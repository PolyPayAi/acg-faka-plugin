---
name: polypay-acg-faka
description: Install, configure, upgrade, or troubleshoot the PolyPay payment extension and PolyPayCallback companion in ACG-Faka using PolyPayAi/acg-faka-plugin. Use for ACG-Faka purchases, wallet recharge, panel language packs, HTTPS configuration, verified callbacks, and payment reconciliation.
---

# PolyPay for ACG-Faka

Integrate through the host payment-extension and companion-plugin mechanisms. Both modules are required: `app/Pay/PolyPay/` and `app/Plugin/PolyPayCallback/`.

## Locate the maintained source

Use the user's plugin checkout and host installation. If source is absent, retrieve `https://github.com/PolyPayAi/acg-faka-plugin` into a new task directory. This integration and skill are maintained on `master`; inspect the current repository documentation before selecting a revision. Do not overwrite a populated directory.

Paths below are relative to the **plugin source checkout**, not this installed skill. Read its `README.md` or `README.zh.md` for the supported host version and deployment instructions. Read `MARKETPLACE.md` only for store packages or their update layout. Inspect the actual host revision and any local instructions; forks are not automatically compatible.

## Install or upgrade the modules

- Check PHP 8+ and the curl, json, bcmath, and sodium extensions. SDK dependencies are already bundled; do not modify host Composer dependencies to install this extension.
- For manual installation, copy both modules to their actual persistent host locations. Resolve container symlinks/mounts before copying: changing an ephemeral container path can disappear at recreation. Preserve existing files, payment configuration, and mapping storage when upgrading.
- Enable **PolyPay Callback** in plugin management. Then configure **PolyPay** in payment-plugin settings and create a payment interface with plugin/code `PolyPay`; enable purchase and/or recharge as requested.
- Store delivery has separate payment-plugin and callback-plugin packages. Use the documented install/update archives: update packages intentionally omit `Config/Config.php`. Do not overwrite a live merchant configuration with an install template.
- Configuration labels follow panel languages `zh-cn`, `zh-tw`, `en`, and `ja`. Store installation imports language packs; manual installation must call the host's `Kernel\Util\Lang::scanExtensionPacks()` through the existing bootstrap and rebuild its dictionaries as documented.
- The extension does not require changes to existing host source, route files, or database schema. If a fork is incompatible, identify the exact difference before proposing host edits; record any authorized edits separately.

## Configure private merchant settings

Use `api_key`, `merchant_id`, `usd_rate`, `storage_dir`, and `force_https` in the host payment configuration. Obtain credentials from the user's private source, check their validity and expected merchant, and never commit them to the repository's `Config.php` or place them in CLI arguments.

- On the supported host, the gateway amount is CNY even when the storefront displays another currency. `usd_rate` is USD per CNY. Check this behavior on the installed version; avoid double conversion. The extension snapshots the original amount and USD total before checkout.
- `storage_dir` must be an absolute persistent path outside the public document root, writable only by the PHP worker and administrator. Preserve it with the payment configuration. This adapter uses single-instance local mappings; do not independently scale its store.
- HTTPS is enabled by default. Set `force_https=0` only for an explicitly selected HTTP test environment; it does not change production API/checkout HTTPS or signature verification. There is no Sandbox mode.
- Callback URLs are derived by the extension: `/plugin/PolyPayCallback/notification/callback.<order>?kind=order`, or `kind=recharge`. Ensure the public origin and host callback policy permit delivery. Public senders cannot reach private LAN addresses; a browser return never authorizes delivery.

## Reconcile, cancel, and troubleshoot

The host extension interface has no cancellation lifecycle hook. Closing or deleting a host order therefore does **not** automatically cancel its PolyPay checkout. Make this limitation explicit when it affects the user's requested workflow; do not claim automatic synchronization.

For an authorized query/cancellation, export the selected payment configuration to a private PHP file returning an array, set `POLYPAY_ACG_CONFIG` to its path, and use the **source checkout's** `bin/payment.php`:

```bash
php bin/payment.php reconcile AC_ORDER_ID
php bin/payment.php cancel AC_ORDER_ID
```

The CLI is not installed into the host extension archives. Use the mapped merchant-attempt number, not an unrelated display ID. Cancellation queries before and after the request; paid success wins. Reconciliation alone does not replace verified host callback fulfillment. Correct failed deliveries through the documented merchant delivery workflow with appropriate authorization.

For payment errors, correlate the host order, durable mapping, gateway response, and PHP controller result. Preserve Ed25519 v2 verification, production merchant audience, freshness, replay prevention, locked USD amount, and native transactional delivery/recharge. Accept paid statuses `2`/`7`, not manual recharge `5`. Return non-2xx for failed callback processing; never fake a paid state to make a test pass.

## Verify and report

For source changes, run the repository's PHP adapter/controller/trade/checkout/HTTPS tests and `python3 tests/package.py`; generate archives with `python3 scripts/package.py`. Do not deploy source changes to an unauthorized environment.

For an authorized runtime check, exercise a clearly labelled host test product or recharge flow, verify the resulting HTTPS checkout and stored amount snapshot, and cancel the unpaid test attempt without charging funds. Verify callback delivery/fulfillment only when that scenario is authorized and reachable. Keep any pre-upgrade callback controller needed for existing orders until those attempts are resolved.

Report installed module paths, configuration and panel-language status, package/source revisions, checks, cancellation limitations, and the exact unverified payment steps. Do not equate successful checkout creation with successful payment or automatic delivery.
