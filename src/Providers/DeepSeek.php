<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

/**
 * DeepSeek - OpenAI's dialect, at DeepSeek's prices.
 *
 * Its own base URL and model names, otherwise identical to OpenAI.
 *
 *   new DeepSeek(['apiKey' => 'sk-...', 'model' => 'deepseek-chat']);
 */
final class DeepSeek extends OpenAiCompatible
{
    protected const NAME = 'deepseek';

    protected const DEFAULT_MODEL = 'deepseek-flash';

    protected const DEFAULT_BASE_URL = 'https://api.deepseek.com/v1';

    protected const DEFAULT_TEMPERATURE = 0.7;
}
