<?php

declare(strict_types=1);

namespace Units\Ai;

/**
 * IAiProvider - what every provider must be able to do.
 *
 * Four operations, and they are the same four for every provider: ask once, hold
 * a conversation, offer tools, and say whether it is reachable. That is the
 * whole contract. A provider is an *adapter* - it translates this interface into
 * one vendor's HTTP dialect and translates the answer back.
 *
 * Deliberately NOT in this contract:
 *
 *   - streaming, embeddings, images, moderation - real capabilities that only
 *     some providers have. Putting them here would force every provider to
 *     implement them or throw, which is the same as lying in the type system.
 *     A provider that can do more ships its own methods.
 *   - retries, caching, cost accounting - policy, and the caller's.
 *   - running tools. `tools()` asks the model *what* to run and returns the
 *     request. Executing it is the caller's decision, with the caller's
 *     credentials.
 *
 * Depend on IAiProvider, never on a concrete provider: that is what makes
 * swapping OpenAI for Gemini a one-line change at the call site.
 */
interface IAiProvider
{
    /**
     * Stable short name, used in errors, events and `AiResponse::$provider`.
     * Lowercase, and never a class name: it travels on the wire and into logs.
     */
    public function name(): string;

    /**
     * The model this instance will use unless a call overrides it.
     */
    public function model(): string;

    /**
     * One prompt, one answer.
     *
     * No system prompt and no tools: a system prompt belongs to a conversation
     * (`chat()`), and a tool request belongs to `tools()`. This one method does
     * one thing, so "which call do I want?" has one answer.
     *
     * @param array{model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, metadata?: array<string, mixed>} $options
     */
    public function ask(string $prompt, array $options = []): AiResponse;

    /**
     * A conversation, in order.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array{model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, systemPrompt?: string, metadata?: array<string, mixed>} $options
     */
    public function chat(array $messages, array $options = []): AiResponse;

    /**
     * Offer tools and let the model decide whether to ask for one.
     *
     * The tool calls come back in the response. Nothing is executed here.
     *
     * Give it a `prompt` for the one-shot case, or `messages` when there is a
     * conversation - which is what every round after the first of a tool loop
     * is, because the model has to see the call it made and the answer it got.
     * Rendering those is the provider's job: OpenAI replies with a `tool` role,
     * Anthropic with `tool_result` blocks in a user turn, Google with
     * `functionResponse` parts in one.
     *
     * @param list<ToolDefinition|array<string, mixed>> $tools
     * @param array{messages?: list<ChatMessage|array<string, mixed>>, prompt?: string, systemPrompt?: string, metadata?: array<string, mixed>, model?: string, temperature?: float, maxTokens?: int, stopSequences?: list<string>, reasoningEffort?: string} $request
     */
    public function tools(array $tools, array $request = []): AiResponse;

    /**
     * Is this provider reachable with this configuration?
     *
     * Never throws: an unreachable provider is a false, not an exception.
     */
    public function validateConnection(): bool;
}
