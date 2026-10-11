<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;
use Patterns\IUnitSchema;
use Patterns\ValueObject;

/**
 * AiSchema - this unit's DNA (Patterns\IUnitSchema).
 *
 * Local on purpose, like every unit here: a unit ships its own schema class, so
 * its identity can change without touching the pattern or any sibling unit. The
 * shape is identical to its neighbours', and the id is what must stay private -
 * a shared schema is not sharing a shape, it is sharing a fate.
 *
 * Methods, not public fields. Equality and JSON serialization come from
 * Patterns\ValueObject, which is what makes the DNA persistable - and that is
 * what provenance needs: an answer can say which unit, at which version,
 * produced it.
 */
final class AiSchema extends ValueObject implements IUnitSchema
{
    /**
     * @param array<string, mixed> $props
     */
    private function __construct(array $props)
    {
        parent::__construct($props);
    }

    /**
     * @param array<string, mixed> $props
     */
    public static function create(array $props): self
    {
        $id      = (string) ($props['id'] ?? '');
        $version = (string) ($props['version'] ?? '');

        if ($id === '') {
            throw new InvalidArgumentException('AiSchema: id is required');
        }

        if ($version === '') {
            throw new InvalidArgumentException('AiSchema: version is required');
        }

        $label = (string) ($props['label'] ?? '');

        return new self([
            'id'          => $id,
            'version'     => $version,
            'label'       => $label === '' ? $id . ' v' . $version : $label,
            'description' => (string) ($props['description'] ?? ''),
        ]);
    }

    public function id(): string
    {
        return $this->props['id'];
    }

    public function version(): string
    {
        return $this->props['version'];
    }

    public function label(): string
    {
        return $this->props['label'];
    }

    public function description(): string
    {
        return $this->props['description'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toProps();
    }
}
