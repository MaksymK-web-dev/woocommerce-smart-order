# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Repository scaffolding: MIT license, CI (lint on PHP 8.1–8.4, coding standards, tests,
  workflow JSON regeneration check), editorconfig.

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
