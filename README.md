# WooCommerce Smart Order

<p align="center">
  <img src="docs/cover.svg" alt="WooCommerce Smart Order — order pipeline with LLM classification" width="880">
</p>

[![CI](https://github.com/MaksymK-web-dev/woocommerce-smart-order/actions/workflows/ci.yml/badge.svg)](https://github.com/MaksymK-web-dev/woocommerce-smart-order/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](composer.json)
[![WooCommerce](https://img.shields.io/badge/woocommerce-8.0%2B-96588a.svg)](wp-content/plugins/woocommerce-smart-order/woocommerce-smart-order.php)

A new WooCommerce order is inspected for delivery-time requests hidden in the free-form
customer comment, and the manager's Telegram group gets a message that says whether
anything needs attention.

Two interchangeable implementations ship here — the same logic once as a PHP plugin and
once as an n8n workflow — because the interesting part is the pipeline, not the tool.
In the deployment these screenshots come from, **n8n is the chain that runs**; the plugin
provides the settings screen and can take over by flipping one checkbox.

```mermaid
flowchart LR
    A[WooCommerce<br/>order.created] --> B[Extract order<br/>name, phone, items,<br/>total, comment]
    B --> C{Comment?}
    C -- no --> F[Format message]
    C -- yes --> D[LLM classification<br/>JSON: urgent, note]
    D --> F
    F --> G[Telegram group]
    D -. provider failure .-> H[AI unavailable line]
    H --> F
```

## The problem

Order comments carry two different kinds of text:

- `Подарункове пакування, якщо можливо` — a fulfilment detail.
- `Доставка після 18:00, зателефонувати за годину` — a delivery commitment that must
  reach a human before dispatch.

Read quickly, both look the same. The pipeline separates them, and it never lets a failed
classification look like a clean comment.

## Implementation 1 — WordPress plugin

`wp-content/plugins/woocommerce-smart-order/` — PHP 8.1+, `strict_types`, PSR-4 without a
Composer runtime dependency (the plugin has to run where `composer install` never ran).

```
woocommerce-smart-order.php   header + bootstrap, no logic
autoload.php                  PSR-4 loader for WSO\SmartOrder\
src/Plugin.php                composition root
src/Contract/                 Classifier, Notifier, HookRegistrar
src/Order/                    OrderPayload (readonly DTO), PayloadFactory, Pipeline
src/Ai/                       UrgencyResult, Prompt, ResponseParser, OpenAI + Anthropic adapters
src/Notify/                   Telegram, Log, factory
src/Hook/OrderListener.php    `woocommerce_new_order`
src/Admin/SettingsPage.php    Settings API screen under WooCommerce
src/Cli/TestOrderCommand.php  `wp smart-order test "..."`
src/Support/                  Logger, MessageFormatter
```

Design decisions worth naming:

- **Providers behind an interface.** `Classifier` has OpenAI and Anthropic adapters;
  switching provider is a setting, not a code change.
- **Three-state result, not two.** `UrgencyResult` distinguishes *not urgent* from
  *could not classify*. A manager must never mistake a failed API call for a clean comment.
- **Typed DTO at the boundary.** Everything after `PayloadFactory` works with
  `OrderPayload`, not `WC_Order` and not an array.
- **HPOS-safe.** Orders are read through `wc_get_order()` / `WC_Order` getters, never
  through the posts table.
- **Escaping at the edge.** Customer text is escaped before it enters Telegram's HTML mode,
  so a comment cannot break the message markup.

### The hook that fires too early

`woocommerce_new_order` fires inside the first `$order->save()` — before products,
addresses and the customer note exist. Reading the order there returns an empty object,
which is exactly what the first working version did: `No name (not provided)`,
`— — 0.00 UAH`. `OrderListener` therefore only collects ids and defers processing to
`shutdown`, when the order is complete and still in the same request.

### Configuration

Everything a shop manager needs lives at **WooCommerce → Smart Order**: enable, provider,
base URL, model, transport, group id, and the two credentials. Credentials can come from two
places:

1. **`wp-config.php` constants** — outside the database, outside git, wins over the screen:

```php
define( 'WSO_OPENAI_API_KEY', 'sk-...' );      // or WSO_ANTHROPIC_API_KEY
define( 'WSO_AI_API_KEY', 'AIza...' );        // Gemini and custom endpoints
define( 'WSO_TELEGRAM_BOT_TOKEN', '123:AA...' );
```

2. **The settings screen** — stored in the `wso_smart_order` option. Rotating a key is a
   form submit, not a deploy. The field never prints a stored value back: empty means
   *keep what is stored*.

Each secret shows where its effective value comes from (*not set* / *saved in the database* /
*set in wp-config.php — the stored value is ignored*), and **Test connection** proves it
before anything depends on it. The check is live but costs nothing: the provider's models
listing authenticates the key, and Telegram's `getMe` + `getChat` confirm the token and that
the bot can see the configured group. A key typed into the field is tested without saving
first.

### Any model, not one model

A provider is a base URL plus a model name, not a class:

- **OpenAI** — `https://api.openai.com/v1`
- **Google Gemini** — `https://generativelanguage.googleapis.com/v1beta/openai` (Gemini's
  own OpenAI-compatible endpoint)
- **Anthropic** — native messages API, kept separate because it does not speak `/chat/completions`
- **Custom endpoint** — any URL the customer runs: OpenRouter, Groq, DeepSeek, Mistral,
  vLLM, Ollama (`http://localhost:11434/v1`), LM Studio

The model field is free text, so `gpt-4o-mini`, `gemini-2.0-flash`, `llama3.2:3b` and
`deepseek-chat` all work without a code change. A model name carried over from another
provider would be rejected by the new one, so it is replaced by that provider's default.

Providers disagree about optional request parameters. When a provider rejects
`response_format` or `temperature`, the adapter drops that parameter and retries instead of
losing the notification, and it unwraps the `[{…}]` error envelope some gateways return so
the admin sees the provider's own wording.

A provider with a genuinely different protocol can be supplied from another plugin through
the `wso_smart_order_classifier` filter.

With transport set to *Log only* the pipeline is fully observable in
**WooCommerce → Status → Logs** (`woocommerce-smart-order` source) without any credentials —
that is the default, so a fresh install never fails silently.

![The settings screen: per-provider key status, and both live checks passing](docs/screenshots/settings.png)

### One chain per order

The plugin and the n8n workflow are two implementations of the same delivery. Both fire on
the same event, so running both means two messages per order. *Enable processing* is the
switch: it is **off** in this deployment because n8n delivers, and the plugin then does
nothing to the order. The settings screen also looks for active `order.created` webhooks
and warns, naming them, when both chains are live.

## Implementation 2 — n8n workflow

`n8n/workflow.json` (generated by `n8n/build-workflow.mjs`) runs the same steps visually:

```
webhook → verify signature → extract → already notified? → LLM → build → Telegram
```

Three of those nodes are what separate this from a demo:

- **`0. Verify signature`** recomputes the HMAC-SHA256 of the raw request body with the
  store's webhook secret and compares it in constant time. WooCommerce signs every
  delivery with `X-WC-Webhook-Signature`; without this check anyone who knows the URL can
  POST a fake order. A mismatch throws before the AI or Telegram steps are reached.
- **`2. Already notified?`** keeps a claim per order id in the workflow's static data. A
  repeated delivery of the same order stops there — no second message. The claim expires
  after ten minutes, so a delivery that genuinely failed and was retried later is still
  announced.
- **`3. AI urgency`** retries once and, if the provider is still down, passes the order
  through with a line saying the classification failed. **`5. Telegram`** retries three
  times.

The AI node takes its endpoint, key and model from the environment, so the same workflow
runs on OpenAI, Gemini, Groq, DeepSeek or a local server — the same "a provider is a base
URL plus a model" idea as the plugin:

```bash
# .env.example lists every variable; keep the filled copy outside the web root.
AI_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai
AI_API_KEY=AIza...
AI_MODEL=gemini-2.5-flash
WC_WEBHOOK_SECRET=<the webhook's Secret field>
N8N_ENCRYPTION_KEY=<fixed, and kept outside the container>
```

### Running it

`n8n/docker-compose.yml` is the deployment: `unless-stopped` restart, a healthcheck,
log rotation, execution pruning, and the port bound to `127.0.0.1` only.

```bash
export WSO_N8N_ENV_FILE=~/.config/wso/n8n.env
docker compose -f n8n/docker-compose.yml up -d

docker cp n8n/workflow.json n8n:/tmp/wf.json
docker exec n8n n8n import:workflow --input=/tmp/wf.json
docker exec n8n n8n list:workflow                    # note the id
docker exec n8n n8n publish:workflow --id=<id>
docker restart n8n                                   # a running instance ignores the publish
```

Two settings in there are not optional. `NODE_FUNCTION_ALLOW_BUILTIN=crypto` is what lets
the signature node reach `require('crypto')`; without it the sandbox answers *Module
'crypto' is disallowed*. `N8N_ENCRYPTION_KEY` must be fixed, or credentials stored by n8n
become unreadable after moving the volume.

The **Crypto** node looks like the natural fit for the HMAC check but reads its secret from
n8n's credential store, not from a parameter, so it cannot be configured from the workflow
file alone. The code node keeps the secret in the same env file as everything else.

## Two traps specific to a local n8n

**WooCommerce delivers webhooks asynchronously.** `woocommerce_deliver_webhook_async` is
queued through Action Scheduler and drained by WP-Cron or site traffic. On a local site
with neither, the action sits in `pending` and nothing is ever sent. `tools/wc-setup.php
order` drains the queue explicitly for that reason.

**`wp_safe_remote_post()` refuses the destination.** `wp_http_validate_url()` rejects
loopback/private hosts and ports outside `http_allowed_safe_ports` (80, 443, 8080), so
`127.0.0.1:5678` fails twice. `wp-content/mu-plugins/wso-local-webhook-transport.php`
allows exactly that host, port and `/webhook/` path — parsed from the URL, not matched as a
substring — and nothing else: `http://127.0.0.1:9999/other` and `http://example.com:5678/`
are still refused. A hosted n8n needs none of it.

## Security

What is enforced, and where:

| Concern | Where it lives |
| --- | --- |
| API keys, bot token | file outside the web root, loaded as constants from `wp-config.php` |
| Webhook authenticity | HMAC-SHA256 of the raw body, compared in constant time |
| Duplicate announcement | per-order claim in the workflow's static data |
| Dashboard code editing | `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS` |
| Admin over TLS | `FORCE_SSL_ADMIN` |
| Username enumeration | one neutral message for every failed login |
| Dotfiles (`.git`, `.codewhale`, `.env`) | `deny all` in the host's nginx config |
| PHP in `wp-content/uploads` | `deny all` for `*.php`, `*.phtml`, `*.phar` |
| n8n editor | port bound to `127.0.0.1`, restart policy, healthcheck |
| Execution table growth | n8n pruning, 14 days |
| Log growth | Docker log rotation, 10 MB × 3 |

Two of these were real holes in this install, not hypothetical:

- **`.git/config` and `.codewhale/handoff.md` answered `HTTP 200`.** Anyone on the
  network could fetch the whole repository history and the deployment notes. The rules
  live in `.osp/nginx/woocommerce-smartorder.loc.conf` — OSPanel includes that file
  inside the host block, so they survive regeneration of `nginx.conf`, which is
  generated and must not be edited by hand. Both now answer `403`.
- **The webhook accepted unsigned requests.** A `POST` to the webhook URL with any body
  created an order announcement for a customer who does not exist.

Secrets are deliberately **not** in the database. `wp-config.php` loads
`~/.config/wso/wp-secrets.php`, which defines `WSO_AI_API_KEY` and
`WSO_TELEGRAM_BOT_TOKEN`; the settings screen then reports them as
*set in wp-config.php* and refuses to store a typed key that the constant would shadow.
The only settings left in the database are non-secret: provider, model, transport, group
id.

The blocks this install needs in `wp-config.php`, before *stop editing*:

```php
$wso_secrets = '/path/to/wp-secrets.php';   // outside the web root
if ( is_readable( $wso_secrets ) ) {
	require_once $wso_secrets;                                 // WSO_AI_API_KEY, WSO_TELEGRAM_BOT_TOKEN
}

define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'FORCE_SSL_ADMIN', true );
```

## Getting started

```bash
composer install                                    # dev tools: phpcs, phpunit

composer cs                                         # WordPress coding standards
composer test                                       # unit tests
composer check                                      # both
```

The plugin folder is meant to be dropped into `wp-content/plugins/` of a WordPress
install with WooCommerce active. The unit tests do not need WordPress: a small bootstrap
stubs the handful of global functions the covered classes touch.

## Repository layout

```
.github/workflows/ci.yml      lint 8.1–8.4, coding standards, tests, workflow JSON check
wp-content/plugins/woocommerce-smart-order/   the plugin
wp-content/mu-plugins/wso-local-webhook-transport.php   local n8n transport shim
n8n/workflow.json             importable workflow
n8n/build-workflow.mjs        workflow source (authored as JS, not clicked)
n8n/docker-compose.yml        the deployment: restart, healthcheck, loopback-only port
tools/wc-setup.php            CLI: webhook | order | deliver | cron | list
tests/                        PHPUnit unit tests
.env.example                  secrets n8n reads
```

The working tree doubles as the site's document root on the author's machine, so
`.gitignore` is deny-by-default and re-includes only the paths above.

## Verification status

Verified on a local WordPress 7.1.4 + WooCommerce 11.3.0 + PHP 8.4 install:

- plugin activates, HPOS compatibility declared, `php -l` clean on every file;
- **a real order reached a real Telegram group.** Order #47 (`Доставка після 18:00,
  зателефонувати за годину`) was classified by Gemini through the pipeline and delivered:
  `HTTP 200 ok=true message_id=15`, chat «Test Group». Order #48 (`Гарний сайт, дякую`)
  was delivered without the flag line, as *no urgent delivery request found*;
- classification runs in both directions against the live model: a delivery-time request
  is `urgent: true`, a compliment or a packaging note is `urgent: false`;
- 115 unit tests over the parser (fenced JSON, `{"urgent": "false"}`, prose, arrays,
  missing keys), the formatter (three branches, markup escaping, double-escaping), the
  prompt contract, per-provider key storage, the base-URL validator, key-prefix
  detection, the settings markup, and the credential checks (missing key, provider
  mismatch, provider error, transport failure, unreachable Telegram group);
- live checks in the browser: both *Test connection* buttons report the provider's own
  wording, and a Google key under the OpenAI provider is named as a mismatch instead of
  being sent;
- the settings form was driven through Chrome DevTools: saving a key-shaped value into
  the custom base-URL field is refused with a visible notice and nothing is stored;
- the duplicate-delivery warning fires on the settings screen for the real
  `order.created` webhook and stays silent on every other screen;
- coding standards clean under WordPress rules, adapted for PSR-4 as documented in
  `phpcs.xml.dist`;
- **the n8n chain ran end to end** against Gemini: order → Action Scheduler → webhook →
  verify → extract → Gemini (`gemini-2.5-flash`, `finish_reason: stop`) → Telegram
  `ok: true`, `message_id: 20` in «Test Group». A comment with a delivery-time request
  produced «⚠️ ШІ-позначка», a compliment produced «✅ ШІ: термінових вимог щодо
  доставки не знайдено»;
- **the signature check rejects what it should.** An unsigned POST and a POST with a
  garbage `X-WC-Webhook-Signature` both stopped at `0. Verify signature`; no AI call and
  no Telegram call followed. A real delivery through WooCommerce passed;
- **a duplicate delivery is suppressed.** Delivering order 55 twice produced one message:
  the second execution reported `isNew=false` and ended at `2. Already notified?`;
- **the AI outage degrades instead of failing.** With a revoked key the workflow still
  delivered the order and said «ШІ недоступний — перевірте коментар вручну»;
- the deployment runs from `n8n/docker-compose.yml` with restart `unless-stopped`, a
  passing healthcheck, the port bound to `127.0.0.1`, and `NODE_FUNCTION_ALLOW_BUILTIN`
  limited to `crypto`.

Not verified — stated rather than implied:

- the claim that guards against duplicates lives in n8n's static data; it survives
  restarts of the container but not deletion of the `n8n_data` volume, so a restored
  volume would re-announce one recent order.

## Known gaps

- **Duplicates on the plugin path.** The n8n workflow suppresses a repeated delivery; the
  plugin, when enabled, does not.
- **No signature check for the plugin path**, because it never leaves the site.
- **Webhook secret rotation** is manual: change it on the webhook and in the env file, then
  restart n8n.
- **Synchronous LLM call** at `shutdown`: a slow provider delays the response. It belongs
  in an async job before high-traffic use.

## License

MIT — see [LICENSE](LICENSE). n8n itself is distributed under the Sustainable Use License:
self-hosting for internal use is fine, reselling it as a service is not.
