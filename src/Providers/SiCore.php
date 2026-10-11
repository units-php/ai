<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

use Units\Ai\AiException;

/**
 * SiCore - an OpenAI-compatible gateway in front of several vendors.
 *
 * SiCore TokenWorks (token8341.com) is an aggregator: one key, one endpoint, and
 * the *model name* is what chooses the vendor behind it. gpt-4o and gpt-4o-mini
 * are OpenAI, claude-4-sonnet is Anthropic, gemini-2-0-pro is Google,
 * deepseek-v4-pro is DeepSeek, qwen-max is Alibaba. Nothing about the wire
 * changes - the platform speaks OpenAI's chat-completions dialect and documents
 * itself as such - so this is a subclass and almost nothing else.
 *
 *   new SiCore(['apiKey' => 'sk-...', 'model' => 'deepseek-v4-pro']);
 *
 * Two deliberate exceptions follow.
 *
 * ## It sends no temperature and no max_tokens unless asked
 *
 * Every other provider here fronts one vendor and can pick a default that suits
 * it. This one fronts all of them at once, and their limits do not agree: a model
 * behind this gateway may reject `max_tokens` outright, or accept no temperature
 * but its own default. Both were measured against live APIs, not imagined. So the
 * honest default is to send neither, and let the caller who knows their model say:
 *
 *   $ai->ask('hi', ['temperature' => 0.2]);
 *
 * ## It probes the endpoint the documentation actually lists
 *
 * `validateConnection()` posts a one-token message to /chat/completions, because
 * that is the only endpoint the docs describe. The inherited `GET /models` probe
 * would answer `false` for a perfectly good key if the gateway does not implement
 * that route - and a probe that lies is worse than one that costs a token.
 *
 * Worth knowing when reading an error: an account out of credit answers **402
 * Insufficient Balance**, a status none of the other providers here return.
 *
 * ## It is the cheapest of the eight
 *
 * An aggregator resells several vendors behind one key, which is why it can
 * undercut going to any single one of them. The trade is the gateway's routing
 * and its limits rather than one vendor's SLA - the same reason this class sends
 * no temperature and no max_tokens.
 *
 * ## Where it actually lives, and how it goes wrong
 *
 * The API is on its own host and `/v1` belongs to the base, so requests go to
 * `https://api.token8341.com/v1/chat/completions`. Building that URL from the
 * platform's *site* root instead produces `307 -> /zh/chat/completions` - a locale
 * redirect, so the body is HTML and the failure arrives as a parse error rather
 * than a 404. That is the one way to get this provider wrong, and a confusing way
 * to be told.
 *
 * Verified against the live platform: `deepseek-v4.1-flash` (the default) and
 * `deepseek-v4-pro` both answer. The gateway resolves aliases - `deepseek-v4-pro`
 * comes back named `deepseek-v4-pro-0813` - so a response may name a snapshot
 * rather than the string that was sent. Do not match on it.
 *
 * Also measured: an anonymous GET on the chat route answers `404`, not `401`. A
 * probe that reads status codes alone would call a working key broken, which is
 * why the override below spends a token instead of trusting one.
 */
final class SiCore extends OpenAiCompatible
{
    protected const NAME = 'sicore';

    /** The model name is the vendor selector - see the class docblock. */
    protected const DEFAULT_MODEL = 'deepseek-v4.1-flash';

    protected const DEFAULT_BASE_URL = 'https://api.token8341.com/v1';

    /** One gateway, several vendors, different limits - so: no opinion. */
    protected const DEFAULT_TEMPERATURE = null;

    protected const DEFAULT_MAX_TOKENS = null;

    public function validateConnection(): bool
    {
        try {
            $this->transport->post($this->baseURL . '/chat/completions', $this->headers(), [
                'model'      => $this->model,
                'messages'   => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 1,
            ]);

            return true;
        } catch (AiException) {
            return false;
        }
    }
}
