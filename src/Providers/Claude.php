<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

use Psr\Http\Client\ClientInterface;
use Units\Ai\AiException;
use Units\Ai\AiResponse;
use Units\Ai\ChatMessage;
use Units\Ai\IAiProvider;
use Units\Ai\TokenUsage;
use Units\Ai\ToolCall;
use Units\Ai\ToolDefinition;
use Units\Ai\Transport;

/**
 * Claude - Anthropic's Messages API.
 *
 * Four things are different from the OpenAI dialect, and they are the whole
 * reason this is not `extends OpenAiCompatible`:
 *
 *  - the system prompt is a **top-level parameter**, not a message with
 *    `role: system` - which is why `ChatMessage::System` has to be lifted out;
 *  - tools are flat (`name`, `description`, `input_schema`) instead of nested
 *    under a `function` key;
 *  - a tool call is a `tool_use` **content block** mixed in with the text,
 *    rather than a separate `tool_calls` array;
 *  - `max_tokens` is required, so this provider always sends one.
 *
 *   new Claude(['apiKey' => 'sk-ant-...', 'model' => 'claude-3-5-sonnet-20241022']);
 */
final class Claude implements IAiProvider
{
    private const NAME = 'claude';

    private const API_VERSION = '2023-06-01';

    private const DEFAULT_MODEL = 'claude-sonnet-5-5';

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    private const DEFAULT_MAX_TOKENS = 1000;

    private const DEFAULT_TEMPERATURE = 0.7;

    private readonly Transport $transport;

    private readonly string $apiKey;

    private readonly string $model;

    private readonly string $baseURL;

    /**
     * @param array{apiKey?: string, model?: string, baseURL?: string} $config
     */
    public function __construct(array $config = [], ?ClientInterface $http = null)
    {
        $apiKey = (string) ($config['apiKey'] ?? '');

        if ($apiKey === '') {
            throw AiException::config(self::NAME, 'apiKey');
        }

        $this->apiKey = $apiKey;
        $this->model = (string) ($config['model'] ?? self::DEFAULT_MODEL);
        $this->baseURL = rtrim((string) ($config['baseURL'] ?? self::DEFAULT_BASE_URL), '/');
        $this->transport = Transport::create(self::NAME, $http);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One prompt, one answer. No conversation, and no system prompt - Anthropic
     * takes the system prompt as a sibling of the message list, so it belongs to
     * the call that has one: chat().
     *
     * @param array<string, mixed> $options
     */
    public function ask(string $prompt, array $options = []): AiResponse
    {
        return $this->messages(
            [['role' => 'user', 'content' => $prompt]],
            $options,
            [],
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        [$wire, $system] = $this->wire(
            ChatMessage::list($messages),
            (string) ($options['systemPrompt'] ?? ''),
        );

        return $this->messages($wire, $options, [], $system);
    }

    /**
     * @param list<ToolDefinition|array<string, mixed>> $tools
     * @param array{messages?: list<ChatMessage|array<string, mixed>>, prompt?: string, systemPrompt?: string, metadata?: array<string, mixed>} $request
     */
    public function tools(array $tools, array $request = []): AiResponse
    {
        $messages = (array) ($request['messages'] ?? []);
        $metadata = ['metadata' => (array) ($request['metadata'] ?? [])];

        if ($messages !== []) {
            [$wire, $system] = $this->wire(
                ChatMessage::list($messages),
                (string) ($request['systemPrompt'] ?? ''),
            );

            return $this->messages($wire, $metadata, ToolDefinition::list($tools), $system);
        }

        return $this->messages(
            [['role' => 'user', 'content' => (string) ($request['prompt'] ?? '')]],
            $metadata,
            ToolDefinition::list($tools),
            (string) ($request['systemPrompt'] ?? ''),
        );
    }

    /**
     * Render a conversation the way Anthropic wants it.
     *
     * Two things are peculiar here. There is no system role - the prompt is a
     * top-level field, so a system message is lifted out rather than rejected.
     * And there is no tool role either: what the model asked for is a `tool_use`
     * block on the assistant turn, and the answer is a `tool_result` block in a
     * *user* turn. Consecutive results are merged into one user turn, because
     * Anthropic expects the replies to one round together and rejects two user
     * turns in a row.
     *
     * @param list<ChatMessage> $messages
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function wire(array $messages, string $system): array
    {
        $wire = [];
        $pending = [];

        $flush = static function () use (&$wire, &$pending): void {
            if ($pending !== []) {
                $wire[] = ['role' => 'user', 'content' => $pending];
                $pending = [];
            }
        };

        foreach ($messages as $message) {
            if ($message->isSystem()) {
                $system = $message->content;

                continue;
            }

            if ($message->isTool()) {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $message->toolCallId,
                    'content'     => $message->content,
                ];

                if ($message->failed) {
                    $block['is_error'] = true;
                }

                $pending[] = $block;

                continue;
            }

            $flush();

            if ($message->toolCalls !== []) {
                $blocks = [];

                if (trim($message->content) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $message->content];
                }

                foreach ($message->toolCalls as $call) {
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => $call->id,
                        'name'  => $call->name,
                        'input' => $call->arguments,
                    ];
                }

                $wire[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            if ($message->isAssistant() && trim($message->content) === '') {
                continue;
            }

            $wire[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        $flush();

        return [$wire, $system];
    }

    public function validateConnection(): bool
    {
        try {
            // /models does not exist here; the cheapest authenticated probe is a
            // real, minimal completion.
            $this->transport->post($this->baseURL . '/messages', $this->headers(), [
                'model'      => $this->model,
                'max_tokens' => 10,
                'messages'   => [['role' => 'user', 'content' => 'test']],
            ]);

            return true;
        } catch (AiException) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @param list<ToolDefinition> $tools
     */
    private function messages(array $messages, array $options, array $tools, string $system = ''): AiResponse
    {
        $body = [
            'model'       => (string) ($options['model'] ?? $this->model),
            'max_tokens'  => (int) ($options['maxTokens'] ?? self::DEFAULT_MAX_TOKENS),
            'temperature' => (float) ($options['temperature'] ?? self::DEFAULT_TEMPERATURE),
            'messages'    => $messages,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        $stop = array_values((array) ($options['stopSequences'] ?? []));

        if ($stop !== []) {
            $body['stop_sequences'] = $stop;
        }

        if ($tools !== []) {
            $body['tools'] = $this->toolPayload($tools);
        }

        $started = microtime(true);

        $data = $this->transport->post($this->baseURL . '/messages', $this->headers(), $body);

        $content = '';
        $calls = [];

        foreach ((array) ($data['content'] ?? []) as $block) {
            $type = (string) ($block['type'] ?? '');

            if ($type === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }

            if ($type === 'tool_use') {
                $calls[] = new ToolCall(
                    (string) ($block['name'] ?? ''),
                    (array) ($block['input'] ?? []),
                    (string) ($block['id'] ?? ''),
                );
            }
        }

        return new AiResponse(
            content: trim($content),
            provider: self::NAME,
            model: isset($data['model']) ? (string) $data['model'] : null,
            toolCalls: $calls,
            usage: $this->usage($data),
            metadata: (array) ($options['metadata'] ?? []),
            duration: round((microtime(true) - $started) * 1000, 2),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function usage(array $data): ?TokenUsage
    {
        $usage = $data['usage'] ?? null;

        if (!is_array($usage) || $usage === []) {
            return null;
        }

        return new TokenUsage(
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * Flat tools, `input_schema` instead of `parameters`.
     *
     * @param list<ToolDefinition> $tools
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private function toolPayload(array $tools): array
    {
        $payload = [];

        foreach ($tools as $tool) {
            $payload[] = [
                'name'         => $tool->name,
                'description'  => $tool->description,
                'input_schema' => $tool->parameters,
            ];
        }

        return $payload;
    }
}
