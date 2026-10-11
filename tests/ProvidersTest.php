<?php

declare(strict_types=1);

namespace Units\Ai\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Units\Ai\ChatMessage;
use Units\Ai\IAiProvider;
use Units\Ai\Providers\Claude;
use Units\Ai\Providers\DeepSeek;
use Units\Ai\Providers\Gemini;
use Units\Ai\Providers\Grok;
use Units\Ai\Providers\Mistral;
use Units\Ai\Providers\OpenAi;
use Units\Ai\Providers\OpenRouter;
use Units\Ai\Providers\SiCore;
use Units\Ai\Tests\Fakes\ScriptedHttp;
use Units\Ai\ToolDefinition;

/**
 * What each provider puts on the wire.
 *
 * A provider is an adapter, and an adapter is judged by its translation: the
 * URL it chose, the header it authenticated with, and the shape it rendered. All
 * of that is asserted here against a scripted client, so the suite proves the
 * dialects without a key, a network, or a token.
 *
 * Base URLs are asserted by suffix, not by literal - a provider's endpoint is
 * its own business and may move - except SiCore, whose route is the subject of
 * its own test, because getting it wrong fails in a way that reads like a bug in
 * this unit.
 */
final class ProvidersTest extends TestCase
{
    private const OPENAI_ANSWER = ['choices' => [['message' => ['content' => 'ok']]]];

    private const CLAUDE_ANSWER = ['content' => [['type' => 'text', 'text' => 'ok']]];

    private const GEMINI_ANSWER = ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]];

    private const WEATHER_SCHEMA = [
        'type'       => 'object',
        'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
        'required'   => ['city'],
    ];

    /**
     * Every provider, in the order the README lists them.
     */
    private const PROVIDERS = [
        'openai'     => [OpenAi::class,     'openai',     'gpt-6-luna'],
        'deepseek'   => [DeepSeek::class,   'deepseek',   'deepseek-flash'],
        'grok'       => [Grok::class,       'grok',       'grok-4.7'],
        'openrouter' => [OpenRouter::class, 'openrouter', 'openai/gpt-oss-20b'],
        'mistral'    => [Mistral::class,    'mistral',    'mistral-small-latest'],
        'claude'     => [Claude::class,     'claude',     'claude-sonnet-5-5'],
        'gemini'     => [Gemini::class,     'gemini',     'gemini-3.8-flash'],
        'sicore'     => [SiCore::class,     'sicore',     'deepseek-v4.1-flash'],
    ];

    /**
     * The six that share one translation.
     */
    private const OPENAI_DIALECT = [
        OpenAi::class,
        DeepSeek::class,
        Grok::class,
        OpenRouter::class,
        Mistral::class,
        SiCore::class,
    ];

    /**
     * @param class-string $class
     * @param array<string, mixed> $props
     */
    private function make(string $class, ScriptedHttp $http, array $props = []): IAiProvider
    {
        return new $class($props + ['apiKey' => 'test'], $http);
    }

    private function weather(): ToolDefinition
    {
        return ToolDefinition::create('get_weather', 'Current weather for a city', self::WEATHER_SCHEMA);
    }

    // -----------------------------------------------------------------------
    // Identity
    // -----------------------------------------------------------------------

    /**
     * The provider is the second argument, never a config key: a client that can
     * be swapped is what makes this suite offline, and what lets a caller put
     * its own PSR-18 client - with its own retries and timeouts - underneath.
     */
    public function testEveryProviderNamesItselfAndItsDefaultModel(): void
    {
        foreach (self::PROVIDERS as $key => [$class, $name, $model]) {
            $provider = new $class(['apiKey' => 'test']);

            $this->assertSame($name, $provider->name(), $key . ': name');
            $this->assertSame($model, $provider->model(), $key . ': default model');
        }
    }

    public function testThereAreEightProviders(): void
    {
        $this->assertCount(8, self::PROVIDERS);
    }

    // -----------------------------------------------------------------------
    // The OpenAI dialect - six providers, one translation
    // -----------------------------------------------------------------------

    public function testTheOpenAiDialectPostsToChatCompletionsWithABearerToken(): void
    {
        foreach (self::OPENAI_DIALECT as $class) {
            $http = new ScriptedHttp();
            $http->push(self::OPENAI_ANSWER);

            $this->make($class, $http)->chat([ChatMessage::user('hi')]);

            $this->assertSame('POST', $http->method(), $class);
            $this->assertStringEndsWith('/chat/completions', $http->url(), $class);
            $this->assertSame('Bearer test', $http->header(0, 'Authorization'), $class);
            $this->assertSame('application/json', $http->header(0, 'Content-Type'), $class);

            $body = $http->body();
            $this->assertArrayHasKey('model', $body, $class);
            $this->assertSame('user', $body['messages'][0]['role'], $class);
            $this->assertSame('hi', $body['messages'][0]['content'], $class);
        }
    }

    public function testTheOpenAiDialectNestsToolDefinitionsUnderFunction(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::OPENAI_ANSWER);

        $this->make(OpenAi::class, $http)->tools([$this->weather()], ['prompt' => 'weather?']);

        $this->assertSame([
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_weather',
                    'description' => 'Current weather for a city',
                    'parameters'  => self::WEATHER_SCHEMA,
                ],
            ],
        ], $http->body()['tools']);
    }

    // -----------------------------------------------------------------------
    // The two dialects that had to be written by hand
    // -----------------------------------------------------------------------

    public function testClaudeAuthenticatesWithItsOwnHeaderAndSpeaksMessages(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::CLAUDE_ANSWER);

        $this->make(Claude::class, $http)->chat([ChatMessage::user('hi')]);

        $this->assertStringEndsWith('/messages', $http->url());
        $this->assertSame('test', $http->header(0, 'x-api-key'));
        $this->assertNotNull($http->header(0, 'anthropic-version'), 'Anthropic requires a version header');
        $this->assertSame('user', $http->body()['messages'][0]['role']);
    }

    /**
     * Anthropic takes tools flat with an `input_schema`; there is no nested
     * `function` wrapper. Getting this wrong is a 400, not a degraded answer.
     */
    public function testClaudeTakesToolsFlatWithAnInputSchema(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::CLAUDE_ANSWER);

        $this->make(Claude::class, $http)->tools([$this->weather()], ['prompt' => 'weather?']);

        $tool = $http->body()['tools'][0];

        $this->assertSame('get_weather', $tool['name']);
        $this->assertSame('Current weather for a city', $tool['description']);
        $this->assertSame(self::WEATHER_SCHEMA, $tool['input_schema']);
        $this->assertArrayNotHasKey('function', $tool);
        $this->assertArrayNotHasKey('parameters', $tool);
    }

    public function testGeminiPutsTheModelInThePathAndTheKeyInTheQuery(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::GEMINI_ANSWER);

        $this->make(Gemini::class, $http)->chat([ChatMessage::user('hi')]);

        $this->assertStringContainsString('models/gemini-3.8-flash:generateContent', $http->url());
        $this->assertStringContainsString('key=test', $http->url());
        $this->assertNull($http->header(0, 'Authorization'), 'Gemini authenticates by query, not by header');
        $this->assertSame('application/json', $http->header(0, 'Accept'));
        $this->assertSame('user', $http->body()['contents'][0]['role']);
    }

    public function testGeminiTakesToolsAsFunctionDeclarations(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::GEMINI_ANSWER);

        $this->make(Gemini::class, $http)->tools([$this->weather()], ['prompt' => 'weather?']);

        $this->assertSame([
            [
                'functionDeclarations' => [
                    [
                        'name'        => 'get_weather',
                        'description' => 'Current weather for a city',
                        'parameters'  => self::WEATHER_SCHEMA,
                    ],
                ],
            ],
        ], $http->body()['tools']);
    }

    // -----------------------------------------------------------------------
    // SiCore
    // -----------------------------------------------------------------------

    /**
     * The API is on its own host and `/v1` belongs to the base, so the route is
     * asserted exactly rather than by suffix. Building it from the platform's site
     * root gives a locale redirect, and a redirect is a parse error rather than a
     * 404 - a genuinely confusing way to discover a wrong URL.
     */
    public function testSiCorePostsToTheDocumentedVersionedRoute(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::OPENAI_ANSWER);

        $this->make(SiCore::class, $http)->chat([ChatMessage::user('hi')]);

        $this->assertSame('POST', $http->method());
        $this->assertSame('https://api.token8341.com/v1/chat/completions', $http->url());
    }

    /**
     * SiCore is an aggregator: the model name selects the vendor behind it, and
     * those vendors disagree about what they accept. So this provider declines
     * to send tuning at all rather than guessing one vendor's limits for all.
     */
    public function testSiCoreSendsNoTuningAtAll(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::OPENAI_ANSWER);

        $this->make(SiCore::class, $http)->chat([ChatMessage::user('hi')]);

        $body = $http->body();

        $this->assertArrayHasKey('model', $body);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('max_tokens', $body);
    }

    public function testSiCoreReportsReachabilityWithoutThrowing(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::OPENAI_ANSWER);

        $provider = $this->make(SiCore::class, $http);

        $this->assertTrue($provider->validateConnection());
        $this->assertSame('https://api.token8341.com/v1/chat/completions', $http->url());
    }

    // -----------------------------------------------------------------------
    // Tuning comes from the provider's defaults, not from a shared guess
    // -----------------------------------------------------------------------

    public function testEachProviderSendsItsOwnDefaults(): void
    {
        $openAi = new ScriptedHttp();
        $openAi->push(self::OPENAI_ANSWER);
        $this->make(OpenAi::class, $openAi)->chat([ChatMessage::user('hi')]);

        // JSON turns 1.0 into 1 on the way out, so this is a numeric compare.
        $this->assertEqualsWithDelta(1.0, $openAi->body()['temperature'], 0.0001);
        $this->assertArrayNotHasKey('max_tokens', $openAi->body());

        $mistral = new ScriptedHttp();
        $mistral->push(self::OPENAI_ANSWER);
        $this->make(Mistral::class, $mistral)->chat([ChatMessage::user('hi')]);

        $this->assertEqualsWithDelta(0.7, $mistral->body()['temperature'], 0.0001);
        $this->assertSame(4000, $mistral->body()['max_tokens']);
    }

    // -----------------------------------------------------------------------
    // A tool name has to survive the wire
    // -----------------------------------------------------------------------

    /**
     * OpenAI validates the tool name against `^[a-zA-Z0-9_-]+$` and rejects the
     * whole request if it does not match - `products.topSelling`, the name that
     * reads best in PHP, is a 400. Catching it here means the failure happens
     * where the tool is defined, not in production at 3am.
     */
    public function testAToolNameThatCannotSurviveTheWireIsRefusedWhenItIsDefined(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolDefinition::create('products.topSelling', 'Dotted name', self::WEATHER_SCHEMA);
    }

    /**
     * Dashes and underscores survive; the length cap is the wire's, not ours.
     */
    public function testToolNamesThatSurviveTheWireAreAccepted(): void
    {
        $names = ['get_weather', 'get_weather_2', 'get-weather', '_private', str_repeat('a', 64)];

        foreach ($names as $name) {
            $this->assertSame($name, ToolDefinition::create($name, 'ok', self::WEATHER_SCHEMA)->name);
        }
    }

    public function testAToolNameLongerThanTheWireAllowsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolDefinition::create(str_repeat('a', 65), 'too long', self::WEATHER_SCHEMA);
    }
}
