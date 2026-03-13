import { timingSafeEqual } from 'node:crypto';
import { generateText, jsonSchema, stepCountIs, tool } from 'ai';
import { openai, createOpenAI } from '@ai-sdk/openai';

const DEFAULT_MAX_STEPS = 15;

export async function runWordPressAgentRequest(payload, headers = {}) {
  assertAuthorized(headers);

  const request = normalizeRequest(payload);
  const toolCalls = [];
  const tools = buildTools(request.toolDefinitions, request.toolEndpoint, toolCalls);
  const modelConfig = resolveModel(request.model, request.wpApiKey, request.wpApiProvider);
  const messages = buildMessages(request.history, request.prompt);

  let result = await generateText({
    model: modelConfig.instance,
    system: request.systemPrompt,
    messages,
    tools,
    stopWhen: stepCountIs(request.maxSteps),
    temperature: 0.2,
  });

  let message = normalizeText(result.text);
  let usage = normalizeUsage(result.usage);

  if (!message) {
    const summaryResult = await generateText({
      model: modelConfig.instance,
      system: request.systemPrompt,
      messages: [
        ...messages,
        {
          role: 'assistant',
          content: buildToolSummaryTranscript(toolCalls),
        },
        {
          role: 'user',
          content: 'Write the final user-facing summary now. Do not call tools. Summarize what you found, what you changed, what still needs attention, and any verification performed.',
        },
      ],
      temperature: 0.2,
    });

    message = normalizeText(summaryResult.text);
    usage = mergeUsage(usage, normalizeUsage(summaryResult.usage));
  }

  return {
    message,
    tool_calls: toolCalls,
    usage,
    provider: modelConfig.provider,
    model: modelConfig.modelId,
  };
}

function assertAuthorized(headers) {
  const expected = (process.env.AIVB_AGENT_RUNTIME_SECRET || '').trim();
  if (!expected) {
    throw createError('AIVB_AGENT_RUNTIME_SECRET is not configured in the runtime.', 500);
  }

  const provided = String(getHeader(headers, 'x-aivb-agent-secret') || '').trim();
  if (!provided) {
    throw createError('Missing runtime authorization secret.', 401);
  }

  const expectedBuffer = Buffer.from(expected, 'utf8');
  const providedBuffer = Buffer.from(provided, 'utf8');

  if (
    expectedBuffer.length !== providedBuffer.length ||
    !timingSafeEqual(expectedBuffer, providedBuffer)
  ) {
    throw createError('Invalid runtime authorization secret.', 401);
  }
}

function normalizeRequest(payload) {
  if (!payload || 'object' !== typeof payload || Array.isArray(payload)) {
    throw createError('Request body must be a JSON object.', 400);
  }

  const prompt = String(payload.prompt || '').trim();
  if (!prompt) {
    throw createError('The prompt field is required.', 400);
  }

  const systemPrompt = String(payload.system_prompt || payload.systemPrompt || '').trim();
  const toolEndpoint = String(payload.tool_endpoint || payload.toolEndpoint || '').trim();
  const toolDefinitions = Array.isArray(payload.tool_definitions || payload.toolDefinitions)
    ? payload.tool_definitions || payload.toolDefinitions
    : [];
  const history = Array.isArray(payload.history) ? payload.history : [];
  const maxSteps = clampInteger(payload.max_steps || payload.maxSteps, 1, 25, DEFAULT_MAX_STEPS);

  if (!toolEndpoint) {
    throw createError('The tool_endpoint field is required.', 400);
  }

  return {
    prompt,
    history,
    model: String(payload.model || '').trim(),
    systemPrompt,
    toolEndpoint,
    toolDefinitions,
    maxSteps,
    // API credentials forwarded from the WordPress admin settings.
    // Used as fallbacks when Vercel env vars are not configured.
    wpApiKey: String(payload.api_key || '').trim(),
    wpApiProvider: String(payload.api_provider || '').trim().toLowerCase(),
  };
}

/**
 * Resolve which AI model/provider to use.
 *
 * Priority (first configured wins):
 *   1. "custom"   — any OpenAI-compatible API via CUSTOM_API_KEY + CUSTOM_API_BASE_URL env vars
 *   2. "deepseek" — DeepSeek via env var DEEPSEEK_API_KEY, or WordPress-forwarded key
 *   3. "openai"   — OpenAI via env var OPENAI_API_KEY, or WordPress-forwarded key
 *
 * `wpApiKey` and `wpApiProvider` come from the WordPress admin settings and act
 * as fallbacks when Vercel env vars are not set — so users only need to configure
 * keys once, in the WordPress plugin admin page.
 */
function resolveModel(slug, wpApiKey = '', wpApiProvider = '') {
  const normalized = String(slug || process.env.AIVB_DEFAULT_MODEL || '').trim().toLowerCase();

  // Explicit slug routing (env-var-based providers take priority)
  if (isCustomSelection(normalized)) {
    return createCustomModel();
  }

  if (isDeepSeekSelection(normalized)) {
    return createDeepSeekModel(wpApiKey, wpApiProvider);
  }

  if (isOpenAISelection(normalized)) {
    return createOpenAIModel(wpApiKey, wpApiProvider);
  }

  // Auto-detect: try custom → deepseek → openai (env vars first)
  if ((process.env.CUSTOM_API_KEY || '').trim() && (process.env.CUSTOM_API_BASE_URL || '').trim()) {
    return createCustomModel();
  }

  if ((process.env.DEEPSEEK_API_KEY || '').trim()) {
    return createDeepSeekModel();
  }

  if ((process.env.OPENAI_API_KEY || '').trim()) {
    return createOpenAIModel();
  }

  // Fallback: use WordPress-forwarded API key
  if (wpApiKey) {
    if (wpApiProvider === 'deepseek') {
      return createDeepSeekModel(wpApiKey, wpApiProvider);
    }
    if (wpApiProvider === 'openai') {
      return createOpenAIModel(wpApiKey, wpApiProvider);
    }
  }

  throw createError(
    'No AI provider API key is configured. Enter your API key in the AI Vibe Builder settings page, or set DEEPSEEK_API_KEY / OPENAI_API_KEY in your Vercel environment variables.',
    500,
  );
}

/**
 * Custom OpenAI-compatible provider.
 * Supports DeepSeek, Mistral, Together, Groq, or any provider that exposes
 * an OpenAI-compatible /v1/chat/completions endpoint.
 *
 * Required env vars:
 *   CUSTOM_API_KEY       — your API key
 *   CUSTOM_API_BASE_URL  — base URL including /v1  (e.g. https://api.deepseek.com/v1)
 *   CUSTOM_MODEL         — model name              (e.g. deepseek-chat)
 */
function createCustomModel() {
  const apiKey  = (process.env.CUSTOM_API_KEY || '').trim();
  const baseURL = (process.env.CUSTOM_API_BASE_URL || '').trim();

  if (!apiKey) {
    throw createError('CUSTOM_API_KEY is not configured in the Vercel runtime.', 500);
  }
  if (!baseURL) {
    throw createError('CUSTOM_API_BASE_URL is not configured in the Vercel runtime.', 500);
  }

  const modelId    = (process.env.CUSTOM_MODEL || 'deepseek-chat').trim();
  const customOAI  = createOpenAI({ apiKey, baseURL, compatibility: 'compatible' });

  return {
    provider: 'custom',
    modelId,
    instance: customOAI(modelId),
  };
}

function createOpenAIModel(wpApiKey = '', wpApiProvider = '') {
  // Env var takes priority; fall back to the key forwarded from WordPress admin
  const apiKey = (process.env.OPENAI_API_KEY || '').trim() || (wpApiProvider === 'openai' ? wpApiKey : '');

  if (!apiKey) {
    throw createError(
      'No OpenAI API key found. Enter it in the AI Vibe Builder settings page or set OPENAI_API_KEY in Vercel.',
      500,
    );
  }

  const modelId = (process.env.OPENAI_MODEL || 'gpt-4o').trim();
  // Use createOpenAI so we can pass the key explicitly (env var may not be set)
  const oaiClient = createOpenAI({ apiKey, compatibility: 'strict' });

  return {
    provider: 'openai',
    modelId,
    instance: oaiClient(modelId),
  };
}

function createDeepSeekModel(wpApiKey = '', wpApiProvider = '') {
  // Env var takes priority; fall back to the key forwarded from WordPress admin
  const apiKey = (process.env.DEEPSEEK_API_KEY || '').trim() || (wpApiProvider === 'deepseek' ? wpApiKey : '');

  if (!apiKey) {
    throw createError(
      'No DeepSeek API key found. Enter it in the AI Vibe Builder settings page or set DEEPSEEK_API_KEY in Vercel.',
      500,
    );
  }

  const modelId = (process.env.DEEPSEEK_MODEL || 'deepseek-chat').trim();
  // DeepSeek exposes an OpenAI-compatible endpoint; use createOpenAI for explicit key passing
  const dsClient = createOpenAI({ apiKey, baseURL: 'https://api.deepseek.com/v1', compatibility: 'compatible' });

  return {
    provider: 'deepseek',
    modelId,
    instance: dsClient(modelId),
  };
}

function buildMessages(history, prompt) {
  const messages = [];

  for (const turn of history) {
    if (!turn || 'object' !== typeof turn) {
      continue;
    }

    const role = String(turn.role || '').trim();
    const content = normalizeText(turn.content);

    if (!content || !['user', 'assistant'].includes(role)) {
      continue;
    }

    messages.push({ role, content });
  }

  messages.push({ role: 'user', content: prompt });
  return messages;
}

function buildTools(toolDefinitions, toolEndpoint, toolCalls) {
  const runtimeSecret = (process.env.AIVB_AGENT_RUNTIME_SECRET || '').trim();

  return Object.fromEntries(
    toolDefinitions
      .map((definition) => normalizeToolDefinition(definition))
      .filter(Boolean)
      .map((definition) => [
        definition.name,
        tool({
          description: definition.description,
          inputSchema: jsonSchema(definition.parameters),
          execute: async (args) => {
            const response = await executeRemoteTool({
              name: definition.name,
              args,
              toolEndpoint,
              runtimeSecret,
            });

            toolCalls.push({
              id: response.id,
              name: definition.name,
              args,
              success: Boolean(response.success),
              summary: String(response.summary || ''),
            });

            // Hard cap: prevent any single tool result from blowing up the context window.
            // Each tool result is stored in the AI SDK's internal message history and
            // re-sent on every subsequent step — one large result poisons all future steps.
            return capToolResult(response.result, 12000);
          },
        }),
      ])
  );
}

function normalizeToolDefinition(definition) {
  if (!definition || 'object' !== typeof definition) {
    return null;
  }

  const fn = definition.function;
  if (!fn || 'object' !== typeof fn) {
    return null;
  }

  const name = String(fn.name || '').trim();
  if (!name) {
    return null;
  }

  const parameters = normalizeJsonSchema(fn.parameters);

  return {
    name,
    description: String(fn.description || '').trim(),
    parameters,
  };
}

function normalizeJsonSchema(schema) {
  if (!schema || 'object' !== typeof schema || Array.isArray(schema)) {
    return { type: 'object', properties: {} };
  }

  const normalized = { ...schema };

  if (!normalized.type) {
    normalized.type = 'object';
  }

  if ('object' === normalized.type && (!normalized.properties || 'object' !== typeof normalized.properties)) {
    normalized.properties = {};
  }

  return normalized;
}

async function executeRemoteTool({ name, args, toolEndpoint, runtimeSecret }) {
  const response = await fetch(toolEndpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-AIVB-Agent-Secret': runtimeSecret,
    },
    body: JSON.stringify({ name, args }),
  });

  const payload = await parseJsonResponse(response);

  if (!response.ok) {
    const message =
      (payload && typeof payload.error === 'string' && payload.error) ||
      (payload && typeof payload.message === 'string' && payload.message) ||
      `Tool ${name} request failed with HTTP ${response.status}.`;
    throw createError(message, response.status || 500);
  }

  if (!payload || 'object' !== typeof payload || Array.isArray(payload)) {
    throw createError(`Tool ${name} returned invalid JSON.`, 502);
  }

  return {
    id: String(payload.id || `tool_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`),
    success: Boolean(payload.success),
    summary: String(payload.summary || ''),
    result: payload.result,
  };
}

async function parseJsonResponse(response) {
  const raw = await response.text();

  if (!raw.trim()) {
    return {};
  }

  try {
    return JSON.parse(raw);
  } catch (error) {
    throw createError(`Runtime received invalid JSON from WordPress: ${error.message}`, 502);
  }
}

function normalizeUsage(usage) {
  if (!usage || 'object' !== typeof usage) {
    return {
      prompt_tokens: 0,
      completion_tokens: 0,
      total_tokens: 0,
    };
  }

  const promptTokens = Number(usage.inputTokens ?? usage.promptTokens ?? usage.prompt_tokens ?? 0);
  const completionTokens = Number(usage.outputTokens ?? usage.completionTokens ?? usage.completion_tokens ?? 0);
  const totalTokens = Number(usage.totalTokens ?? usage.total_tokens ?? promptTokens + completionTokens);

  return {
    prompt_tokens: safeInteger(promptTokens),
    completion_tokens: safeInteger(completionTokens),
    total_tokens: safeInteger(totalTokens),
  };
}

function mergeUsage(a, b) {
  return {
    prompt_tokens: safeInteger((a?.prompt_tokens || 0) + (b?.prompt_tokens || 0)),
    completion_tokens: safeInteger((a?.completion_tokens || 0) + (b?.completion_tokens || 0)),
    total_tokens: safeInteger((a?.total_tokens || 0) + (b?.total_tokens || 0)),
  };
}

function buildToolSummaryTranscript(toolCalls) {
  if (!toolCalls.length) {
    return 'No tools were called.';
  }

  return [
    'Tool calls executed:',
    ...toolCalls.map((toolCall) => {
      const status = toolCall.success ? 'success' : 'failure';
      return `- ${toolCall.name}: ${toolCall.summary || status}`;
    }),
  ].join('\n');
}

function normalizeText(value) {
  return String(value || '').trim();
}

/**
 * Cap a tool result to maxChars characters of serialized JSON.
 * The AI SDK stores every tool result in the conversation and re-sends it on every
 * subsequent step. One large result (a 100 KB file, serialized CSS, etc.) would
 * re-appear in 14 more steps → easily 1M+ tokens. This function trims oversized
 * results intelligently so the model still gets useful data.
 */
function capToolResult(result, maxChars = 12000) {
  const serialized = JSON.stringify(result);
  if (!serialized || serialized.length <= maxChars) return result;

  // For objects: truncate individual string fields, drop oversized arrays
  if (result && typeof result === 'object' && !Array.isArray(result)) {
    const trimmed = {};
    for (const [key, value] of Object.entries(result)) {
      if (typeof value === 'string' && value.length > 2000) {
        trimmed[key] = value.slice(0, 2000) + `...[truncated ${value.length} chars]`;
      } else if (Array.isArray(value) && JSON.stringify(value).length > 2000) {
        trimmed[key] = value.slice(0, 5);
        trimmed[`${key}_total`] = value.length;
        trimmed[`${key}_note`] = `Showing first 5 of ${value.length} items. Use a more specific query.`;
      } else {
        trimmed[key] = value;
      }
    }
    const trimmedStr = JSON.stringify(trimmed);
    if (trimmedStr.length <= maxChars) return trimmed;
    // Still too large — return a summary stub
    return { _truncated: true, _note: `Result was ${serialized.length} chars, too large for context. Use more targeted tools.` };
  }

  // For strings
  if (typeof result === 'string') {
    return result.slice(0, maxChars) + `...[truncated ${result.length} chars]`;
  }

  // Fallback for arrays or other types
  return { _truncated: true, _note: `Result was ${serialized.length} chars, too large. Use more specific arguments.` };
}

function safeInteger(value) {
  return Number.isFinite(value) ? Math.max(0, Math.round(value)) : 0;
}

function clampInteger(value, min, max, fallback) {
  const numeric = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(numeric)) {
    return fallback;
  }

  return Math.min(max, Math.max(min, numeric));
}

function isOpenAISelection(slug) {
  return ['gpt5_4', 'gpt-5.4', 'gpt', 'gpt4', 'gpt-4o', 'gpt-4', 'openai'].includes(slug);
}

function isDeepSeekSelection(slug) {
  return ['deepseek', 'deepseek-chat', 'deepseek-reasoner'].includes(slug);
}

function isCustomSelection(slug) {
  return ['custom', 'custom-api', 'custom_api'].includes(slug);
}

function getHeader(headers, name) {
  const normalizedName = name.toLowerCase();

  for (const [headerName, value] of Object.entries(headers || {})) {
    if (headerName.toLowerCase() === normalizedName) {
      return Array.isArray(value) ? value[0] : value;
    }
  }

  return '';
}

function createError(message, statusCode = 500) {
  const error = new Error(message);
  error.statusCode = statusCode;
  return error;
}
