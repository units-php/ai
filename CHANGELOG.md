# Changelog

All notable changes to `units/ai` are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) ·
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-10-11

### Fixed

- **`units/ai` could not be installed.** `AiSchema` extends `Patterns\ValueObject`,
  and `patterns/value-object` was missing from `require` - so a fresh
  `composer require units/ai` fataled on the first `Ai::create()`. The package
  suite never noticed, because `tests/bootstrap.php` bridges `Patterns\` to the
  sibling packages in this repository and quietly supplied the class. The bridge
  now refuses to bridge a package that `composer.json` does not require.

### Added

- `patterns/value-object ^2.0.0` to `require`.

## [1.0.0] - 2026-10-11

### Added

- `Units\Ai\Ai` — the AI unit: one conversation, whichever provider is behind it.
  `ask()`, `chat()`, `tools()`, `validateConnection()`, on the `patterns/unit`
  contract.
- `Units\Ai\IAiProvider` — the provider port. Eight implementations ship with the
  unit, because without them it does nothing: OpenAI, DeepSeek, Grok, Mistral,
  OpenRouter and SiCore share the `OpenAiCompatible` base; Claude and Gemini
  implement the port directly because their wire format genuinely differs.
- `Units\Ai\Providers\SiCore` — an aggregator gateway: one key, one endpoint, and
  the model name chooses the vendor behind it. Cheapest of the seven.
- `Units\Ai\ChatMessage`, `Role`, `ToolCall`, `ToolDefinition`, `AiResponse`,
  `TokenUsage` — the shapes a conversation is made of. One conversation is
  portable across all eight providers; each renders its own dialect.
- `Units\Ai\AiEvent` — `ai.ask` / `ai.chat` / `ai.tools`, published to an injected
  `Patterns\EventPublisher`.
- `Units\Ai\AiException`, `AiConnectionException`, `AiToolException` — failures
  that name the provider and carry a machine-readable `errorCode`.

### Notes

- **No tool is ever executed.** `tools()` returns what the model asked for and
  stops. Deciding whether to run it, with what arguments and whose credentials, is
  the caller's job — the loop lives in the agent, not here.
- Every request is stateless: no conversation history, no cache, no retry, no
  spend tracking. Those are policies, and they belong where the caller can see
  them.
