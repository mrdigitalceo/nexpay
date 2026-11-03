# Changelog

All notable changes to NexPay are documented here.

## 1.1.0 — Production-readiness hardening

### Security (P0)
- **Easypaisa:** replaced the forgeable unkeyed SHA-256 postback hash
  with the keyed merchant hash (new **Merchant Hash Key** setting).
  Postbacks now also validate store id, order reference, exact amount
  and currency, compare in constant time, and fail closed when no Hash
  Key is configured. Processing is idempotent per provider transaction
  reference. **Action required:** existing Easypaisa users must enter
  their Merchant Hash Key after upgrading, or postbacks will be
  rejected.
- **M-Pesa:** success callbacks are now confirmed server-to-server with
  the Daraja **STK Push Query** API before an order is paid. Callback
  amount must match the order total; the M-Pesa receipt is stored and
  can never pay a second order. Failure/cancel codes map to clear order
  notes.
- **Nagad:** verification responses must now match merchant, order
  reference and exact amount (not just `status === Success`); missing
  fields fail closed. The sandbox endpoint now uses **HTTPS**.

### Correctness (P1)
- **JazzCash:** IPN now also requires the exact amount (paisa) and PKR
  currency to match the order; transaction references are stored and
  duplicates ignored.
- **bKash:** execute responses are fully verified (status, Completed
  state, trxID, amount, BDT currency) and a successful execute can never
  run twice for the same payment.
- **Settings validation:** saving an enabled gateway with missing
  required credentials now produces a clear admin error (field labels
  only, never secrets). Secret fields use password inputs.
- **Checkout validation:** stricter server-side formats — JazzCash CNIC
  exactly 6 digits; PK/BD mobile formats; UPI VPA checked again in
  `process_payment()`.
- **Logging:** new `NexPay_Logger` redacts credential-looking keys from
  everything written to the WooCommerce log. All provider HTTP calls
  use a bounded 30s timeout.

### Features
- **WooCommerce Blocks:** real Cart/Checkout Blocks payment-method
  integration for all six gateways (previously only a compatibility
  declaration). Block checkout field values are copied onto the order
  via the Store API, so `process_payment()` works unchanged.
- **UPI:** removed the misleading "QR" wording — customers get a UPI
  intent link, and the docs now spell out the manual verification
  workflow (orders stay on hold until the merchant verifies).

### Compatibility & docs
- Admin warning (no fatal) when the PHP OpenSSL extension is missing,
  since Nagad requires it.
- Added `docs/SETUP.md`, `docs/SECURITY.md`,
  `docs/COMPATIBILITY.md`, a dependency-free unit test suite
  (`tests/run-tests.php`) and a GitHub Actions CI workflow
  (PHP 7.4–8.3 lint + tests).

## 1.0.0 — Initial release

- JazzCash (Pakistan) — MWALLET API, HMAC-SHA256 secure hash, IPN handler.
- Easypaisa (Pakistan) — hosted payment page, hash-verified postback.
- bKash (Bangladesh) — tokenized checkout (grant/create/execute).
- Nagad (Bangladesh) — RSA-signed initialize/complete checkout + verification API.
- UPI (India) — intent deep link, on-hold flow.
- M-Pesa (Kenya) — Daraja OAuth + STK Push + callback handler.
- WooCommerce → NexPay admin overview page.
- HPOS + Cart/Checkout blocks compatibility declarations.
