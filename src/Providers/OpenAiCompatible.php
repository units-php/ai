<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

use Psr\Http\Client\ClientInterface;
use Units\Ai\AiException;
use Units\Ai\AiResponse;
use Units\Ai\AiToolException;
use Units\Ai\ChatMessage;
use Units\Ai\IAiProvider;
use Units\Ai\TokenUsage;
use Units\Ai\ToolCall;
use Units\Ai\ToolDefinition;
use Units\Ai\Transport;

/**
 * OpenAiCompatible - one implementation of the OpenAI chat-completions dialect.
 *
 * Five of the seven supported providers speak exactly this: OpenAI, DeepSeek,
 * Grok, OpenRouter and Mistral. They differ in four constants and, for
 * OpenRouter, two headers. Writing that five times would be five places to fix
 * the next time the dialect moves, so it is written once and parameterised.
 *
 * A subclass is expected to be nothing but constants. When one needs more than
 * that, it should stop extending this and implement IAiProvider itself - that is
 * what Claude and Gemini do, because their wire format is genuinely different.
 */
abstract class OpenAiCompatible implements IAiProvider
{
    /** Stable provider name, as it travels in errors, events and responses. */
    protected const NAME = 'openai-compatible';

    protected const DEFAULT_MODEL = '';

    protected const DEFAULT_BASE_URL = '';

    /**
     * `null` means never send the parameter at all.
     *
     * Not the same as sending a plausible number: the reasoning models reject
     * `temperature` outright unless it is their own default - "Unsupported value:
     * 'temperature' does not support 0.2 with this model" - so "no opinion" has
     * to be expressible. A provider that fronts several vendors' models, where
     * the acceptable range differs per model, should default to null.
     */
    protected const DEFAULT_TEMPERATURE = 0.7;

    /**
     * `null` means never send the parameter at all.
     *
     * This is not the same as sending a large number: OpenAI's newer models
     * reject `max_tokens` outright, so "no opinion" has to be expressible.
     */
    protected const DEFAULT_MAX_TOKENS = 1000;

    /** Protected so a subclass can probe its own endpoint - see SiCore. */
    protected readonly Transport $transport;

    private readonly string $apiKey;

    protected readonly string $model;

    protected readonly string $baseURL;

    private readonly ?float $temperature;

    private readonly ?int $maxTokens;

    /**
     * @param array{apiKey?: string, model?: string, baseURL?: string, temperature?: float|null, maxTokens?: int|null} $config
     */
    public function __construct(array $config = [], ?ClientInterface $http = null)
    {
        $apiKey = (string) ($config['apiKey'] ?? '');

        if ($apiKey === '') {
            throw AiException::config(static::NAME, 'apiKey');
        }

        $this->apiKey = $apiKey;
        $this->model = (string) ($config['model'] ?? static::DEFAULT_MODEL);
        $this->baseURL = rtrim((string) ($config['baseURL'] ?? static::DEFAULT_BASE_URL), '/');

        $temperature = array_key_exists('temperature', $config) ? $config['temperature'] : static::DEFAULT_TEMPERATURE;
        $this->temperature = $temperature === null ? null : (float) $temperature;

        $this->maxTokens = array_key_exists('maxTokens', $config)
            ? ($config['maxTokens'] === null ? null : (int) $config['maxTokens'])
            : static::DEFAULT_MAX_TOKENS;

        $this->transport = Transport::create(static::NAME, $http);
    }

    public function name(): string
    {
        return static::NAME;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One prompt, one answer. No conversation, and no system prompt - see chat().
     *
     * @param array<string, mixed> $options
     */
    public function ask(string $prompt, array $options = []): AiResponse
    {
        return $this->completion(
            [['role' => 'user', 'content' => $prompt]],
            $options,
            tools: [],
        );
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $wire = [];

        $systemPrompt = (string) ($options['systemPrompt'] ?? '');

        if ($systemPrompt !== '') {
            $wire[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($this->wireMessages(ChatMessage::list($messages)) as $message) {
            $wire[] = $message;
        }

        return $this->completion($wire, $options, tools: []);
    }

    /**
     * @param list<ToolDefinition|array<string, mixed>> $tools
     * @param array{messages?: list<ChatMessage|array<string, mixed>>, prompt?: string, systemPrompt?: string, metadata?: array<string, mixed>, model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, reasoningEffort?: string} $request
     */
    public function tools(array $tools, array $request = []): AiResponse
    {
        // A conversation, when the caller has one - that is what a second and
        // later round of a tool loop looks like. Otherwise the prompt shorthand.
        $wire = $this->wireMessages(ChatMessage::list((array) ($request['messages'] ?? [])));

        if ($wire === []) {
            if (($request['systemPrompt'] ?? null) !== null && $request['systemPrompt'] !== '') {
                $wire[] = ['role' => 'system', 'content' => (string) $request['systemPrompt']];
            }

            if (($request['prompt'] ?? null) !== null && $request['prompt'] !== '') {
                $wire[] = ['role' => 'user', 'content' => (string) $request['prompt']];
            }
        }

        // A tool request carries its own tuning. Some models refuse tools
        // outright unless told to stop reasoning first, so the caller has to be
        // able to say so - see OpenAi::extraBody().
        $options = ['metadata' => (array) ($request['metadata'] ?? [])];

        foreach (['model', 'temperature', 'maxTokens', 'stopSequences', 'reasoningEffort'] as $key) {
            if (isset($request[$key])) {
                $options[$key] = $request[$key];
            }
        }

        return $this->completion($wire, $options, tools: ToolDefinition::list($tools));
    }

    /**
     * Render a conversation into this dialect's messages.
     *
     * @param list<ChatMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    protected function wireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            $rendered = $this->wireMessage($message);

            if ($rendered !== null) {
                $wire[] = $rendered;
            }
        }

        return $wire;
    }

    /**
     * One message, or null when this dialect has no place for it.
     *
     * @return array<string, mixed>|null
     */
    protected function wireMessage(ChatMessage $message): ?array
    {
        // The model asking for tools. This dialect has a role for it and the
        // calls ride along on the assistant turn, keyed by an id it invents.
        if ($message->toolCalls !== []) {
            return [
                'role'       => 'assistant',
                'content'    => $message->content === '' ? null : $message->content,
                'tool_calls' => array_map(
                    static fn (ToolCall $call): array => [
                        'id'       => $call->id,
                        'type'     => 'function',
                        'function' => [
                            'name'      => $call->name,
                            'arguments' => (string) json_encode($call->arguments, JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                    $message->toolCalls,
                ),
            ];
        }

        // A tool answering. Again a real role, and the id is what ties it back.
        if ($message->isTool()) {
            return [
                'role'         => 'tool',
                'tool_call_id' => (string) $message->toolCallId,
                'content'      => $message->content,
            ];
        }

        // An empty assistant turn is a valid conversation and an invalid
        // request: several of these providers answer it with a 400.
        if ($message->isAssistant() && trim($message->content) === '') {
            return null;
        }

        return ['role' => $message->role->value, 'content' => $message->content];
    }

    public function validateConnection(): bool
    {
        try {
            $this->transport->get($this->baseURL . $this->connectionPath(), $this->headers());

            return true;
        } catch (AiException) {
            return false;
        }
    }

    /**
     * The path an adapter is expected to expose a cheap, authenticated GET on.
     */
    protected function connectionPath(): string
    {
        return '/models';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ], $this->extraHeaders());
    }

    /**
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    /**
     * Provider-specific keys added to every request body.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function extraBody(array $options): array
    {
        return [];
    }

    /**
     * Provider-specific keys added to a tool-calling body only.
     *
     * @return array<string, mixed>
     */
    protected function extraToolBody(): array
    {
        return [];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @param list<ToolDefinition> $tools
     */
    private function completion(array $messages, array $options, array $tools): AiResponse
    {
        $body = [
            'model'    => (string) ($options['model'] ?? $this->model),
            'messages' => $messages,
        ];

        // Sent only when someone asked for one - the caller, or the provider's
        // own default when that default is not null.
        $temperature = array_key_exists('temperature', $options) ? $options['temperature'] : $this->temperature;

        if ($temperature !== null) {
            $body['temperature'] = (float) $temperature;
        }

        $maxTokens = array_key_exists('maxTokens', $options) ? $options['maxTokens'] : $this->maxTokens;

        if ($maxTokens !== null) {
            $body['max_tokens'] = (int) $maxTokens;
        }

        $stop = array_values((array) ($options['stopSequences'] ?? ($options['stop'] ?? [])));

        if ($stop !== []) {
            $body['stop'] = $stop;
        }

        if ($tools !== []) {
            $body['tools'] = $this->toolPayload($tools);
            $body['tool_choice'] = 'auto';
            $body = array_merge($body, $this->extraToolBody());
        }

        $started = microtime(true);

        $data = $this->transport->post(
            $this->baseURL . '/chat/completions',
            $this->headers(),
            array_merge($body, $this->extraBody($options)),
        );

        return new AiResponse(
            content: $this->content($data),
            provider: static::NAME,
            model: isset($data['model']) ? (string) $data['model'] : null,
            toolCalls: $this->toolCalls($data),
            usage: $this->usage($data),
            metadata: (array) ($options['metadata'] ?? []),
            duration: round((microtime(true) - $started) * 1000, 2),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function content(array $data): string
    {
        $content = $data['choices'][0]['message']['content'] ?? '';

        // Reasoning models may answer with a content array of parts instead of a
        // string; anything that is not text is not this unit's business.
        if (is_array($content)) {
            $text = '';

            foreach ($content as $part) {
                $text .= is_array($part) ? (string) ($part['text'] ?? '') : (string) $part;
            }

            return $text;
        }

        return (string) $content;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ToolCall>
     */
    private function toolCalls(array $data): array
    {
        $calls = [];

        foreach ((array) ($data['choices'][0]['message']['tool_calls'] ?? []) as $call) {
            $name = (string) ($call['function']['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $raw = $call['function']['arguments'] ?? [];

            $calls[] = new ToolCall(
                $name,
                match (true) {
                    is_array($raw) => $raw,
                    $raw === '' || $raw === null => [],
                    default => $this->decodeArguments($name, (string) $raw),
                },
                (string) ($call['id'] ?? ''),
            );
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(string $tool, string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Decoding a broken payload to [] would run the tool with no
            // arguments and look like success.
            throw AiToolException::unreadableArguments(static::NAME, $tool, $raw);
        }

        return $decoded;
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
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
        );
    }

    /**
     * @param list<ToolDefinition> $tools
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function toolPayload(array $tools): array
    {
        $payload = [];

        foreach ($tools as $tool) {
            $payload[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->name,
                    'description' => $tool->description,
                    'parameters'  => $tool->parameters,
                ],
            ];
        }

        return $payload;
    }
}
