# NexPay — Security Notes

This document describes how NexPay decides whether a payment is real.
The governing principle (PRD FR-01): **an order is marked paid only on a
provider-authenticated, server-side verified confirmation that matches
the order, the amount and the currency. Everything else fails closed.**

## Callback trust model per gateway

### JazzCash (IPN)
- Every IPN must carry `pp_SecureHash`: HMAC-SHA256 over the sorted
  `pp_*` field values keyed with the merchant Integrity Salt
  (`NexPay_Gateway_JazzCash::build_secure_hash()`). Compared with
  `hash_equals()`.
- After the hash verifies, the handler additionally requires
  `pp_Amount` == order total in paisa, `pp_TxnCurrency` == `PKR`, and a
  success response code (`000`). The order is located by the
  `pp_TxnRefNo` stored on it at payment time.

### Easypaisa (postback)
- `merchantHashedReq` is a **keyed** SHA-256: the ordered parameters
  (storeId, orderRefNum, amount, postBackURL, accountNum) combined with
  the merchant **Hash Key**
  (`NexPay_Gateway_Easypaisa::build_merchant_hash()`).
- The postback handler rebuilds the expected fields from the **stored
  order + gateway settings** (never from posted data) and requires:
  keyed hash match (`hash_equals()`), posted storeId == configured
  store, orderRefNum == the order, amount == order total exactly, and
  currency PKR when present.
- **Fail closed:** if no Hash Key is configured, every postback is
  rejected and no order can be paid via Easypaisa. (v1.0.0's unkeyed
  hash was forgeable and was removed in v1.1.0.)

### bKash (callback + execute)
- The browser return (`?paymentID=…&status=success`) is never trusted.
  The plugin executes the payment server-to-server with its own token,
  then requires the execute response to have `statusCode` 0000,
  `transactionStatus` "Completed", a non-empty `trxID`, and amount +
  currency (BDT) equal to the order. The paymentID was stored on the
  order at creation, binding the callback to the order.

### Nagad (callback + verification API)
- The browser return is never trusted. The plugin calls the Nagad
  Payment Verification API server-to-server and requires **all** of:
  final status "Success", `merchantId` == configured merchant,
  `orderId` == the reference stored on the order, `amount` == order
  total (exact 2-decimal compare), and — when present —
  `paymentRefId` == the reference from the callback. Missing fields fail
  closed. Requests/responses use RSA encryption/signing (OpenSSL), and
  **all endpoints including the sandbox use HTTPS**.

### M-Pesa (callback + STK Push Query)
- Daraja callbacks are not cryptographically signed, so the payload is
  used only to *locate* the order (via the `CheckoutRequestID` saved at
  push time). On a success callback the plugin confirms the result
  server-to-server with the **Daraja STK Push Query API** and requires
  the query's `ResultCode` to be 0.
- Additionally the callback amount must equal the order total and the
  `MpesaReceiptNumber` is stored on the order; a receipt that already
  belongs to a different order is rejected.
- Failure/cancel result codes (e.g. 1032 cancelled, 1037 timeout, 1
  insufficient balance) mark the order failed with a customer-readable
  note — and can never reverse an already-paid order.

### UPI (no callback)
- UPI intent payments have **no provider callback and no automatic
  confirmation**. Orders stay on hold until a human verifies the payment
  in the bank/UPI statement (amount + transaction reference), per the
  workflow in [SETUP.md](SETUP.md). Anyone claiming a UPI payment must
  be verified out-of-band — never complete an order from a screenshot.

## Idempotency & replay handling

- **JazzCash:** the transaction reference (`pp_TxnRefNo`) is stored on
  the order; duplicate IPNs for an already-paid order, or with the same
  stored reference, are acknowledged without re-processing.
- **Easypaisa:** the provider transaction reference is stored in order
  meta (`_nexpay_easypaisa_txn_id`). A repeat postback with the same
  reference returns the success acknowledgement without completing the
  order twice; the same reference presented for a *different* order is
  rejected.
- **bKash:** a successful verified execute sets
  `_nexpay_bkash_executed`; repeat callbacks short-circuit. A failed
  execute may be retried safely (the flag is only set on success).
- **Nagad:** orders that are already paid short-circuit the callback.
- **M-Pesa:** duplicate callbacks for a paid order are acknowledged and
  ignored; receipt numbers are unique per order (see above).

## Secret handling & logging

- Secrets (passwords, salts, hash keys, app secrets, private keys,
  tokens, passkeys) are entered in password-type settings fields where
  the settings API supports them, are never rendered to the storefront,
  and are never sent to the browser.
- All plugin logging goes through `NexPay_Logger`, which redacts any
  array/object key — and any `key=value` pair in strings — whose name
  looks like password / secret / salt / hash key / token / integrity /
  passkey / private key before writing to the WooCommerce log.
- Configuration errors shown to admins name the *missing field labels*
  only; secret values are never printed (`WC_Admin_Settings::add_error`
  on save when an enabled gateway lacks required credentials).
- Provider HTTP calls use the WordPress HTTP API with a bounded
  30-second timeout. No provider API call is made while rendering the
  checkout page.

## Fail-closed behaviour (summary)

| Situation | Result |
|---|---|
| Invalid/missing signature or hash | Order not paid; rejection logged |
| Amount or currency mismatch | Order not paid; security log entry |
| Unknown order/transaction reference | No order created or completed |
| Provider timeout / network error | Order stays unpaid (pending/on-hold); no auto-paid |
| Replay of a processed callback | Idempotent acknowledgement; no double completion |
| Late failure callback after verified payment | Paid order is never silently reversed |
