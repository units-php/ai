<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

/**
 * OpenRouter - one key, two hundred models, OpenAI's dialect.
 *
 * The only provider here that asks to be told who is calling: `HTTP-Referer`
 * and `X-Title` are how it attributes traffic on its dashboards. They are
 * advisory, and they are the reason this subclass has a method and not only
 * constants.
 *
 * The model name is a namespaced path - 'openai/gpt-oss-20b', 'anthropic/...' -
 * because one endpoint fronts many vendors.
 *
 *   new OpenRouter(['apiKey' => 'sk-or-...', 'model' => 'openai/gpt-oss-20b']);
 */
final class OpenRouter extends OpenAiCompatible
{
    protected const NAME = 'openrouter';

    protected const DEFAULT_MODEL = 'openai/gpt-oss-20b';

    protected const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

    protected const DEFAULT_TEMPERATURE = 0.7;

    /**
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [
            'HTTP-Referer' => 'https://ecom.com',
            'X-Title'      => 'Ecom AI Unit',
        ];
    }
}
