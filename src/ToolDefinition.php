<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;
use JsonSerializable;

/**
 * ToolDefinition - something the model may ask you to run.
 *
 * Flat - `name`, `description`, `parameters` - and not the nested
 * `{type: 'function', function: {...}}` envelope the OpenAI family wants on the
 * wire. That envelope is one provider's format: OpenAI and friends nest it,
 * Anthropic flattens it into `{name, description, input_schema}`, Gemini wraps
 * it in `functionDeclarations`. Keeping the envelope out of the domain type is
 * what makes one definition usable with all of them.
 *
 * `parameters` is a JSON Schema object, passed through untouched. This class
 * does not validate it: a provider that dislikes a schema should say so, not
 * this type guess.
 */
final class ToolDefinition implements JsonSerializable
{
    /** @var array{type: string, properties: array<string, mixed>, required: list<string>} */
    public const SCHEMA = ['type' => 'object', 'properties' => [], 'required' => []];

    /**
     * What every provider accepts - the intersection, not the union.
     *
     * The dialects do not agree on this. Google allows dots in a function name;
     * OpenAI and Anthropic do not, and reject the whole request for it - "Invalid
     * 'tools[0].function.name': string does not match pattern
     * '^[a-zA-Z0-9_-]+$'". Google also wants the name to begin with a letter or an
     * underscore. One definition is supposed to work with any of them, so it has
     * to satisfy all of them.
     *
     * Which means `products.topSelling`, the name that reads best in PHP, is a 400
     * from OpenAI. Better to say so here, where the definition is written, than to
     * discover it mid-conversation - which is exactly how this was discovered.
     */
    public const NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/';

    /**
     * @param array{type?: string, properties?: array<string, mixed>, required?: list<string>} $parameters
     */
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
    ) {
    }

    /**
     * @param array{type?: string, properties?: array<string, mixed>, required?: list<string>} $parameters
     */
    public static function create(string $name, string $description = '', array $parameters = []): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('[ai] a tool definition needs a name');
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '[ai] the tool name %s would not survive the wire - letters, digits, underscore or dash, starting with a letter or an underscore, up to 64 characters',
                $name,
            ));
        }

        return new self($name, $description, $parameters === [] ? self::SCHEMA : $parameters);
    }

    /**
     * @param array{name?: string, description?: string, parameters?: array<string, mixed>} $tool
     */
    public static function fromArray(array $tool): self
    {
        return self::create(
            (string) ($tool['name'] ?? ''),
            (string) ($tool['description'] ?? ''),
            (array) ($tool['parameters'] ?? []),
        );
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'parameters'  => $this->parameters,
        ];
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Normalize a list of definitions, accepting arrays too.
     *
     * @param list<ToolDefinition|array<string, mixed>> $tools
     *
     * @return list<ToolDefinition>
     */
    public static function list(array $tools): array
    {
        $normalized = [];

        foreach ($tools as $index => $tool) {
            $normalized[] = match (true) {
                $tool instanceof self => $tool,
                is_array($tool) => self::fromArray($tool),
                default => throw new InvalidArgumentException(
                    sprintf('[ai] tool %s is not a ToolDefinition or an array', (string) $index)
                ),
            };
        }

        return $normalized;
    }
}
