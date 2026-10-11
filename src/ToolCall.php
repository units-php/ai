<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;
use JsonSerializable;

/**
 * ToolCall - the model asking you to run something.
 *
 * It is a *request*, never an execution. This unit returns tool calls and stops
 * there: deciding whether to run `delete_everything`, with what arguments, and
 * with whose credentials is a decision no library should take on the caller's
 * behalf. The TS version ran them through the capability trinity; that is gone
 * with the trinity.
 *
 * `$arguments` is already decoded. Providers disagree on the wire: OpenAI and
 * friends send a JSON *string* that has to be parsed, Anthropic sends an object,
 * Gemini sends an object and no id at all - so `$id` may be empty and each
 * provider fills in what its protocol actually supplies.
 */
final class ToolCall implements JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly string $id = '',
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('[ai] a tool call needs a name');
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function create(string $name, array $arguments = [], string $id = ''): self
    {
        return new self($name, $arguments, $id);
    }

    public function hasId(): bool
    {
        return $this->id !== '';
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'arguments' => $this->arguments,
        ];
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
