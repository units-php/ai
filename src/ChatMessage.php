<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;
use JsonSerializable;

/**
 * ChatMessage - one turn of a conversation.
 *
 * A role and what was said, plus the two things a tool call needs and nothing
 * else: the calls the model made, and the answer coming back for one of them.
 *
 * The providers disagree about everything *except* this. Anthropic takes the
 * system prompt as a top-level parameter, Gemini as a `systemInstruction`,
 * OpenAI as the first message; OpenAI has a `role: "tool"`, Anthropic wraps a
 * `tool_result` block in a user turn, Google puts a `functionResponse` part
 * there. Keeping the intent here and letting each provider render its own
 * dialect is what makes one conversation portable across all of them.
 *
 * Deliberately NOT carried: provider metadata, response envelopes, prompt
 * caching hints. Those are a vendor's business, and a message that carried them
 * would only be portable until the first vendor changed its mind.
 */
final class ChatMessage implements JsonSerializable
{
    /**
     * @param list<ToolCall> $toolCalls
     */
    private function __construct(
        public readonly Role $role,
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        public readonly ?string $toolName = null,
        public readonly bool $failed = false,
    ) {
    }

    public static function create(Role|string $role, string $content): self
    {
        return new self(Role::normalize($role), $content);
    }

    public static function fromArray(array $message): self
    {
        return new self(
            Role::normalize((string) ($message['role'] ?? '')),
            (string) ($message['content'] ?? ''),
            toolCallId: isset($message['toolCallId']) ? (string) $message['toolCallId'] : null,
            toolName: isset($message['toolName']) ? (string) $message['toolName'] : null,
            failed: (bool) ($message['failed'] ?? false),
        );
    }

    public static function system(string $content): self
    {
        return new self(Role::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(Role::Assistant, $content);
    }

    /**
     * The model asking for one or more tools.
     *
     * The content is what it said alongside the request, and may be empty - some
     * providers answer a tool call with no prose at all, and several reject an
     * empty assistant turn outright, so a provider rendering this decides
     * whether to include it.
     *
     * @param list<ToolCall> $toolCalls
     */
    public static function asked(array $toolCalls, string $content = ''): self
    {
        if ($toolCalls === []) {
            throw new InvalidArgumentException('[ai] an assistant turn with no tool calls is just a message');
        }

        return new self(Role::Assistant, $content, $toolCalls);
    }

    /**
     * A tool answering the call that was made.
     *
     * Takes the whole call, not an id, because providers disagree about what
     * identifies it: OpenAI wants `tool_call_id`, Anthropic `tool_use_id`, and
     * Google - which sends no id at all - wants the function *name* back. The
     * call carries all three, so the message does too.
     */
    public static function answered(ToolCall $call, mixed $result, bool $failed = false): self
    {
        return new self(
            Role::Tool,
            self::text($result),
            toolCallId: $call->id === '' ? null : $call->id,
            toolName: $call->name,
            failed: $failed,
        );
    }

    public function isSystem(): bool
    {
        return $this->role->isSystem();
    }

    public function isAssistant(): bool
    {
        return $this->role->isAssistant();
    }

    public function isTool(): bool
    {
        return $this->role->isTool();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'role'    => $this->role->value,
            'content' => $this->content,
        ];

        if ($this->toolCalls !== []) {
            $data['toolCalls'] = array_map(
                static fn (ToolCall $call): array => $call->toArray(),
                $this->toolCalls,
            );
        }

        if ($this->toolCallId !== null) {
            $data['toolCallId'] = $this->toolCallId;
        }

        if ($this->toolName !== null) {
            $data['toolName'] = $this->toolName;
        }

        if ($this->failed) {
            $data['failed'] = true;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Normalize a conversation to ChatMessage objects, accepting arrays too.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage>
     */
    public static function list(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $index => $message) {
            $normalized[] = match (true) {
                $message instanceof self => $message,
                is_array($message) => self::fromArray($message),
                default => throw new InvalidArgumentException(
                    sprintf('[ai] message %s is not a ChatMessage or an array', (string) $index)
                ),
            };
        }

        return $normalized;
    }

    /**
     * A tool result is usually an array; the wire wants text.
     */
    private static function text(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        if ($result === null) {
            return 'null';
        }

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '(unencodable result)' : $encoded;
    }
}
