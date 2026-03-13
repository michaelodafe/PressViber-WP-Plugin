# AI Vibe Builder Vercel Agent Runtime

This runtime adds an optional Vercel AI SDK execution layer for the `ai-vibe-builder` plugin.

WordPress remains the source of truth for:

- file reads and writes
- page inspection
- rendered-page verification
- controlled command execution

The Vercel runtime becomes the multi-step agent loop that decides which tools to call and in what order.

## What this changes

When `AIVB_AGENT_RUNTIME_URL` and `AIVB_AGENT_RUNTIME_SECRET` are configured in WordPress:

1. WordPress sends the user prompt, history, system prompt, and tool schemas to this runtime.
2. The runtime uses the Vercel AI SDK tool loop to execute multi-step reasoning.
3. Each tool call is sent back to WordPress over the secure `/wp-json/aivb/v1/agent-tool` bridge.
4. WordPress still owns the actual file system and page mutations.
5. If the runtime is unavailable, the plugin falls back to the built-in PHP agent loop.

## Runtime setup

Install dependencies inside this folder:

```bash
npm install
```

Set these environment variables in the runtime deployment:

- `AIVB_AGENT_RUNTIME_SECRET`: shared secret used by both WordPress and the runtime
- `OPENAI_API_KEY`: required for OpenAI-backed runs
- `OPENAI_MODEL`: optional, defaults to `gpt-5.4`
- `DEEPSEEK_API_KEY`: required for DeepSeek-backed runs
- `DEEPSEEK_MODEL`: optional, defaults to `deepseek-chat`
- `AIVB_DEFAULT_MODEL`: optional fallback, defaults to `deepseek`

The included [`.env.example`](/Users/michaelodafe/Documents/3D Objects/AI2Africa/AI2Africa WordPress/wp-content/plugins/ai-vibe-builder/vercel-agent-runtime/.env.example) shows the expected variable names.

## WordPress setup

Add these constants to `wp-config.php`:

```php
define( 'AIVB_AGENT_RUNTIME_URL', 'https://your-runtime-domain.vercel.app/api/run' );
define( 'AIVB_AGENT_RUNTIME_SECRET', 'replace-with-the-same-secret-you-set-in-vercel' );
```

Once those are present, the plugin will try the Vercel runtime first and fall back to the built-in PHP loop if the runtime request fails.

## Files

- [api/run.mjs](/Users/michaelodafe/Documents/3D Objects/AI2Africa/AI2Africa WordPress/wp-content/plugins/ai-vibe-builder/vercel-agent-runtime/api/run.mjs): Vercel function entrypoint
- [src/agent-runtime.mjs](/Users/michaelodafe/Documents/3D Objects/AI2Africa/AI2Africa WordPress/wp-content/plugins/ai-vibe-builder/vercel-agent-runtime/src/agent-runtime.mjs): AI SDK tool-loop implementation
- [vercel.json](/Users/michaelodafe/Documents/3D Objects/AI2Africa/AI2Africa WordPress/wp-content/plugins/ai-vibe-builder/vercel-agent-runtime/vercel.json): function duration config
