# WooCommerce Smart Order → Telegram

An order-notification pipeline: a new WooCommerce order is classified by an LLM for
delivery-time requests hidden in the free-form customer comment, then posted to the
manager's Telegram group.

Built as a working local stack (WordPress + WooCommerce + n8n in Docker), not a diagram.

```
WooCommerce  ──order.created──▶  n8n webhook
                                    │
                                    ├─ 1. Extract order      (Code)     name, phone, items, total, comment
                                    ├─ 2. AI urgency         (HTTP)     → OpenAI, JSON-mode classification
                                    ├─ 3. Build message      (Code)     HTML for Telegram, escaped
                                    └─ 4. Telegram           (HTTP)     → Bot API sendMessage
```

## The problem it solves

Order comments carry two very different things at once:

- `Подарункове пакування, якщо можливо` — fulfilment detail, no action needed.
- `Доставка після 18:00, зателефонувати за годину` — a delivery-time commitment that
  must reach a human before dispatch.

A manager reading a queue of orders at speed treats both the same way. The pipeline tells
them apart and puts a warning on the message when it matters.

## What the workflow actually does

**Node 1 — Extract (`n8n/workflow.json`, Code node).** Pulls `billing.first_name`,
`billing.last_name`, `billing.phone`, `line_items[]`, `total`, `currency` and
`customer_note` out of the WooCommerce `wp_api_v3` payload and normalises them into one
flat object. Everything downstream works with that object, not with the raw webhook body.

**Node 2 — AI classification (HTTP Request node).** Calls
`POST https://api.openai.com/v1/chat/completions` with:

- `temperature: 0` — this is classification, not writing; variance is a bug here.
- `response_format: { "type": "json_object" }` — forces a machine-readable answer instead
  of prose that has to be re-parsed.
- a system prompt that names the shape of an urgent request explicitly, with Ukrainian
  examples, and a fixed contract: `{"urgent": boolean, "note": string}`.

The node is configured `neverError: true` + `onError: continueRegularOutput`, so a provider
outage degrades the message rather than dropping the order.

**Node 3 — Build message (Code node).** Parses the model output defensively:

- strips a markdown fence if the model added one anyway;
- compares `parsed.urgent === true` strictly — the string `"false"` is truthy and would
  raise a false alarm;
- escapes `&`, `<`, `>` before interpolation, because the comment is customer-supplied
  text going into `parse_mode: HTML`.

Three message states are possible: urgent request, no urgent request, classifier
unavailable. Each is rendered differently, so the manager never mistakes a failed
classification for a clean comment.

**Node 4 — Telegram (HTTP Request node).** `sendMessage` to the configured group with
`parse_mode: HTML` and `disable_web_page_preview`, so a link in a customer comment does not
expand into a card in an operational chat.

## The interesting part: webhooks are asynchronous

WooCommerce does not POST the webhook during order creation. It queues an Action Scheduler
action (`woocommerce_deliver_webhook_async`), and the queue is drained by WP-Cron or by site
traffic. On a local site with neither, the action sits in `pending` — and the webhook
silently never fires. This cost real debugging time: the delivery action reported
`complete` while n8n never received anything.

Two consequences, both handled here:

1. `tools/wc-setup.php order` drains `action_scheduler_run_queue` explicitly after creating
   the order, so the demo is reproducible.
2. `responseMode: onReceived` on the n8n webhook — n8n answers 200 immediately instead of
   holding the request while the LLM call runs, which keeps WooCommerce's one-minute
   delivery timeout out of the picture.

## The second trap: `wp_safe_remote_post()`

`WC_Webhook::deliver()` calls `wp_safe_remote_request()`, which validates the destination
URL first. `wp_http_validate_url()` rejects:

- hosts resolving to loopback/private ranges, unless `http_request_host_is_external` allows
  them;
- ports outside `http_allowed_safe_ports` (80, 443, 8080).

A local n8n at `127.0.0.1:5678` fails both. `plugin/wso-local-webhook-transport.php` grants
exactly that destination and nothing more: the allowlist checks host **and** port **and**
path (`/webhook/`). The control case is tested — `http://127.0.0.1:9999/other` is still
refused with `A valid URL was not provided`.

A hosted n8n needs none of this file.

## Repository layout

```
plugin/wso-local-webhook-transport.php   mu-plugin: local-only transport allowlist
n8n/workflow.json                        importable n8n workflow
n8n/build-workflow.mjs                   generator — the workflow is authored as JS, not clicked
tools/wc-setup.php                       CLI: webhook | order | deliver | cron | list
scripts/deploy.ps1                       copies the mu-plugin into a WordPress tree
.env.example                             the three secrets the workflow reads
```

The workflow JSON is generated by `build-workflow.mjs`. Node positions, connection maps and
node ids are structural noise; the readable source is the script.

## Running it

Requires: WordPress + WooCommerce, PHP 8.0+, Docker, an OpenAI key, a Telegram bot.

```bash
# 1. n8n
docker run -d --name n8n -p 5678:5678 --env-file .env \
  -e N8N_SECURE_COOKIE=false -e N8N_BLOCK_ENV_ACCESS_IN_NODE=false \
  -v n8n_data:/home/node/.n8n n8nio/n8n:latest

# 2. import + activate
docker cp n8n/workflow.json n8n:/tmp/wf.json
docker exec n8n n8n import:workflow --input=/tmp/wf.json
docker exec n8n n8n list:workflow          # note the id
docker exec n8n n8n publish:workflow --id=<id>
docker restart n8n                         # required: a running instance ignores the publish

# 3. transport allowlist + webhook
.\scripts\deploy.ps1 -WordPressRoot D:\path\to\wordpress
php tools/wc-setup.php webhook http://127.0.0.1:5678/webhook/wc-order-created --root=D:\path\to\wordpress

# 4. trigger
php tools/wc-setup.php order "Доставка після 18:00, зателефонувати за годину" --root=D:\path\to\wordpress
```

`N8N_BLOCK_ENV_ACCESS_IN_NODE=false` is not optional: n8n 2.x denies `$env` access inside
nodes by default, and every credential reference in the workflow is an `$env` lookup.

## Verification status

Verified end to end on a local install, with the LLM key absent:

- WooCommerce order created → Action Scheduler drains → webhook delivered → n8n execution
  recorded;
- nodes 1 and 3 produce the expected output for real order payloads (customer, phone,
  `STEM Robotics Kit (x2), Interactive Smart Board (x1)`, `637.00 UAH`, comment);
- the message body renders correctly, and the "classifier unavailable" branch fires when
  the OpenAI key is missing (`401 You didn't provide an API key`);
- the transport allowlist grants the n8n endpoint and refuses other private destinations.

Not verified — stated plainly rather than implied:

- the classifier has never run against a live model response, so the `urgent: true` branch
  and the `note` rendering are untested at runtime;
- nothing has been delivered to a real Telegram group (no bot token at build time);
- no idempotency: WooCommerce retries delivery on failure, so a retried webhook means a
  duplicate Telegram message.

## Known gaps

- **Webhook signature is not verified.** WooCommerce signs the payload with
  `X-WC-Webhook-Signature` (HMAC-SHA256). Anything that reaches the webhook URL is currently
  trusted.
- **No retry policy.** A Telegram failure ends the run; the message is lost.
- **No deduplication**, as above.
- **Secrets live in an env file next to the workflow**, not in a secrets store.

## License

MIT for the code in this repository. n8n itself is distributed under the Sustainable Use
License — self-hosting for internal use is fine, reselling it as a service is not.
