<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

/**
 * OpenAi - OpenAI's chat-completions API.
 *
 * The reference implementation of the dialect the other four copy. Two things
 * are peculiar to OpenAI itself and worth knowing:
 *
 *  - the default temperature is 1, not the 0.7 everyone else defaults to;
 *  - `max_tokens` is *not* sent, because the newer models reject it (they take
 *    `max_completion_tokens`, which is a different conversation). A caller who
 *    needs it can pass `maxTokens` and get it.
 *
 *   new OpenAi(['apiKey' => 'sk-...', 'model' => 'gpt-4o-mini']);
 */
final class OpenAi extends OpenAiCompatible
{
    protected const NAME = 'openai';

    protected const DEFAULT_MODEL = 'gpt-6-luna';

    protected const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    protected const DEFAULT_TEMPERATURE = 1.0;

    protected const DEFAULT_MAX_TOKENS = null;

    /**
     * `reasoning_effort`, when the caller asks for it.
     *
     * The reasoning models refuse function tools on /v1/chat/completions unless
     * reasoning is switched off first:
     *
     *   "Function tools with reasoning_effort are not supported for gpt-6-luna
     *    in /v1/chat/completions. To use function tools, use /v1/responses or
     *    set reasoning_effort to 'none'."
     *
     * It is passed through rather than set by default, because turning reasoning
     * off is a change to how the model thinks, and that is the caller's call:
     *
     *   $ai->tools($definitions, ['prompt' => $q, 'reasoningEffort' => 'none']);
     *
     * The other way out is the /v1/responses endpoint, which this provider does
     * not speak.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function extraBody(array $options): array
    {
        $effort = $options['reasoningEffort'] ?? null;

        return is_string($effort) && trim($effort) !== ''
            ? ['reasoning_effort' => trim($effort)]
            : [];
    }
}
