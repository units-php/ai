<?php

declare(strict_types=1);

namespace Units\Ai\Tests\Fakes;

use Patterns\Event;
use Patterns\EventPublisher;

/**
 * RecordingPublisher - remembers what the unit published, and nothing else.
 *
 * A unit only ever publishes, so the fake implements the write half of the
 * channel and not the channel: if this unit ever wanted to subscribe, this fake
 * would stop satisfying it and the test would say so.
 */
final class RecordingPublisher implements EventPublisher
{
    /**
     * @var list<Event>
     */
    private array $events = [];

    public function publish(Event $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (Event $event): string => $event->type(), $this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $index = 0): array
    {
        return $this->events[$index]->toArray();
    }
}
