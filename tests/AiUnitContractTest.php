<?php

declare(strict_types=1);

namespace Units\Ai\Tests;

use InvalidArgumentException;
use Patterns\EventPublisher;
use Patterns\IUnit;
use Patterns\IUnitSchema;
use PHPUnit\Framework\TestCase;
use stdClass;
use Units\Ai\Ai;
use Units\Ai\ChatMessage;
use Units\Ai\IAiProvider;
use Units\Ai\Providers\OpenAi;
use Units\Ai\Tests\Fakes\RecordingPublisher;
use Units\Ai\Tests\Fakes\ScriptedHttp;
use Units\Ai\ToolDefinition;

/**
 * What the AI unit *is*, independent of any provider dialect.
 *
 * The provider tests prove the translation. These prove the unit: that it is a
 * unit, that its DNA is its identity and carries no version ledger, and that it
 * remembers nothing between calls.
 */
final class AiUnitContractTest extends TestCase
{
    private const ANSWER = ['choices' => [['message' => ['content' => 'ok']]]];

    private function provider(ScriptedHttp $http): OpenAi
    {
        return new OpenAi(['apiKey' => 'test'], $http);
    }

    // -----------------------------------------------------------------------
    // The unit
    // -----------------------------------------------------------------------

    public function testItIsBothAUnitAndAProvider(): void
    {
        $ai = Ai::create(['provider' => $this->provider(new ScriptedHttp())]);

        $this->assertInstanceOf(IUnit::class, $ai);
        $this->assertInstanceOf(IAiProvider::class, $ai);
    }

    public function testDnaIsTheIdentityAndNothingElse(): void
    {
        $dna = Ai::create(['provider' => $this->provider(new ScriptedHttp())])->dna();

        $this->assertInstanceOf(IUnitSchema::class, $dna);
        $this->assertSame('ai', $dna->id());
        $this->assertSame(Ai::VERSION, $dna->version());
        $this->assertSame('Ai v' . Ai::VERSION, $dna->label());
        $this->assertNotSame('', $dna->description());
    }

    /**
     * The version ledger was removed from every unit: nothing read it, and every
     * persisted row paid for it. It must not come back through the DNA.
     */
    public function testDnaCarriesNoVersionLedger(): void
    {
        $dna = Ai::create(['provider' => $this->provider(new ScriptedHttp())])->dna()->toArray();

        $this->assertEqualsCanonicalizing(['id', 'version', 'label', 'description'], array_keys($dna));
        $this->assertArrayNotHasKey('versions', $dna);
    }

    public function testDnaIsFixedAtCreation(): void
    {
        $ai = Ai::create(['provider' => $this->provider(new ScriptedHttp())]);

        $this->assertSame($ai->dna(), $ai->dna());
    }

    /**
     * A unit has a voice: it inherits the label from its DNA and adds what it is
     * wired to. Saying which provider is answering is the difference between a
     * record and an actor.
     */
    public function testWhoamiSpeaksFromTheDnaAndNamesWhatItIsWiredTo(): void
    {
        $ai = Ai::create(['provider' => $this->provider(new ScriptedHttp())]);

        $this->assertSame($ai->dna()->label() . ' - openai/gpt-6-luna', $ai->whoami());
        $this->assertStringStartsWith($ai->dna()->label(), $ai->whoami());
    }

    public function testHelpDescribesWhatItIsWiredTo(): void
    {
        $help = Ai::create(['provider' => $this->provider(new ScriptedHttp())])->help();

        $this->assertStringContainsString('openai', $help);
        $this->assertStringContainsString('gpt-6-luna', $help);
    }

    // -----------------------------------------------------------------------
    // create() refuses what it cannot use
    // -----------------------------------------------------------------------

    public function testCreateRefusesToBuildWithoutAProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a provider');

        Ai::create([]);
    }

    public function testCreateRefusesSomethingThatIsNotAProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(IAiProvider::class);

        Ai::create(['provider' => new stdClass()]);
    }

    public function testCreateRefusesAPublisherThatIsNotAPublisher(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(EventPublisher::class);

        Ai::create([
            'provider' => $this->provider(new ScriptedHttp()),
            'events'   => new stdClass(),
        ]);
    }

    // -----------------------------------------------------------------------
    // It is a seam to a provider, not a session
    // -----------------------------------------------------------------------

    /**
     * Two calls are two requests. Nothing is remembered, which is why the unit
     * needs no `reset()` and why a long-running process cannot leak one user's
     * conversation into another's.
     */
    public function testItRemembersNothingBetweenCalls(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::ANSWER, self::ANSWER);

        $ai = Ai::create(['provider' => $this->provider($http)]);

        $ai->ask('first');
        $ai->ask('second');

        $this->assertSame(2, $http->count());
        $this->assertCount(1, $http->body(0)['messages']);
        $this->assertCount(1, $http->body(1)['messages']);
        $this->assertSame('first', $http->body(0)['messages'][0]['content']);
        $this->assertSame('second', $http->body(1)['messages'][0]['content']);
    }

    // -----------------------------------------------------------------------
    // Events: one verb, one event
    // -----------------------------------------------------------------------

    public function testEachCallPublishesItsOwnEvent(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::ANSWER, self::ANSWER, self::ANSWER);

        $events = new RecordingPublisher();

        $ai = Ai::create([
            'provider' => $this->provider($http),
            'events'   => $events,
        ]);

        $ai->ask('hi');
        $ai->chat([ChatMessage::user('hi')]);
        $ai->tools(
            [ToolDefinition::create('ping', 'Ping', ['type' => 'object', 'properties' => []])],
            ['prompt' => 'hi'],
        );

        $this->assertSame(['ai.ask', 'ai.chat', 'ai.tools'], $events->types());

        // The payload reports that a call happened, never what was said: a count
        // and a bounded preview, so an event log cannot become a transcript.
        $this->assertSame(['prompt' => 'hi'], $events->payload(0)['details']);
        $this->assertSame(['messages' => 1], $events->payload(1)['details']);
        $this->assertSame(['offered' => 1], $events->payload(2)['details']);
        $this->assertSame('openai', $events->payload(0)['provider']);
    }

    public function testThePromptInAnEventIsAPreviewNotThePrompt(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::ANSWER);

        $events = new RecordingPublisher();
        $ai = Ai::create([
            'provider' => $this->provider($http),
            'events'   => $events,
        ]);

        $ai->ask(str_repeat('secret ', 100));

        $preview = $events->payload(0)['details']['prompt'];

        $this->assertStringStartsWith('secret', $preview);
        $this->assertLessThan(150, strlen($preview));
    }

    public function testWithoutAPublisherItStillWorks(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::ANSWER);

        $ai = Ai::create(['provider' => $this->provider($http)]);

        $this->assertSame('ok', $ai->ask('hi')->content);
    }

    public function testNothingIsPublishedWhenNoPublisherWasGiven(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::ANSWER);

        $ai = Ai::create(['provider' => $this->provider($http)]);
        $ai->ask('hi');

        // No publisher was injected, so there is nowhere for an event to go -
        // and no silent default that would surprise a caller who wanted none.
        $this->assertSame(1, $http->count());
    }
}
