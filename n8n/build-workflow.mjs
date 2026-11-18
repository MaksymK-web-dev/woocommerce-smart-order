// Builds the n8n workflow JSON for the WooCommerce → AI → Telegram chain.
// Run: node n8n/build-workflow.mjs
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

const extractCode = `
// Node 1: pull the fields the message needs out of the webhook payload.
const order = $json.body || $json;
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

return [{
  json: {
    orderId: order.number || order.id || '—',
    customer: name,
    phone: phone || 'не вказано',
    itemsText,
    totalText,
    comment,
    hasComment: comment.length > 0,
  },
}];
`.trim();

const buildMessageCode = `
// Node 3: read the AI answer and build the HTML message for Telegram.
const d = $('1. Extract order').first().json;

let aiOk = false;
let urgent = false;
let note = '';
try {
  const ai = $('2. AI urgency').first().json;
  let content = ai?.choices?.[0]?.message?.content;
  if (typeof content === 'string' && content.trim()) {
    content = content.replace(/^\\\`\\\`\\\`(?:json)?/i, '').replace(/\\\`\\\`\\\`$/, '').trim();
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
        options: {},
      },
      id: 'b1a1c0de-0001-4000-8000-000000000001',
      name: 'WC order.created',
      type: 'n8n-nodes-base.webhook',
      typeVersion: 2,
      position: [-260, 0],
      webhookId: 'wc-order-created',
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
      position: [-40, 0],
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
      name: '2. AI urgency',
      type: 'n8n-nodes-base.httpRequest',
      typeVersion: 4.2,
      position: [180, 0],
      onError: 'continueRegularOutput',
    },
    {
      parameters: {
        mode: 'runOnceForAllItems',
        jsCode: buildMessageCode,
      },
      id: 'b1a1c0de-0001-4000-8000-000000000004',
      name: '3. Build message',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: [400, 0],
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
      name: '4. Telegram',
      type: 'n8n-nodes-base.httpRequest',
      typeVersion: 4.2,
      position: [620, 0],
      onError: 'continueRegularOutput',
    },
  ],
  connections: {
    'WC order.created': {
      main: [[{ node: '1. Extract order', type: 'main', index: 0 }]],
    },
    '1. Extract order': {
      main: [[{ node: '2. AI urgency', type: 'main', index: 0 }]],
    },
    '2. AI urgency': {
      main: [[{ node: '3. Build message', type: 'main', index: 0 }]],
    },
    '3. Build message': {
      main: [[{ node: '4. Telegram', type: 'main', index: 0 }]],
    },
  },
  settings: { executionOrder: 'v1' },
};

const out = join(here, 'workflow.json');
writeFileSync(out, JSON.stringify(workflow, null, 2) + '\n', 'utf8');
console.log('written:', out);
