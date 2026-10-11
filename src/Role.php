<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;

/**
 * Role - who is speaking.
 *
 * Four, because a tool call makes four: the model asks (`Assistant`, carrying
 * the calls), and a tool answers (`Tool`). Every provider here names the last
 * two differently on the wire - OpenAI has a real `role: "tool"`, Anthropic has
 * no such role and spells it `tool_result` blocks inside a user turn, Google
 * spells it `functionResponse` parts inside a user turn - but those are
 * renderings of one idea, and this is the idea.
 *
 * An enum rather than a string union, so an unknown role fails at the call that
 * caused it instead of arriving as a 400 from a provider three layers down.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';

    /**
     * Case-insensitive: 'User', 'USER' and 'user' are one thing.
     */
    public static function normalize(self|string $role): self
    {
        if ($role instanceof self) {
            return $role;
        }

        return self::tryFrom(strtolower(trim($role)))
            ?? throw new InvalidArgumentException(sprintf(
                '[ai] unknown role: %s - known: %s',
                $role,
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }

    public function isSystem(): bool
    {
        return $this === self::System;
    }

    public function isAssistant(): bool
    {
        return $this === self::Assistant;
    }

    public function isTool(): bool
    {
        return $this === self::Tool;
    }
}
