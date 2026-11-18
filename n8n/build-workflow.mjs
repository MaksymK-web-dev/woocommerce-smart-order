// Builds the n8n workflow JSON for the WooCommerce → AI → Telegram chain.
// Run: node n8n/build-workflow.mjs
//
// Four things make this production rather than a demo:
//   1. the HMAC signature WooCommerce signs every delivery with is verified before
//      anything else runs, so a forged POST to the webhook URL is rejected;
//   2. the same order is not announced twice, because WooCommerce retries deliveries;
//   3. the AI step degrades instead of breaking the chain, and Telegram retries;
//   4. the provider is a base URL plus a model taken from the environment, not a
//      hardcoded api.openai.com.
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// Node 0: reject anything that is not signed by the store.
//
// WooCommerce sends X-WC-Webhook-Signature = base64(HMAC-SHA256(raw body, secret)).
// The signature covers the exact bytes, so the webhook node is configured with
// rawBody: true — which places untouched bytes in binary.data, base64-encoded.
const verifyCode = `
const crypto = require('crypto');

const secret = $env.WC_WEBHOOK_SECRET || '';
if (!secret) {
  throw new Error('WC_WEBHOOK_SECRET is not set: refusing to trust an unsigned delivery.');
}

const headers = $json.headers || {};
const received = String(headers['x-wc-webhook-signature'] || '');
if (!received) {
  throw new Error('Missing X-WC-Webhook-Signature header.');
}

// rawBody puts the untouched payload in binary.data; a JSON string body is accepted
// as well so the node keeps working if the webhook option changes.
const binary = $input.first().binary || {};
const raw = binary.data?.data
  ? Buffer.from(binary.data.data, 'base64').toString('utf8')
  : (typeof $json.body === 'string' ? $json.body : null);

if (raw === null) {
  throw new Error('Raw body unavailable: enable the Raw Body option on the webhook node.');
}

const expected = crypto.createHmac('sha256', secret).update(raw, 'utf8').digest('base64');

const a = Buffer.from(expected, 'utf8');
const b = Buffer.from(received, 'utf8');
const ok = a.length === b.length && crypto.timingSafeEqual(a, b);

if (!ok) {
  throw new Error('Webhook signature does not match.');
}

const order = JSON.parse(raw);

return [{
  json: {
    order,
    orderId: String(order.number || order.id || ''),
    topic: String(headers['x-wc-webhook-topic'] || ''),
  },
}];
`.trim();

// Node 1: turn the order into the few fields the message needs, and claim the order.
//
// Delivery is at-least-once: WooCommerce retries a failed webhook and n8n can replay
// one. Only this node touches the static data — two nodes writing it made the last
// stale copy win and the claim was lost.
//
// The claim expires after DEDUPE_WINDOW so a delivery that failed and was retried an
// hour later is still announced. Losing an order is worse than announcing it twice.
const extractCode = `
const order = $json.order || {};
const b = order.billing || {};

const name = [b.first_name, b.last_name].filter(Boolean).join(' ').trim()
  || order.customer_name || 'Без імені';
const phone = (b.phone || '').trim();
const items = (order.line_items || []).map(li => \`\${li.name} (x\${li.quantity})\`);
const itemsText = items.length ? items.join(', ') : '—';
const totalText = order.total
  ? \`\${Number(order.total).toFixed(2)} \${order.currency || 'UAH'}\`
  : '—';
const comment = (order.customer_note || '').trim();

const orderId = String($json.orderId || order.number || order.id || '');

const WEEK = 7 * 24 * 60 * 60 * 1000;
const DEDUPE_WINDOW = 10 * 60 * 1000;

const store = $getWorkflowStaticData('global');
const claims = store.claims || {};
const now = Date.now();

for (const [key, at] of Object.entries(claims)) {
  if (typeof at !== 'number' || now - at > WEEK) {
    delete claims[key];
  }
}

const claimedAt = typeof claims[orderId] === 'number' ? claims[orderId] : 0;
const isNew = '' !== orderId && (0 === claimedAt || now - claimedAt > DEDUPE_WINDOW);

if (isNew) {
  claims[orderId] = now;
}
store.claims = claims;

return [{
  json: {
    orderId,
    customer: name,
    phone: phone || 'не вказано',
    itemsText,
    totalText,
    comment,
    hasComment: comment.length > 0,
    isNew,
  },
}];
`.trim();

// Node 4: read the AI answer and build the HTML message.
const buildMessageCode = `
const d = $('1. Extract order').first().json;

let aiOk = false;
let urgent = false;
let note = '';
try {
  const ai = $('3. AI urgency').first().json;
  let content = ai?.choices?.[0]?.message?.content;
  if (typeof content === 'string' && content.trim()) {
    content = content.replace(/^\`\`\`(?:json)?/i, '').replace(/\`\`\`$/, '').trim();
    const parsed = JSON.parse(content);
    urgent = parsed.urgent === true;
    note = String(parsed.note ?? '').trim();
    aiOk = true;
  }
} catch (e) {
  aiOk = false;
}

const esc = (s) => String(s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const lines = [];
lines.push(\`🛒 <b>Нове замовлення #\${esc(d.orderId)}</b>\`);
lines.push('');
lines.push(\`👤 Клієнт: <b>\${esc(d.customer)}</b> (\${esc(d.phone)})\`);
lines.push('');
lines.push(\`📦 Товари: \${esc(d.itemsText)} — <b>\${esc(d.totalText)}</b>\`);

if (d.hasComment) {
  lines.push('');
  lines.push(\`💬 Коментар: «\${esc(d.comment)}»\`);
  if (urgent) {
    lines.push('');
    lines.push(\`⚠️ ШІ-позначка: «\${esc(note || d.comment)}»\`);
  } else if (aiOk) {
    lines.push('', '✅ ШІ: термінових вимог щодо доставки не знайдено');
  } else {
    lines.push('', '⚠️ ШІ недоступний — перевірте коментар вручну');
  }
}

return [{
  json: {
    ...d,
    aiOk,
    urgent,
    note,
    telegramText: lines.join('\\n'),
  },
}];
`.trim();

const systemPrompt = [
  'Ти — асистент менеджера інтернет-магазину.',
  'Отримуєш коментар покупця до замовлення.',
  'Визнач, чи містить коментар термінове прохання про час або спосіб доставки',
  '(наприклад: «доставка після 18:00», «зателефонувати за годину», «курʼєр до 12:00»,',
  '«самовивіз сьогодні», «не дзвоніть, пишіть у телеграм»).',
  'Поверни СУВОРО JSON без пояснень:',
  '{"urgent": true|false, "note": "коротке формулювання вимоги українською, або порожній рядок"}',
].join(' ');

const aiBody = `={{ JSON.stringify({
  model: $env.AI_MODEL || 'gpt-4o-mini',
  temperature: 0,
  response_format: { type: 'json_object' },
  messages: [
    { role: 'system', content: ${JSON.stringify(systemPrompt)} },
    { role: 'user', content: 'Коментар покупця: ' + JSON.stringify($json.comment) }
  ]
}) }}`;

const telegramBody = `={{ JSON.stringify({
  chat_id: $env.TELEGRAM_CHAT_ID,
  parse_mode: 'HTML',
  disable_web_page_preview: true,
  text: $json.telegramText
}) }}`;

const workflow = {
  name: 'WooCommerce Smart Order → Telegram',
  nodes: [
    {
      parameters: {
        httpMethod: 'POST',
        path: 'wc-order-created',
        responseMode: 'onReceived',
        responseCode: 200,
        options: {
          // The signature covers the exact bytes, so the body must stay untouched.
          rawBody: true,
        },
      },
      id: 'b1a1c0de-0001-4000-8000-000000000001',
      name: 'WC order.created',
      type: 'n8n-nodes-base.webhook',
      typeVersion: 2,
      position: [-460, 0],
      webhookId: 'wc-order-created',
    },
    {
      parameters: {
        mode: 'runOnceForAllItems',
        jsCode: verifyCode,
      },
      id: 'b1a1c0de-0001-4000-8000-000000000006',
      name: '0. Verify signature',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: [-240, 0],
    },
    {
      parameters: {
        mode: 'runOnceForAllItems',
        jsCode: extractCode,
      },
      id: 'b1a1c0de-0001-4000-8000-000000000002',
      name: '1. Extract order',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: [-20, 0],
    },
    {
      parameters: {
        conditions: {
          options: {
            caseSensitive: true,
            leftValue: '',
            typeValidation: 'strict',
            version: 2,
          },
          conditions: [
            {
              id: 'cond-is-new',
              leftValue: '={{ $json.isNew }}',
              rightValue: '',
              operator: { type: 'boolean', operation: 'true', singleValue: true },
            },
          ],
          combinator: 'and',
        },
        options: {},
      },
      id: 'b1a1c0de-0001-4000-8000-000000000007',
      name: '2. Already notified?',
      type: 'n8n-nodes-base.if',
      typeVersion: 2.2,
      position: [200, 0],
    },
    {
      parameters: {
        method: 'POST',
        // Any provider that speaks OpenAI's protocol: set AI_BASE_URL.
        url: '={{ $env.AI_BASE_URL || "https://api.openai.com/v1" }}/chat/completions',
        sendHeaders: true,
        headerParameters: {
          parameters: [
            { name: 'Authorization', value: '=Bearer {{ $env.AI_API_KEY || $env.OPENAI_API_KEY }}' },
            { name: 'Content-Type', value: 'application/json' },
          ],
        },
        sendBody: true,
        specifyBody: 'json',
        jsonBody: aiBody,
        options: {
          timeout: 30000,
          response: { response: { neverError: true } },
        },
      },
      id: 'b1a1c0de-0001-4000-8000-000000000003',
      name: '3. AI urgency',
      type: 'n8n-nodes-base.httpRequest',
      typeVersion: 4.2,
      position: [420, -80],
      // A provider outage must still notify the manager, with a line saying the AI
      // step failed, rather than silence.
      onError: 'continueRegularOutput',
      retryOnFail: true,
      maxTries: 2,
      waitBetweenTries: 1500,
    },
    {
      parameters: {
        mode: 'runOnceForAllItems',
        jsCode: buildMessageCode,
      },
      id: 'b1a1c0de-0001-4000-8000-000000000004',
      name: '4. Build message',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: [640, -80],
    },
    {
      parameters: {
        method: 'POST',
        url: '=https://api.telegram.org/bot{{ $env.TELEGRAM_BOT_TOKEN }}/sendMessage',
        sendHeaders: true,
        headerParameters: {
          parameters: [{ name: 'Content-Type', value: 'application/json' }],
        },
        sendBody: true,
        specifyBody: 'json',
        jsonBody: telegramBody,
        options: {
          timeout: 20000,
          response: { response: { neverError: true } },
        },
      },
      id: 'b1a1c0de-0001-4000-8000-000000000005',
      name: '5. Telegram',
      type: 'n8n-nodes-base.httpRequest',
      typeVersion: 4.2,
      position: [860, -80],
      // Telegram and the network are the two things that fail transiently most often.
      retryOnFail: true,
      maxTries: 3,
      waitBetweenTries: 3000,
    },
  ],
  connections: {
    'WC order.created': {
      main: [[{ node: '0. Verify signature', type: 'main', index: 0 }]],
    },
    '0. Verify signature': {
      main: [[{ node: '1. Extract order', type: 'main', index: 0 }]],
    },
    '1. Extract order': {
      main: [[{ node: '2. Already notified?', type: 'main', index: 0 }]],
    },
    // True branch only: an order already announced stops here.
    '2. Already notified?': {
      main: [
        [{ node: '3. AI urgency', type: 'main', index: 0 }],
        [],
      ],
    },
    '3. AI urgency': {
      main: [[{ node: '4. Build message', type: 'main', index: 0 }]],
    },
    '4. Build message': {
      main: [[{ node: '5. Telegram', type: 'main', index: 0 }]],
    },
  },
  settings: { executionOrder: 'v1' },
};

const out = join(here, 'workflow.json');
writeFileSync(out, JSON.stringify(workflow, null, 2) + '\n', 'utf8');
console.log('written:', out);
