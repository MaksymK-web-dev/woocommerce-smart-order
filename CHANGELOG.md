## [Unreleased]

### Added

- Repository scaffolding: MIT license, CI (lint on PHP 8.1–8.4, coding standards, tests,
  workflow JSON regeneration check), editorconfig.
- **Webhook signature verification.** The n8n workflow recomputes the HMAC-SHA256 of the
  raw request body with the store's webhook secret and compares it in constant time
  before anything else runs. An unsigned or wrongly signed POST is rejected; previously
  anyone who knew the URL could inject a fake order.
- **Duplicate suppression.** A claim per order id in the workflow's static data stops a
  repeated delivery from announcing the same order twice. The claim expires after ten
  minutes, so a delivery that genuinely failed and was retried later still goes out.
- **Retries.** The AI node retries once and degrades to a message saying the
  classification failed; Telegram retries three times.
- `n8n/docker-compose.yml`: restart `unless-stopped`, a healthcheck, log rotation,
  execution pruning, the port bound to loopback only, and `NODE_FUNCTION_ALLOW_BUILTIN`
  limited to `crypto` — without which the signature node cannot reach `require('crypto')`.
- `N8N_ENCRYPTION_KEY` and `WC_WEBHOOK_SECRET` in `.env.example`, with the reason each is
  required.

### Changed

- The n8n AI node reads `AI_BASE_URL`, `AI_API_KEY` and `AI_MODEL` from the environment
  instead of a hardcoded `api.openai.com` URL, so one workflow runs on OpenAI, Gemini,
  Groq, DeepSeek or a local server. `OPENAI_API_KEY` is still honoured as a fallback.
- The workflow reads the raw webhook body from `binary.data`: `rawBody` puts the untouched
  bytes there, not in `json.body`, so the signature covers exactly what the store signed.

### Fixed

- `tools/wc-setup.php deliver <id> --root=…` read the `--root` flag as the webhook id and
  failed with *Webhook not found*. Flags are now stripped before positional arguments are
  read.

### Security

- **Dotfiles are no longer served.** `.git/config` and `.codewhale/handoff.md` answered
  `HTTP 200`, which exposed the whole commit history and the deployment notes. The rules
  are in `.osp/nginx/woocommerce-smartorder.loc.conf`, the file OSPanel includes inside
  the host block, so they survive regeneration of `nginx.conf`. Both now answer `403`.
- **PHP cannot run in `wp-content/uploads`**: `*.php`, `*.phtml`, `*.phar` are denied at
  the web server, and directory listing is off.
- **Secrets left the database.** `wp-config.php` loads `~/.config/wso/wp-secrets.php`
  from outside the web root and defines `WSO_AI_API_KEY` / `WSO_TELEGRAM_BOT_TOKEN`. The
  option now holds only non-secret settings.
- **`DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS`** are set, so a compromised admin
  account cannot edit plugin code from the dashboard.
- **`FORCE_SSL_ADMIN`** is set.
- **Username enumeration closed**: "unknown username" and "wrong password" now produce
  the same message (`wp-content/mu-plugins/wso-login-hardening.php`).
- The settings screen refuses a typed key when a `wp-config.php` constant shadows it,
  instead of storing a secret that would never be used.
## [1.2.0] — 2026-11-18

### Added

- **A provider is a base URL plus a model, not a class.** OpenAI, Google Gemini (through its
  OpenAI-compatible endpoint), Anthropic's native protocol, and a *Custom endpoint* for
  OpenRouter, Groq, DeepSeek, Mistral, vLLM, Ollama or LM Studio. The model field is free
  text, so using a different model needs no code change.
- **One API key per provider.** Keys are stored as a `provider => key` map, so switching the
  provider no longer sends the previous service's key to the new one — the failure that
  produced *Incorrect API key provided* from OpenAI for a Google key. An install upgrading
  from the single-key version keeps its key under the provider it was stored with.
- **`Config\Draft`** — the configuration a form describes, resolved without writing anything.
  *Test connection* now checks the provider, base URL, model, key and chat id **as they
  appear on screen**, not as they were last saved.
- **`Support\Url`** — strict base-URL validation: `http`/`https` only, a host with a dot or
  localhost/IP, no query or fragment. A credential pasted into that field is rejected
  instead of becoming `http://<key>`.
- **`Ai\KeyPrefix`** — recognises `AIza` (Gemini), `sk-ant-` (Anthropic) and `sk-` (OpenAI).
  A key belonging to another service is named in one sentence, and no request is wasted.
- Optional request parameters degrade instead of failing: a provider that rejects
  `response_format` or `temperature` gets the request retried without it.
- `wso_smart_order_classifier` filter, so a provider with its own protocol can be supplied
  from another plugin.
- `JsonBody` unwraps the one-element array envelope some gateways (Gemini) return around
  error bodies, so the admin sees the provider's own message instead of `HTTP 400`.
- A model name carried over from another provider is replaced by the current provider's
  default rather than sent and rejected.
- A wrong model is named on a 404, because *HTTP 404* alone says nothing actionable.
- A warning at save time when the transport is Telegram but the group id is empty, since
  messages would otherwise be written to the log and never sent.

### Changed

- `OpenAiClassifier` becomes `OpenAiCompatibleClassifier` and takes a base URL.
- `WSO_AI_API_KEY` is the constant for Gemini and custom endpoints.
- `autoload.php` refuses direct access and rejects `..` in a class name; it uses `return`
  rather than `exit`, so a process that includes it is not killed.

### Fixed

- The settings screen printed the AI-key summary under the bot-token field.
- A rejected base URL disappeared without a word; `settings_errors()` now renders it.
## [1.1.0] — 2026-11-18

### Added

- **Test connection** buttons on the settings screen. Each one performs a live, free check:
  the AI providers' models listing authenticates the key, Telegram's `getMe` + `getChat`
  confirm the token and that the bot can see the group. A value typed into the field is
  tested before it is saved.
- The screen reports where each credential comes from — *not set*, *saved in the database*,
  or *set in wp-config.php — the stored value is ignored*.
- Warning on the settings screen when the plugin is enabled and a WooCommerce
  `order.created` webhook is also active: two chains mean two messages per order.
- Help text for every field, including the reason to switch processing off when n8n owns
  the delivery.

### Changed

- Credentials may now be stored in the database through the settings screen. Constants in
  `wp-config.php` still take priority when present, so production can keep secrets outside
  the database without a code change.
- `ConnectionTesterTest` and `SettingsTest` added; the suite is 60 tests.

## [1.0.0] — 2026-11-17

### Added

- **WordPress plugin** (`wp-content/plugins/woocommerce-smart-order`): classifies the
  customer comment on a new order and notifies the manager.
  - `Classifier` contract with OpenAI and Anthropic adapters; provider is a setting.
  - `UrgencyResult` distinguishes *not urgent* from *could not classify*.
  - `OrderPayload` readonly DTO; orders are read through `wc_get_order()` (HPOS-safe).
  - `OrderListener` defers to `shutdown` because `woocommerce_new_order` fires before
    products and the customer note are attached.
  - Settings screen under WooCommerce; secrets read from `wp-config.php` constants.
  - `wp smart-order test "..."` CLI command for a real end-to-end run.
- **n8n workflow** (`n8n/`): the same pipeline as a four-node workflow, generated from
  `build-workflow.mjs`.
- **Local transport shim** (`wp-content/mu-plugins`): allows `wp_safe_remote_post()` to
  reach `127.0.0.1:5678` — host, port and path checked, nothing else allowed.
- **CLI driver** (`tools/wc-setup.php`): create webhook, create order, drain Action
  Scheduler, re-deliver a webhook.

### Known limitations

- No live model response has been classified yet; the urgent branch is covered by unit
  tests, not by an API round-trip.
- Telegram delivery has not been exercised against a real bot.
- No idempotency or retry policy.
