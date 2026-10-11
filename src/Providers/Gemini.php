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
 * Gemini - Google's generateContent API.
 *
 * The furthest from the OpenAI dialect of anything here:
 *
 *  - the model is in the **path**, not the body: `models/{model}:generateContent`;
 *  - the key is a **query parameter**, not a header - which means it can end up
 *    in a request log, so treat Gemini keys as the most likely to leak;
 *  - a conversation is `contents`, and the assistant is called `model`;
 *  - a system prompt is `systemInstruction`, a sibling of `contents`;
 *  - tools are `functionDeclarations`, and a tool call arrives as a
 *    `functionCall` part with **no id at all**, so one is synthesized here.
 *
 *   new Gemini(['apiKey' => 'AIza...', 'model' => 'gemini-1.5-flash']);
 */
final class Gemini implements IAiProvider
{
    private const NAME = 'gemini';

    private const DEFAULT_MODEL = 'gemini-3.8-flash';

    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';

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
     * @param array<string, mixed> $options
     */
    public function ask(string $prompt, array $options = []): AiResponse
    {
        return $this->generate(
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $options,
            [],
            (string) ($options['systemPrompt'] ?? ''),
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        [$contents, $system] = $this->wire(
            ChatMessage::list($messages),
            (string) ($options['systemPrompt'] ?? ''),
        );

        return $this->generate($contents, $options, [], $system);
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
            [$contents, $system] = $this->wire(
                ChatMessage::list($messages),
                (string) ($request['systemPrompt'] ?? ''),
            );

            return $this->generate($contents, $metadata, ToolDefinition::list($tools), $system);
        }

        $prompt = (string) ($request['prompt'] ?? '');

        return $this->generate(
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            $metadata,
            ToolDefinition::list($tools),
            (string) ($request['systemPrompt'] ?? ''),
        );
    }

    /**
     * Render a conversation the way Google wants it.
     *
     * `contents`, a system prompt that is a sibling of them (`systemInstruction`),
     * and - unlike the other two - no ids anywhere: a call is a `functionCall`
     * part, and the answer is a `functionResponse` part that identifies the
     * function by *name*. Answers are merged into one user turn, since the
     * replies to a round belong together.
     *
     * @param list<ChatMessage> $messages
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function wire(array $messages, string $system): array
    {
        $contents = [];
        $pending = [];

        $flush = static function () use (&$contents, &$pending): void {
            if ($pending !== []) {
                $contents[] = ['role' => 'user', 'parts' => $pending];
                $pending = [];
            }
        };

        foreach ($messages as $message) {
            if ($message->isSystem()) {
                $system = $message->content;

                continue;
            }

            if ($message->isTool()) {
                // The result travels as text; Google wants an object, so it is
                // wrapped rather than sent bare.
                $pending[] = [
                    'functionResponse' => [
                        'name'     => (string) $message->toolName,
                        'response' => ['content' => $message->content],
                    ],
                ];

                continue;
            }

            $flush();

            if ($message->toolCalls !== []) {
                $parts = [];

                if (trim($message->content) !== '') {
                    $parts[] = ['text' => $message->content];
                }

                foreach ($message->toolCalls as $call) {
                    $parts[] = ['functionCall' => ['name' => $call->name, 'args' => $call->arguments]];
                }

                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            if ($message->isAssistant() && trim($message->content) === '') {
                continue;
            }

            $contents[] = [
                'role'  => $message->isAssistant() ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        $flush();

        return [$contents, $system];
    }

    public function validateConnection(): bool
    {
        try {
            $this->transport->get($this->baseURL . '/models?key=' . rawurlencode($this->apiKey));

            return true;
        } catch (AiException) {
            return false;
        }
    }

    /**
     * @param list<array{role: string, parts: list<array{text: string}>}> $contents
     * @param array<string, mixed> $options
     * @param list<ToolDefinition> $tools
     */
    private function generate(array $contents, array $options, array $tools, string $system = ''): AiResponse
    {
        $generationConfig = [
            'temperature'     => (float) ($options['temperature'] ?? self::DEFAULT_TEMPERATURE),
            'maxOutputTokens' => (int) ($options['maxTokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];

        $stop = array_values((array) ($options['stopSequences'] ?? []));

        if ($stop !== []) {
            $generationConfig['stopSequences'] = $stop;
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => $generationConfig,
        ];

        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($tools !== []) {
            $body['tools'] = $this->toolPayload($tools);
        }

        $model = (string) ($options['model'] ?? $this->model);
        $url = sprintf(
            '%s/models/%s:generateContent?key=%s',
            $this->baseURL,
            $model,
            rawurlencode($this->apiKey),
        );

        $started = microtime(true);

        $data = $this->transport->post($url, ['Accept' => 'application/json'], $body);

        $parts = (array) ($data['candidates'][0]['content']['parts'] ?? []);

        $content = '';
        $calls = [];

        foreach ($parts as $index => $part) {
            if (isset($part['text'])) {
                $content .= (string) $part['text'];
            }

            // Gemini supplies no tool call id, so the position is the only name
            // available for this call within this response.
            if (isset($part['functionCall']['name'])) {
                $calls[] = new ToolCall(
                    (string) $part['functionCall']['name'],
                    (array) ($part['functionCall']['args'] ?? []),
                    'call_' . $index,
                );
            }
        }

        return new AiResponse(
            content: trim($content),
            provider: self::NAME,
            model: $model,
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
        $usage = $data['usageMetadata'] ?? null;

        if (!is_array($usage) || $usage === []) {
            return null;
        }

        return new TokenUsage(
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0),
            isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null,
        );
    }

    /**
     * @param list<ToolDefinition> $tools
     *
     * @return list<array{functionDeclarations: list<array{name: string, description: string, parameters: array<string, mixed>}>}>
     */
    private function toolPayload(array $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            $declarations[] = [
                'name'        => $tool->name,
                'description' => $tool->description,
                'parameters'  => $tool->parameters,
            ];
        }

        return [['functionDeclarations' => $declarations]];
    }
}
