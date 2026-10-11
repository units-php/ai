<?php

declare(strict_types=1);

namespace Units\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Transport - the one place a provider touches the network.
 *
 * Every provider is HTTP + JSON, so the sending, the status check, the JSON
 * decode and the error attribution live here once instead of seven times. A
 * provider only says *what* to send and *how* to read the answer.
 *
 * PSR-18 for sending and PSR-17 for building, so the client is swappable: Guzzle
 * by default (already a dependency of the application), anything else by
 * injection. That is what makes a provider testable without a network - hand it
 * a fake `ClientInterface` and it behaves identically.
 *
 * The provider's name is carried so failures are attributed at the point they
 * happen: an error should say "openai", not "a provider".
 */
final class Transport
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $provider = 'ai',
    ) {
    }

    /**
     * The default: Guzzle, with HTTP errors left to us rather than thrown.
     *
     * `http_errors => false` matters. A 429 carries a body that says what to do
     * about it, and Guzzle's default throws that body away as an exception.
     */
    public static function create(string $provider, ?ClientInterface $http = null): self
    {
        $factories = new HttpFactory();

        return new self(
            $http ?? new Client(['http_errors' => false, 'timeout' => 60.0]),
            $factories,
            $factories,
            $provider,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array<string, mixed>
     */
    public function post(string $url, array $headers, array $body): array
    {
        return $this->send('POST', $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, $headers, null);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $headers, ?array $body): array
    {
        $request = $this->requests->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            try {
                $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new AiException(
                    sprintf('[%s] the request body could not be encoded: %s', $this->provider, $e->getMessage()),
                    $this->provider,
                    'ENCODING_ERROR',
                );
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($encoded));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AiConnectionException(
                sprintf('[%s] %s', $this->provider, $e->getMessage()),
                $this->provider,
                $url,
            );
        }

        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        if ($status >= 400) {
            throw AiException::http($this->provider, $status, $response->getReasonPhrase(), $payload);
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw AiException::unreadable($this->provider, $payload);
        }

        return $decoded;
    }
}
