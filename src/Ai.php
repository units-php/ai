<?php

declare(strict_types=1);

namespace Units\Ai;

use InvalidArgumentException;
use Patterns\EventPublisher;
use Patterns\IUnit;
use Patterns\IUnitSchema;

/**
 * Ai - the AI unit: one conversation, whichever provider is behind it.
 *
 * Ask it something, hold a conversation with it, or offer it tools and see what
 * it asks for. The provider is injected, so the caller decides *who answers* at
 * the call site and nothing above this line needs to know: swapping OpenAI for
 * Gemini is one line in a container, not a branch in every feature.
 *
 * Three things this unit deliberately does not do:
 *
 *   - **It does not run tools.** `tools()` returns what the model asked for.
 *     Whether to run `delete_account`, with what arguments, and with whose
 *     credentials is the caller's decision - a library that executes model output
 *     on its own is a library that executes model output on its own.
 *   - **It does not retry, or cache, or count money.** Those are policies, and
 *     policies belong where the caller can see and change them.
 *   - **It does not remember.** No conversation state, no last response, no
 *     history. Pass the messages you want considered; the unit is a stateless
 *     seam to a provider, not a session.
 *
 * The provider is an adapter, exactly as ILogger is for the log unit. One object
 * to type-hint, one thing to fake in a test, and `validateConnection()` to find
 * out whether it is real.
 */
final class Ai implements IUnit, IAiProvider
{
    public const VERSION = '1.0.0';

    private function __construct(
        private readonly AiSchema $dna,
        private readonly IAiProvider $provider,
        private readonly ?EventPublisher $events = null,
    ) {
    }

    /**
     * @param array{provider?: IAiProvider, dna?: array<string, mixed>, events?: EventPublisher|null} $props
     */
    public static function create(array $props = []): self
    {

        $dna = AiSchema::create($props['dna'] ?? [
            'id'          => 'ai',
            'version'     => self::VERSION,
            'label'       => 'Ai v' . self::VERSION,
            'description' => 'AI unit - ask, chat and tool calling over an injected provider (Patterns\\IUnit).',
        ]);

        $provider = $props['provider'] ?? null;

        if (!$provider instanceof IAiProvider) {
            throw new InvalidArgumentException(
                'Ai::create() needs a provider - an object implementing ' . IAiProvider::class,
            );
        }

        $events = $props['events'] ?? null;

        if ($events !== null && !$events instanceof EventPublisher) {
            throw new InvalidArgumentException(
                'the event publisher must implement ' . EventPublisher::class,
            );
        }

        return new self($dna, $provider, $events);
    }

    // -----------------------------------------------------------------------
    // IUnit
    // -----------------------------------------------------------------------

    public function dna(): IUnitSchema
    {
        return $this->dna;
    }

    public function whoami(): string
    {
        return sprintf('%s - %s/%s', $this->dna->label(), $this->provider->name(), $this->provider->model());
    }

    // -----------------------------------------------------------------------
    // The provider, and what it is
    // -----------------------------------------------------------------------

    public function provider(): IAiProvider
    {
        return $this->provider;
    }

    public function name(): string
    {
        return $this->provider->name();
    }

    public function model(): string
    {
        return $this->provider->model();
    }

    // -----------------------------------------------------------------------
    // IAiProvider - the unit is what callers type-hint
    // -----------------------------------------------------------------------

    /**
     * One prompt, one answer - no conversation, no system prompt, no tools.
     *
     * A system prompt is a property of a conversation, so it lives on `chat()`.
     * Tools are a request, so they live on `tools()`. Keeping ask() to its one
     * job is what makes "which call do I want?" a question with one answer.
     *
     * @param array{model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, metadata?: array<string, mixed>} $options
     */
    public function ask(string $prompt, array $options = []): AiResponse
    {
        $response = $this->provider->ask($prompt, $options);

        $this->publish(AiEvent::ask($this->provider->name(), $prompt, $response));

        return $response;
    }

    /**
     * A conversation, in order.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array{model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, systemPrompt?: string, metadata?: array<string, mixed>} $options
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $conversation = ChatMessage::list($messages);

        $response = $this->provider->chat($conversation, $options);

        $this->publish(AiEvent::chat($this->provider->name(), count($conversation), $response));

        return $response;
    }

    /**
     * Offer tools and see what the model asks for. Nothing is executed.
     *
     * `prompt` for the one-shot case; `messages` when there is a conversation -
     * which is every round after the first of a tool loop, since the model has to
     * see the call it made and the answer it got.
     *
     * The request carries its own tuning - `model`, `temperature`, `maxTokens`,
     * `stopSequences`, `reasoningEffort` - because a tool call is its own call.
     * `ask()` has no tools for exactly that reason, so this is the only way in.
     *
     * @param list<ToolDefinition|array<string, mixed>> $tools
     * @param array{messages?: list<ChatMessage|array<string, mixed>>, prompt?: string, systemPrompt?: string, metadata?: array<string, mixed>, model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, reasoningEffort?: string} $request
     */
    public function tools(array $tools, array $request = []): AiResponse
    {
        $definitions = ToolDefinition::list($tools);

        $response = $this->provider->tools($definitions, $request);

        $this->publish(AiEvent::tools($this->provider->name(), count($definitions), $response));

        return $response;
    }

    public function validateConnection(): bool
    {
        return $this->provider->validateConnection();
    }

    // -----------------------------------------------------------------------
    // Living documentation (not part of the pattern)
    // -----------------------------------------------------------------------

    /**
     * Rendered from the DNA and the provider, never hand-written - so it cannot
     * drift from what the unit actually is.
     */
    public function help(): string
    {
        $operations = [
            'ask(prompt, options?)'   => 'one prompt, one answer',
            'chat(messages, options?)' => 'a conversation, in order',
            'tools(definitions, req?)' => 'what the model wants run - nothing is run',
            'validateConnection()'    => 'is this provider reachable',
            'provider()'              => 'the adapter behind the unit',
        ];

        $lines = [
            $this->whoami(),
            '',
            '  ' . $this->dna->description(),
            '  events     : ' . ($this->events === null ? '(none)' : $this->events::class),
            '',
        ];

        foreach ($operations as $signature => $description) {
            $lines[] = sprintf('  %-32s %s', $signature, $description);
        }

        $lines[] = '';
        $lines[] = '  $ai = Ai::create([\'provider\' => new OpenAi([\'apiKey\' => $key])]);';
        $lines[] = '  $ai->ask(\'What is 2+2?\')->content;';

        return implode(PHP_EOL, $lines);
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    /**
     * Not swallowed. `Patterns\EventPublisher` requires a publish to surface
     * transport failures, and an observer that silently drops is worse than one
     * that is absent - you would be reading a log that lies.
     */
    private function publish(AiEvent $event): void
    {
        $this->events?->publish($event);
    }
}
