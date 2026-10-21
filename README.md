# PolyPay for ACG-Faka

**English** · [简体中文](README.zh.md)

Native payment extension under `app/Pay/PolyPay/` and callback companion under `app/Plugin/PolyPayCallback/`. No existing host files or database schemas are changed. Uses PolyPay Hosted Checkout and the host's own order fulfillment and recharge services.

Status: **0.2.0 preview — adapter tests passed; real host acceptance is still required.** Source compatibility reviewed against ACG-Faka `ea4f4e6` (version 3.8.4). Older branches and forks are not implicitly supported.

## Integrate with AI

Paste this prompt into your AI coding tool (Claude Code, Cursor, Codex, etc.):

```text
Install and use the PolyPay integration Skill for ACG-Faka:
https://github.com/PolyPayAi/acg-faka-plugin/tree/master/skills/polypay-acg-faka

Then integrate PolyPay payments into this ACG-Faka project, configure the payment and callback plugins, and verify checkout creation and cancellation.
```

[View the Skill guide](skills/polypay-acg-faka/SKILL.md)

## Requirements and installation

1. Use a compatible ACG-Faka installation with curl, json, bcmath and sodium enabled in its PHP runtime. PHP 8+ is required by the host.
2. Extract the `manual-install` archive and copy both extension directories into the host. Enable PolyPay Callback in plugin management before configuring payments. Stop if either target already exists; do not overwrite existing files. All SDK dependencies are bundled; no host Composer changes are required.
3. Open payment plugin settings in the host admin and configure PolyPay: API Key, expected merchant ID, `usd_rate` (USD per CNY), and an absolute private `storage_dir` outside the public document root. Webhook verification requires the signed production audience. Do not put real credentials into this repository's `Config.php`.
4. The inspected host submits its CNY gateway amount even when the storefront uses another display currency. For example `usd_rate=0.14` converts CNY 10 to USD 1.40. Check this assumption on your exact version. The adapter persists the original gateway amount and USD conversion before calling PolyPay.
5. Add a payment interface with plugin `PolyPay`, payment code `PolyPay`, and enable product purchase and/or wallet recharge as desired. Give it the display name `PolyPay`.
6. The adapter derives the callback origin from the host and uses its new controller at `/plugin/PolyPayCallback/notification/callback.HOST_ORDER?kind=order` (or `kind=recharge`). The host convention resolves the new controller automatically; no route file is edited. Keep this endpoint accessible over HTTPS, including configured callback IP whitelist/WAF policy.

The payment profile provides a Force HTTPS (`force_https`) switch, enabled by default. Disable it (`0`) for LAN testing to permit HTTP storefront callback and return URLs without an origin allowlist. Keep it enabled (`1`) for production. PolyPay API and checkout URLs remain HTTPS, with production webhook verification unchanged. Public servers cannot directly deliver webhooks to a LAN IP, so LAN testing does not verify automatic fulfillment; browser returns never mark orders paid. The old `lan_http_origin` setting has been removed. After upgrading, explicitly disable Force HTTPS to continue HTTP testing.

The private store must be writable by the PHP worker and persist across upgrades. Back up it and the host payment configuration together. Use one instance; independently scaled stores are not supported. Restrict direct HTTP access to the host's `app/` directory as required by its deployment guide.

## Panel language

Configuration labels and extension descriptions follow the host panel language. Bundled native `Lang/*.json` packs cover Simplified Chinese, Traditional Chinese, English and Japanese. Store installation imports the packs through the host mechanism. After manual copying, run the host `Kernel\Util\Lang::scanExtensionPacks()` to import packs and rebuild dictionaries, then refresh the panel. Switching languages never changes configuration field names, API Keys or merchant settings.

## Docker deployment

On the reviewed ACG-Faka Docker image, `/var/www/html/app/Pay` is a symlink to the persistent `/data/pay` directory. Copy `PolyPay/` into that volume rather than mounting over the symlink. Use `/data/polypay-storage` for the private mapping directory, owned by the PHP worker (UID 33) with mode 0700.

The callback companion resides in the host's persistent plugin directory (`/data/plugins/PolyPayCallback` on the reviewed image). Both components persist in the data volume; no controller bind mount is required. When upgrading from 0.1.0, retain its old controller until outstanding callbacks have drained.

## Marketplace delivery

Register two store entries: payment extension `PolyPay` (type 1) and general extension `PolyPayCallback` (type 0). The packaging script emits install/update archives for each, plus manual install/update bundles. Store archives start at `Config/`; update archives omit configuration to preserve settings. Install defaults are generated instead of reading local configuration. See [MARKETPLACE.md](MARKETPLACE.md) for archive names, submission preparation and migration. Actual store submission and review have not been performed.

## Payment integrity

The server calls canonical merchant endpoints to create Hosted Checkout and query the PolyPay trade ID. The buyer selects payment methods on PolyPay. The official bundled PHP SDK verifies strict Ed25519 Webhook v2, its merchant/environment audience, freshness and replay protection. Only paid status 2 or admin-marked-paid status 7 is accepted; manual recharge status 5 does not authorize an ordinary purchase.

After verification, the adapter validates the stored USD amount, maps the PolyPay order to the host order, and passes the **original CNY gateway amount** to the host's native callback pipeline via its supported signature context. Do not compare token quantities with fiat prices. Browser returns never mark an order paid. The host retains transactional order checks, inventory, delivery and recharge idempotency.

The new controller calls the native transactional callback service, which invokes the plugin signature verifier. It returns HTTP 200 only after successful processing, or when that fresh authenticated delivery matches an already-paid host order. Other host business exceptions return non-2xx instead of the host's usual HTTP 200 error JSON, so PolyPay cannot mistake a rejected fulfillment for successful delivery. No host validation is bypassed.

## Cancellation and reconciliation

The native payment interface exposes order creation and signature verification, not a cancellation lifecycle hook. **Host closure/deletion is therefore not automatically synchronized to PolyPay.** The old checkout can remain payable until cancelled remotely or expired. Late notifications are processed or rejected by the host's own order rules and may need manual handling.

The repository includes a CLI tool (not copied into the host by the install ZIP). Export your matching settings to a private PHP file returning an array; set `POLYPAY_ACG_CONFIG` to it. From this source checkout run:

```bash
php bin/payment.php reconcile AC_MERCHANT_ORDER_ID
php bin/payment.php cancel AC_MERCHANT_ORDER_ID
```

The `AC...` merchant order ID is available in PolyPay's order details; it is not the storefront order number. Query and cancel run server-side using the API Key. Already-paid orders are never overwritten as cancelled. The tool reports payment state; fulfillment remains exclusively in the host's verified callback. A failed delivery must be corrected and retried through the merchant delivery workflow.

## Verification and packaging

```bash
php tests/run.php
php tests/controller.php  # PHP 8+ host HTTP boundary tests
php tests/trade.php
php tests/checkout-errors.php
php tests/https-switch.php
python3 tests/package.py  # extraction, update preservation and secret exclusion
python3 scripts/package.py
```

Tests mock API calls and never charge real funds. Before enabling customer traffic, verify order creation and cancellation without payment. With explicit authorization for a small real payment, verify a signed callback and exactly one product delivery / wallet credit, then test duplicate/invalid signatures, wrong amounts, changed rates, expired orders, cancellation races and late payments. This release has not completed real host end-to-end acceptance.

`Vendor/polypay` is the unmodified official PolyPay PHP SDK snapshot `d4f152b85bdd7c7ac210ded8a13eeecd8e3a9e09` from https://github.com/PolyPayAi/php-sdk, distributed with its MIT license. The snapshot is used for v2 verification. Canonical order HTTP calls preserve the complete checkout/trade mapping. Installation does not call PolyPay plugin activation: the current backend plugin-type allowlist does not include this platform yet.
