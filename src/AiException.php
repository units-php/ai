<?php

declare(strict_types=1);

namespace Units\Ai;

use RuntimeException;

/**
 * AiException - anything that went wrong talking to a provider.
 *
 * Carries the two things a caller needs to react: *which* provider, and a
 * machine-readable `errorCode` to branch on without matching message text.
 *
 * `errorCode` is a separate field and not `getCode()`, because PHP's
 * `getCode()` is an `int` on `Throwable` and a provider's error identifier is
 * never one. Shadowing it with a string would break the interface every catch
 * block and logger in the app already relies on.
 */
class AiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?string $errorCode = null,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The provider answered, and said no.
     */
    public static function http(
        string $provider,
        int $status,
        string $reason,
        string $body = '',
    ): self {
        $detail = trim($body) === '' ? '' : ' - ' . self::shorten($body);

        return new self(
            sprintf('[%s] HTTP %d %s%s', $provider, $status, $reason, $detail),
            $provider,
            'HTTP_ERROR',
            ['status' => $status, 'reason' => $reason],
        );
    }

    /**
     * A 200 with something in it that cannot be read as JSON.
     */
    public static function unreadable(string $provider, string $body = ''): self
    {
        return new self(
            sprintf('[%s] the response was not readable JSON%s', $provider, $body === '' ? '' : ': ' . self::shorten($body)),
            $provider,
            'UNREADABLE_RESPONSE',
        );
    }

    /**
     * A provider missing what it cannot work without - a key, a model.
     */
    public static function config(string $provider, string $key): self
    {
        return new self(
            sprintf('[%s] %s is required', $provider, $key),
            $provider,
            'CONFIG_ERROR',
            ['missing' => $key],
        );
    }

    private static function shorten(string $body, int $limit = 300): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return strlen($body) > $limit ? substr($body, 0, $limit) . '…' : $body;
    }
}
