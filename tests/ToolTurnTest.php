<?php

declare(strict_types=1);

namespace Units\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Units\Ai\Ai;
use Units\Ai\ChatMessage;
use Units\Ai\Providers\Claude;
use Units\Ai\Providers\Gemini;
use Units\Ai\Providers\OpenAi;
use Units\Ai\Tests\Fakes\ScriptedHttp;
use Units\Ai\ToolCall;
use Units\Ai\ToolDefinition;

/**
 * Round two of a tool conversation.
 *
 * The first round is easy - offer tools, read the request. The second is where
 * providers actually differ, because the model has to be shown the call it made
 * *and* the answer it got, and each vendor spells that differently: a `tool`
 * role in OpenAI, a `tool_result` block in a user turn for Anthropic, a
 * `functionResponse` part for Google.
 *
 * Building a tool loop that works on one provider and 400s on the others is the
 * single most common way to get this wrong, so every dialect is asserted.
 */
final class ToolTurnTest extends TestCase
{
    private const OPENAI_ANSWER = ['choices' => [['message' => ['content' => 'ok']]]];

    private const CLAUDE_ANSWER = ['content' => [['type' => 'text', 'text' => 'ok']]];

    private const GEMINI_ANSWER = ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]];

    private const WEATHER_SCHEMA = [
        'type'       => 'object',
        'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
        'required'   => ['city'],
    ];

    private function weather(): ToolDefinition
    {
        return ToolDefinition::create('get_weather', 'Current weather for a city', self::WEATHER_SCHEMA);
    }

    private function call(): ToolCall
    {
        return ToolCall::create('get_weather', ['city' => 'London'], 'call_1');
    }

    /**
     * The second round as a caller would actually build it: the original
     * question, the assistant turn that asked for the tool, and the turn
     * carrying what the tool answered.
     *
     * @param class-string $class
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    private function roundTwo(string $class, ScriptedHttp $http, array $answer): array
    {
        $http->push($answer);

        $provider = new $class(['apiKey' => 'test'], $http);
        $call = $this->call();

        $provider->tools([$this->weather()], [
            'messages' => [
                ChatMessage::user('What is the weather in London?'),
                ChatMessage::asked([$call]),
                ChatMessage::answered($call, 'Rain, 12C'),
            ],
        ]);

        return $http->body();
    }

    // -----------------------------------------------------------------------
    // OpenAI: a `tool` role, addressed by id
    // -----------------------------------------------------------------------

    public function testOpenAiShowsTheCallItMadeAndTheResultAsAToolTurn(): void
    {
        $http = new ScriptedHttp();
        $messages = $this->roundTwo(OpenAi::class, $http, self::OPENAI_ANSWER)['messages'];

        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('call_1', $messages[1]['tool_calls'][0]['id']);
        $this->assertSame('function', $messages[1]['tool_calls'][0]['type']);
        $this->assertSame('get_weather', $messages[1]['tool_calls'][0]['function']['name']);

        // Arguments travel as a JSON *string* over this wire, not as an object.
        $this->assertJson($messages[1]['tool_calls'][0]['function']['arguments']);
        $this->assertSame(
            ['city' => 'London'],
            json_decode($messages[1]['tool_calls'][0]['function']['arguments'], true),
        );

        $this->assertSame('tool', $messages[2]['role']);
        $this->assertSame('call_1', $messages[2]['tool_call_id']);
        $this->assertSame('Rain, 12C', $messages[2]['content']);
    }

    // -----------------------------------------------------------------------
    // Claude: blocks inside the turns, system prompt hoisted out
    // -----------------------------------------------------------------------

    public function testClaudeShowsTheCallAsAToolUseBlockAndTheResultInAUserTurn(): void
    {
        $http = new ScriptedHttp();
        $messages = $this->roundTwo(Claude::class, $http, self::CLAUDE_ANSWER)['messages'];

        $use = $messages[1]['content'][0];
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('tool_use', $use['type']);
        $this->assertSame('call_1', $use['id']);
        $this->assertSame('get_weather', $use['name']);
        $this->assertSame(['city' => 'London'], $use['input']);

        // Anthropic has no `tool` role: the result is a block in a *user* turn.
        $result = $messages[2]['content'][0];
        $this->assertSame('user', $messages[2]['role']);
        $this->assertSame('tool_result', $result['type']);
        $this->assertSame('call_1', $result['tool_use_id']);
        $this->assertSame('Rain, 12C', $result['content']);
    }

    public function testClaudeHoistsTheSystemPromptOutOfTheConversation(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::CLAUDE_ANSWER);

        $provider = new Claude(['apiKey' => 'test'], $http);
        $provider->chat([
            ChatMessage::system('You answer with exactly one word.'),
            ChatMessage::user('Say ok'),
        ]);

        $body = $http->body();

        // Top-level, never as a message: Anthropic rejects a `system` role.
        $this->assertSame('You answer with exactly one word.', $body['system']);
        $this->assertCount(1, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function testClaudeMarksAFailedToolAsAnError(): void
    {
        $http = new ScriptedHttp();
        $http->push(self::CLAUDE_ANSWER);

        $call = $this->call();
        $provider = new Claude(['apiKey' => 'test'], $http);

        $provider->tools([$this->weather()], [
            'messages' => [
                ChatMessage::user('What is the weather in London?'),
                ChatMessage::asked([$call]),
                ChatMessage::answered($call, 'the API is down', failed: true),
            ],
        ]);

        $this->assertTrue($http->body()['messages'][2]['content'][0]['is_error']);
    }

    // -----------------------------------------------------------------------
    // Gemini: parts, in a model turn and a user turn
    // -----------------------------------------------------------------------

    public function testGeminiShowsTheCallAsAFunctionCallPartAndTheResultAsAFunctionResponsePart(): void
    {
        $http = new ScriptedHttp();

        // Google calls the conversation `contents`, not `messages`, and the
        // turns are `parts` rather than content blocks.
        $contents = $this->roundTwo(Gemini::class, $http, self::GEMINI_ANSWER)['contents'];

        $this->assertSame('model', $contents[1]['role']);
        $call = $contents[1]['parts'][0]['functionCall'];
        $this->assertSame('get_weather', $call['name']);
        $this->assertSame(['city' => 'London'], $call['args']);

        $this->assertSame('user', $contents[2]['role']);
        $result = $contents[2]['parts'][0]['functionResponse'];
        $this->assertSame('get_weather', $result['name']);
        $this->assertSame(['content' => 'Rain, 12C'], $result['response']);
    }

    // -----------------------------------------------------------------------
    // The claim in the README: nothing is ever executed
    // -----------------------------------------------------------------------

    /**
     * A tool call is a request. It comes back typed and decoded, and that is all
     * that happens - no second request, no execution, no callback. There is
     * nothing in this unit that could run one, and this is the test that says so.
     */
    public function testAToolCallComesBackAsARequestAndNothingRuns(): void
    {
        $http = new ScriptedHttp();
        $http->push(['choices' => [[
            'message' => [
                'content'   => null,
                'tool_calls' => [[
                    'id'       => 'call_9',
                    'type'     => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"London"}'],
                ]],
            ],
        ]]]);

        $ai = Ai::create(['provider' => new OpenAi(['apiKey' => 'test'], $http)]);
        $response = $ai->tools([$this->weather()], ['prompt' => 'What is the weather in London?']);

        $this->assertCount(1, $response->toolCalls);

        $call = $response->toolCalls[0];
        $this->assertSame('get_weather', $call->name);
        $this->assertSame(['city' => 'London'], $call->arguments);
        $this->assertSame('call_9', $call->id);

        // One request in, one answer out. The tool was not called.
        $this->assertSame(1, $http->count());
    }
}
