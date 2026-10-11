<?php

declare(strict_types=1);

namespace Units\Ai;

use DateTimeImmutable;
use JsonSerializable;
use Patterns\Event;

/**
 * AiEvent - what the unit tells the world it did.
 *
 * Three things are worth observing about an AI call, and none of them are the
 * answer itself: that it happened (`ai.ask`, `ai.chat`, `ai.tools`), how long it
 * took, and what it cost. Keys are dotted so they can be a native subject on any
 * broker without a translation layer - `ai.ask` is a valid NATS subject, a Redis
 * pattern and a WebSocket route.
 *
 * The full `AiResponse` rides along on the object, but `toArray()` deliberately
 * does not carry the content: an event is a routed message, and a prompt or an
 * answer is exactly the part you may not want on a message bus. A monitor that
 * wants the text has the object in hand.
 *
 * `Patterns\Event` asks for one method, and gets one: `type()`.
 */
final class AiEvent implements Event, JsonSerializable
{
    public const ASK = 'ai.ask';
    public const CHAT = 'ai.chat';
    public const TOOLS = 'ai.tools';

    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        private readonly string $type,
        private readonly string $provider,
        private readonly array $details,
        private readonly ?AiResponse $response,
        private readonly ?DateTimeImmutable $timestamp = null,
    ) {
    }

    public static function ask(string $provider, string $prompt, AiResponse $response): self
    {
        return new self(self::ASK, $provider, ['prompt' => self::preview($prompt)], $response);
    }

    public static function chat(string $provider, int $messages, AiResponse $response): self
    {
        return new self(self::CHAT, $provider, ['messages' => $messages], $response);
    }

    public static function tools(string $provider, int $offered, AiResponse $response): self
    {
        return new self(self::TOOLS, $provider, ['offered' => $offered], $response);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function response(): ?AiResponse
    {
        return $this->response;
    }

    public function duration(): ?float
    {
        return $this->response?->duration;
    }

    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp ?? $this->response?->timestamp ?? new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'       => $this->type,
            'provider'   => $this->provider,
            'model'      => $this->response?->model,
            'details'    => $this->details,
            'duration'   => $this->response?->duration,
            'tokens'     => $this->response?->usage?->toArray(),
            'toolCalls'  => count($this->response?->toolCalls ?? []),
            'at'         => $this->timestamp()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A prompt is the most sensitive string in the call, so only its opening
     * rides on an event - enough to identify the call in a log, not enough to
     * leak a document.
     */
    private static function preview(string $prompt, int $limit = 100): string
    {
        $prompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt);

        return strlen($prompt) > $limit ? substr($prompt, 0, $limit) . '…' : $prompt;
    }
}
