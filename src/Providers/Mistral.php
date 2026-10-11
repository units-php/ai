<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

/**
 * Mistral - OpenAI's dialect with more knobs and different defaults.
 *
 * Two differences worth the extra code:
 *
 *  - the default `max_tokens` is 4000, not 1000, and the default temperature is
 *    0.7;
 *  - it accepts `parallel_tool_calls`, and defaults it on, so one turn may come
 *    back asking for several tools at once. A caller that assumed one call per
 *    response will be surprised by Mistral first.
 *
 *   new Mistral(['apiKey' => '...', 'model' => 'mistral-large-latest']);
 */
final class Mistral extends OpenAiCompatible
{
    protected const NAME = 'mistral';

    protected const DEFAULT_MODEL = 'mistral-small-latest';

    protected const DEFAULT_BASE_URL = 'https://api.mistral.ai/v1';

    protected const DEFAULT_TEMPERATURE = 0.7;

    protected const DEFAULT_MAX_TOKENS = 4000;

    /**
     * @return array<string, mixed>
     */
    protected function extraToolBody(): array
    {
        return ['parallel_tool_calls' => true];
    }
}
