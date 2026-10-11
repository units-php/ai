<?php

declare(strict_types=1);

namespace Units\Ai;

/**
 * AiConnectionException - the request never got an answer.
 *
 * Split from AiException because the response to it is different: a 401 means
 * fix the key, a 429 means wait, but this one means the network, the DNS or the
 * timeout - retry, or check outbound connectivity. A caller that wants to retry
 * something should catch exactly this.
 */
final class AiConnectionException extends AiException
{
    public function __construct(
        string $message,
        ?string $provider = null,
        public readonly ?string $url = null,
    ) {
        parent::__construct(
            $message,
            $provider,
            'CONNECTION_ERROR',
            $url === null ? [] : ['url' => $url],
        );
    }
}
