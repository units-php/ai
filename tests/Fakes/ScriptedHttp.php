<?php

declare(strict_types=1);

namespace Units\Ai\Tests\Fakes;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ScriptedHttp - a PSR-18 client that answers from a queue and records what it
 * was asked.
 *
 * The point of the suite is to prove what this unit *renders* for each provider,
 * and that is a property of the outgoing request. So the fake keeps the request
 * rather than the conversation: every assertion in the provider tests is about a
 * URL, a header or a body this unit built, and no test needs a network or a key.
 *
 * Unscripted requests get an error payload rather than an exception, so a test
 * that under-scripts fails on the assertion it meant to make instead of on a
 * confusing transport error.
 */
final class ScriptedHttp implements ClientInterface
{
    /**
     * @var list<array{method: string, url: string, headers: array<string, list<string>>, body: array<string, mixed>}>
     */
    private array $sent = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $script = [];

    private int $cursor = 0;

    public function push(array ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->script[] = $answer;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();

        $this->sent[] = [
            'method'  => $request->getMethod(),
            'url'     => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
            'body'    => $raw === '' ? [] : (array) json_decode($raw, true),
        ];

        $answer = $this->script[$this->cursor] ?? ['error' => ['message' => 'unscripted request #' . $this->cursor]];
        $this->cursor++;

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($answer));
    }

    // -----------------------------------------------------------------------
    // What it was asked
    // -----------------------------------------------------------------------

    public function count(): int
    {
        return count($this->sent);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        return $this->sent[$index]['body'];
    }

    public function url(int $index = 0): string
    {
        return $this->sent[$index]['url'];
    }

    public function method(int $index = 0): string
    {
        return $this->sent[$index]['method'];
    }

    public function header(int $index, string $name): ?string
    {
        foreach ($this->sent[$index]['headers'] as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->sent, 'url');
    }
}
