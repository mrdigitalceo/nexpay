# NexPay — Compatibility & Test Evidence

This page is deliberately honest: it lists what has actually been
verified, where, and what is still pending. Claims in the README and
plugin headers are kept in sync with this page.

## Declared requirements

| Component | Declared | Notes |
|---|---|---|
| WordPress | 6.0+ | From plugin header / readme |
| WooCommerce | 7.0+ | From plugin header (`WC requires at least`) |
| PHP | 7.4+ | Plugin code avoids PHP 8-only syntax; CI lints 7.4 |

## Verified so far

| Check | Environment | Result |
|---|---|---|
| PHP syntax (`php -l`, all files) | PHP 8.3.6, local | ✅ Pass (v1.0.0 and v1.1.0) |
| Unit tests (`tests/run-tests.php`) | PHP 8.3, local, WP/WC stubs | ✅ Pass — hash/signature vectors, postback verification accept/reject matrix, amount/currency rules, UPI URI, phone normalization, Nagad matching, logger redaction |
| Activation + gateway registration | WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.3.6 (external review smoke test, v1.0.0) | ✅ All six gateways registered |
| Checkout fields render (classic) | Same review environment | ✅ Per-gateway fields rendered |
| Currency-based availability | Same review environment | ✅ PKR/BDT/INR/KES show matching gateways; USD shows none |
| Admin overview page | Same review environment | ✅ HTTP 200, six gateway rows |
| **v1.1.0 runtime test** (activation, settings save + missing-credential validation, admin overview) | WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.3.6, local test site, 2026-11-03 | ✅ Pass — zero NexPay entries in debug.log |
| **v1.1.0 classic checkout + currency gating** | Same local test site | ✅ Pass — PKR→JazzCash+Easypaisa, BDT→bKash+Nagad, INR→UPI, KES→M-Pesa, USD→none; JazzCash fields render |
| **v1.1.0 UPI order end-to-end** (classic checkout) | Same local test site | ✅ Pass — order created on-hold with correct `upi://` intent URI (pa/am/cu=INR/tr) in order meta |
| **v1.1.0 forged-callback rejection** | Same local test site | ✅ Pass — forged Easypaisa postback (old unkeyed hash) → HTTP 400, order unpaid; tampered JazzCash IPN hash → HTTP 400, order unpaid |
| Cart/Checkout Blocks | Implemented in **v1.1.0** (real Blocks payment-method integration for all six gateways) | ✅ All six method types verified registered in the Blocks `PaymentMethodRegistry`, currency-gated exactly like classic checkout; block checkout page loads clean. ⚠️ In-browser visual rendering / full Blocks order placement not yet exercised — recommended before relying on it |
| HPOS | Compatibility declared | ✅ Order creation verified on an HPOS-enabled store (WC 11 default) in the v1.1.0 runtime test |
| PHP 7.4 runtime | — | ⚠️ Lint-level only (CI); not yet run end-to-end on 7.4 |

## Pending (requires merchant sandbox credentials)

Provider sandbox end-to-end tests need real sandbox merchant accounts
and a publicly reachable HTTPS callback URL, which the project does not
currently have. For **each** API gateway the following are still open:

- One successful sandbox payment completing exactly the right order,
  exactly once.
- One failed/cancelled sandbox payment leaving the order unpaid.
- Callback negative tests against the live sandbox endpoint (tampered
  hash, wrong amount, replay).

Until those pass for a given gateway, treat that gateway as
**implemented + unit-tested, sandbox E2E pending** (see the
"Verification status" table in the README).

## Support policy

- NexPay aims to support the declared minimums above plus the current
  WordPress/WooCommerce/PHP releases. CI (`.github/workflows/ci.yml`)
  lints every PHP file on PHP 7.4, 8.1, 8.2 and 8.3 and runs the unit
  suite on 8.1+.
- If a minimum version stops passing, the header/readme requirements
  will be raised rather than silently keeping an untested claim.
