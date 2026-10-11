<?php

declare(strict_types=1);

namespace Units\Ai;

use DateTimeImmutable;
use JsonSerializable;

/**
 * AiResponse - one answer, from any provider.
 *
 * The same four things every time: what was said, which provider and model said
 * it, what it cost, and whether the model wants a tool run. Each provider
 * reshapes its own payload into this, so a caller can swap OpenAI for Gemini
 * without touching the code that reads the answer.
 *
 * A shape, not a Unit: no identity, no version, no behaviour. Plain and
 * readonly, because it is read far more often than it is built.
 *
 * `duration` is milliseconds spent in the provider call. It is a real field and
 * not a `metadata` key because it is the one thing every caller wants and the
 * one thing no response payload contains.
 */
final class AiResponse implements JsonSerializable
{
    public readonly DateTimeImmutable $timestamp;

    /**
     * @param list<ToolCall>          $toolCalls
     * @param array<string, mixed>    $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly ?string $model = null,
        public readonly array $toolCalls = [],
        public readonly ?TokenUsage $usage = null,
        public readonly array $metadata = [],
        public readonly ?float $duration = null,
        ?DateTimeImmutable $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? new DateTimeImmutable();
    }

    /**
     * @param list<ToolCall>       $toolCalls
     * @param array<string, mixed> $metadata
     */
    public static function create(
        string $content,
        string $provider,
        ?string $model = null,
        array $toolCalls = [],
        ?TokenUsage $usage = null,
        array $metadata = [],
        ?float $duration = null,
    ): self {
        return new self($content, $provider, $model, $toolCalls, $usage, $metadata, $duration);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function firstToolCall(): ?ToolCall
    {
        return $this->toolCalls[0] ?? null;
    }

    /**
     * Nothing said and nothing asked for - a provider that answered with air.
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '' && $this->toolCalls === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content'    => $this->content,
            'provider'   => $this->provider,
            'model'      => $this->model,
            'toolCalls'  => array_map(
                static fn (ToolCall $call): array => $call->toArray(),
                $this->toolCalls,
            ),
            'usage'      => $this->usage?->toArray(),
            'metadata'   => $this->metadata,
            'duration'   => $this->duration,
            'timestamp'  => $this->timestamp->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
