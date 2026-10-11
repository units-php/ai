<?php

declare(strict_types=1);

namespace Units\Ai;

/**
 * AiToolException - a tool call arrived in a shape that cannot be used.
 *
 * The one tool failure this unit can have, now that it does not run tools: a
 * provider asked for `get_weather` and sent its arguments as a blob that is not
 * JSON. Silently decoding that to `[]` would call the tool with no arguments and
 * report success, so it is raised instead.
 */
final class AiToolException extends AiException
{
    public function __construct(
        string $message,
        ?string $provider = null,
        public readonly ?string $tool = null,
    ) {
        parent::__construct(
            $message,
            $provider,
            'TOOL_ERROR',
            $tool === null ? [] : ['tool' => $tool],
        );
    }

    /**
     * The model asked for a tool with arguments that are not usable.
     */
    public static function unreadableArguments(string $provider, string $tool, string $raw): self
    {
        return new self(
            sprintf('[%s] %s() was called with unreadable arguments: %s', $provider, $tool, $raw),
            $provider,
            $tool,
        );
    }
}
