<?php

declare(strict_types=1);

namespace Units\Ai\Providers;

/**
 * Grok - xAI's models, on OpenAI's dialect.
 *
 *   new Grok(['apiKey' => 'xai-...', 'model' => 'grok-3-mini']);
 */
final class Grok extends OpenAiCompatible
{
    protected const NAME = 'grok';

    protected const DEFAULT_MODEL = 'grok-4.7';

    protected const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    protected const DEFAULT_TEMPERATURE = 0.7;
}
