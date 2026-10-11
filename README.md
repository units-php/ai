
```bash
     \    _ _|       |   |        _)  |   
    _ \     |        |   |  __ \   |  __| 
   ___ \    |        |   |  |   |  |  |   
 _/    _\ ___|      \___/  _|  _| _| \__| 
                                                
version: 1.0.0
last_update: 11.10.26 (post apocalypse) 
                        
```
# AI Unit

**One interface for every AI provider — ask, chat, and tool calling, without the
provider leaking into your code.**

Part of the **Units** collection: stable implementations of the Patterns
contracts. One unit, one package.

- Package: `units/ai`
- Implements: Unit Architecture `Patterns\IUnit` — `create()`, `dna()`, `whoami()`
- Executes tools: **never** (see [What this unit does not do](#what-this-unit-does-not-do))
- Dependencies: `patterns/unit`, `patterns/event-channel`, one HTTP client
- PHP: `>=8.1`

## Why

Swapping OpenAI for Gemini should be one line where the provider is constructed,
not a branch in every feature that thinks. The provider is injected, so the call
site decides *who answers* and nothing above that line needs to know:

```php
$ai = Ai::create(['provider' => new OpenAi(['apiKey' => $key])]);
$ai->ask('What is 2+2?')->content;          // "2+2 equals 4"
```

Change one line to `new Gemini([...])`, `new DeepSeek([...])`, `new Claude([...])`
— the rest of the codebase does not move. That is the whole unit.

## In package

- **One interface** — `IAiProvider`. Everything else is the same object.
- **Eight providers** — six share the OpenAI dialect, two implement the port
  directly because their wire format genuinely differs.
- **Tool calling first** — offer tools, get back typed `ToolCall` objects.
  Nothing is executed for you.
- **Portable conversations** — one `ChatMessage` list renders into three
  dialects; each provider speaks its own.
- **Auditable** — every request is a plain PSR-18 call you can inject your own
  client into, and every response carries provider, model, usage and duration.
- **No retries, no cache, no memory** — see below. Deliberately.

## Supported providers

| Provider | `name()` | Default model | Wire dialect | Cost |
|---|---|---|---|---|
| **DeepSeek** | `deepseek` | `deepseek-flash` | OpenAI | $ |
| **OpenRouter** | `openrouter` | `openai/gpt-oss-20b` | OpenAI | $ |
| **Grok** | `grok` | `grok-4.7` | OpenAI | $ |
| **Mistral** | `mistral` | `mistral-small-latest` | OpenAI | $$ |
| **Gemini** | `gemini` | `gemini-3.8-flash` | Google | $$ |
| **OpenAI** | `openai` | `gpt-6-luna` | OpenAI | $$$$ |
| **Claude** | `claude` | `claude-sonnet-5-5` | Anthropic | $$$ |
| **SiCore** | `sicore` | `deepseek-v4.1-flash` | OpenAI | **Cheapest** |

`SiCore` is the cheapest of the eight because it is not one vendor: it is an
aggregator — one key, one endpoint — and the **model name is the vendor
selector**. Models and prices are listed at
[token8341.com/en/pricing](https://www.token8341.com/en/pricing).

Cost figures are the project's assessment, not metered here.

## Quick start

```php
use Units\Ai\Ai;
use Units\Ai\Providers\OpenAi;
use Units\Ai\Providers\DeepSeek;
use Units\Ai\Providers\OpenRouter;
use Units\Ai\Providers\Grok;
use Units\Ai\Providers\Mistral;
use Units\Ai\Providers\Gemini;
use Units\Ai\Providers\Claude;
use Units\Ai\Providers\SiCore;

$ai = Ai::create(['provider' => new OpenAi(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new DeepSeek(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new OpenRouter(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new Grok(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new Mistral(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new Gemini(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new Claude(['apiKey' => $key])]);
$ai = Ai::create(['provider' => new SiCore(['apiKey' => $key, 'model' => 'deepseek-v4-pro'])]);

$ai->ask('What is 2+2?')->content;
```

One prompt is a shortcut for one conversation. When you need a conversation, say
so:

```php
$answer = $ai->chat([
    ChatMessage::system('You answer with exactly one word.'),
    ChatMessage::user('Say ok'),
]);

$answer->content;      // "ok"
$answer->provider;     // "openai"
$answer->model;        // "gpt-6-luna"
$answer->usage;        // TokenUsage
$answer->duration;     // ms
```

## The three calls

| Method | Use it for |
|---|---|
| `ask($prompt, $options?)` | one prompt, one answer. No conversation, no system prompt, no tools. |
| `chat($messages, $options?)` | a conversation, in order. The system prompt belongs here. |
| `tools($definitions, $request?)` | offer tools and see what the model asks for. **Nothing is run.** |

Three methods, three jobs, and no overlap — the reason a system prompt is not on
`ask()` is that a system prompt is a property of a *conversation*, and the reason
tools are not on `ask()` is that a tool request is its own kind of call.

## Tool calling

```php
$weather = ToolDefinition::create('get_weather', 'Current weather for a city', [
    'type'       => 'object',
    'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
    'required'   => ['city'],
]);

$response = $ai->tools([$weather], ['prompt' => 'What is the weather in London?']);

foreach ($response->toolCalls as $call) {
    $call->name;       // "get_weather"
    $call->arguments;  // ['city' => 'London']  — already decoded
    $call->id;         // the provider's id for it, when the provider sends one
}
```

**A `ToolCall` is a request, never an execution.** This unit returns it and stops
there. Deciding whether to run `delete_everything`, with what arguments, and with
whose credentials is a decision no library should take on the caller's behalf.
The loop that runs tools and feeds the answers back is a separate thing —
`Units\Agent` — and it is not part of this package.

> **Tool names must survive the wire.** Letters, digits, underscores and dashes,
> starting with a letter or underscore, up to 64 characters. OpenAI rejects the
> whole request for anything else — `Invalid 'tools[0].function.name'` — so
> `products.topSelling`, the name that reads best in PHP, is a 400.
> `ToolDefinition` refuses it when the tool is *defined*, not when it is called.

## Provider ranking

Carried over from the TypeScript project this unit was ported from, as *it*
scored them — not re-measured here:

| Rank | Provider | Speed | Quality | Cost | Best for |
|---|---|---|---|---|---|
| 1 | **SiCore** | — | several vendors | **Cheapest** | one key, many vendors |
| 2 | **DeepSeek** | Slow | Excellent | $ | best overall value |
| 3 | **OpenRouter** | Good | Good | $ | budget + model variety |
| 4 | **OpenAI** | Fast | Good | $$$$ | speed-critical |
| 5 | **Mistral** | Good | Excellent | $$ | European privacy |
| 6 | **Gemini** | Good | Excellent | $$ | Google ecosystem |
| 7 | **Grok** | Slow | Excellent | $ | budget + quality |
| 8 | **Claude** | Slow | Incomplete | $$$ | text-only tasks |

## Events

Pass a `Patterns\EventPublisher` and the unit reports what it did — to a broker,
a log, a test:

```php
$ai = Ai::create(['provider' => $provider, 'events' => $publisher]);

$ai->ask('hi');    // publishes ai.ask
$ai->chat($msgs);  // publishes ai.chat
$ai->tools($t);    // publishes ai.tools
```

`EventPublisher`, not `EventChannel`: a unit that reports what happened never
subscribes to anything, and asking for the wider port would make you supply
`connect()` and `on()` that are never called.

The payload carries no content — a request happened, what it cost, how long it
took, and a bounded preview of the prompt. The answer is not published; if you
want it elsewhere, that is your decision, not the unit's.

## What this unit does not do

| Not here | Why |
|---|---|
| **Running tools** | A model's output is a request. Somebody has to decide — see `Units\Agent`. |
| **Retries, backoff, caching** | Policies. They belong where the caller can see and change them. |
| **Spend tracking, budgets** | Same. The unit reports `TokenUsage`; what it costs is your table. |
| **Conversation memory** | Pass the messages you want considered. The unit is a stateless seam to a provider, not a session. |
| **A `temperature` default for everyone** | Each provider sets its own, and can decline to send one at all. |

## Installation

```bash
composer require units/ai
```

`units/ai` requires `patterns/event-channel` for the event port. It ships one
HTTP dependency — `guzzlehttp/guzzle` — as the default PSR-18 client. Every
provider accepts an injected `Psr\Http\Client\ClientInterface`, so nothing in
this unit needs to reach the network in a test.

## API keys

| Provider | Where |
|---|---|
| OpenAI | [platform.openai.com](https://platform.openai.com) |
| DeepSeek | [platform.deepseek.com](https://platform.deepseek.com) |
| OpenRouter | [openrouter.ai](https://openrouter.ai) |
| Grok | [platform.x.ai](https://platform.x.ai) |
| Mistral | [console.mistral.ai](https://console.mistral.ai) |
| Gemini | [aistudio.google.com](https://aistudio.google.com) |
| Claude | [console.anthropic.com](https://console.anthropic.com) |
| SiCore | [token8341.com](https://www.token8341.com/) |

Keys are never read from the environment by this package: `create()` takes the
provider, and the provider takes its `apiKey`. Where your key comes from is your
application's business.

## Tests

```bash
composer install
composer test
```

35 tests, and not one of them touches the network: the PSR-18 client is faked,
so the suite proves what this unit *renders* for each provider without a key or a
token. What is asserted is the translation, because that is the part that breaks:

- **identity** — eight names, eight default models, and that `ask()` sends one
  message while a `chat()` sends exactly the conversation it was given;
- **the OpenAI dialect, asserted once for all six** that share it — route, bearer
  header, nested `function` tool payload, and the round-two `tool` role with the
  arguments still a JSON string;
- **Anthropic** — `x-api-key` and a version header, flat tools with an
  `input_schema`, the system prompt hoisted out of the conversation, and results
  as `tool_result` blocks in a *user* turn;
- **Google** — the model in the path, the key in the query,
  `functionDeclarations`, and `contents`/`parts` with a `functionResponse` part;
- **SiCore** — the documented versioned route, and no tuning sent;
- **that nothing is ever executed** — a tool call comes back typed and decoded,
  nothing runs, and the request count stays at one.

Run from a checkout of this repository (the package is not installed into itself
here):

```bash
cd packages/ai && ../logger/vendor/bin/phpunit
```

## License

MIT.
