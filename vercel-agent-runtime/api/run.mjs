import { runWordPressAgentRequest } from '../src/agent-runtime.mjs';

export default async function handler(req, res) {
  if ('POST' !== req.method) {
    return sendJson(res, 405, { error: 'Method not allowed. Use POST.' });
  }

  try {
    const body = await parseJsonBody(req);
    const result = await runWordPressAgentRequest(body, req.headers || {});
    return sendJson(res, 200, result);
  } catch (error) {
    const statusCode = Number.isInteger(error?.statusCode) ? error.statusCode : 500;
    const message = error instanceof Error ? error.message : 'Unexpected runtime error.';
    return sendJson(res, statusCode, { error: message });
  }
}

async function parseJsonBody(req) {
  if (req.body && 'object' === typeof req.body) {
    return req.body;
  }

  if ('string' === typeof req.body && '' !== req.body.trim()) {
    return JSON.parse(req.body);
  }

  const chunks = [];
  for await (const chunk of req) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  }

  const raw = Buffer.concat(chunks).toString('utf8').trim();
  return '' === raw ? {} : JSON.parse(raw);
}

function sendJson(res, statusCode, payload) {
  if ('function' === typeof res.status && 'function' === typeof res.json) {
    return res.status(statusCode).json(payload);
  }

  res.statusCode = statusCode;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(JSON.stringify(payload));
  return undefined;
}
