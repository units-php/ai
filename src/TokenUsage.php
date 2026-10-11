<?php

declare(strict_types=1);

namespace Units\Ai;

use JsonSerializable;

/**
 * TokenUsage - what a call cost, in tokens.
 *
 * The providers count the same three numbers under different names:
 * `prompt_tokens`/`completion_tokens` on the OpenAI family, `input_tokens`/
 * `output_tokens` at Anthropic, `promptTokenCount`/`candidatesTokenCount` at
 * Google. Normalizing here is what lets a cost report read one field.
 *
 * `totalTokens` is derived when a provider does not send one, because "prompt
 * plus completion" is the only definition any of them uses.
 */
final class TokenUsage implements JsonSerializable
{
    public readonly int $totalTokens;

    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        ?int $totalTokens = null,
    ) {
        $this->totalTokens = $totalTokens ?? $promptTokens + $completionTokens;
    }

    public static function create(int $promptTokens, int $completionTokens, ?int $totalTokens = null): self
    {
        return new self($promptTokens, $completionTokens, $totalTokens);
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int}
     */
    public function toArray(): array
    {
        return [
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens'      => $this->totalTokens,
        ];
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
