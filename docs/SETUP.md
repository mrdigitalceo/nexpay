# NexPay — Setup Guide

This guide covers installation and per-gateway configuration for store
administrators. For the security model see [SECURITY.md](SECURITY.md);
for tested versions see [COMPATIBILITY.md](COMPATIBILITY.md).

## Installation

1. Upload the `nexpay` folder to `wp-content/plugins/` (or install the
   plugin ZIP via **Plugins → Add New → Upload**) and activate it.
2. Make sure WooCommerce is active. NexPay shows an admin notice (and
   does nothing) while WooCommerce is missing.
3. Open **WooCommerce → NexPay** for an overview of all gateways, then
   click **Configure** on the gateway you want to enable.
4. Set your store currency first (**WooCommerce → Settings → General**).
   A NexPay gateway only appears at checkout when the store currency is
   its settlement currency.
5. Keep **Test mode** enabled while you use sandbox credentials; disable
   it only with live merchant credentials.

**Callback URLs.** Every API gateway has a WooCommerce API callback
endpoint. Wherever a provider asks for a callback / IPN / postback URL,
use:

```
https://your-site.com/wc-api/<gateway_id>/
```

(equivalently `https://your-site.com/?wc-api=<gateway_id>`). The exact
URL for each gateway is listed in its section below. Your site must be
reachable from the internet over HTTPS for provider callbacks to arrive.

---

## JazzCash (Pakistan — PKR)

- **Where to get credentials:** JazzCash merchant portal / your JazzCash
  merchant account manager.
- **Required settings:** Merchant ID, Password, Integrity Salt.
- **Test mode:** uses `sandbox.jazzcash.com.pk`; live uses
  `www.jazzcash.com.pk`.
- **Callback URL:** `https://your-site.com/wc-api/nexpay_jazzcash/`
  (also set as the IPN/return URL in your JazzCash merchant settings if
  asked — the plugin sends it as `pp_ReturnURL` automatically).
- **Currency:** PKR only. Checkout collects the customer's mobile number
  and CNIC (last 6 digits), as required by the JazzCash MWALLET API.

## Easypaisa (Pakistan — PKR)

- **Where to get credentials:** Easypaisa merchant onboarding (Easypay
  hosted page product).
- **Required settings:** Store ID, Account Number, **Merchant Hash Key**.
  The Hash Key signs every request and verifies every postback. Without
  it the gateway refuses payments and all postbacks are rejected.
- **Test mode:** uses the Easypaisa staging host
  (`easypaystg.easypaisa.com.pk`); live uses `easypay.easypaisa.com.pk`.
- **Callback URL (postBackURL):**
  `https://your-site.com/wc-api/nexpay_easypaisa/`
  The plugin sends this URL to Easypaisa with each payment automatically.
- **Currency:** PKR only. Customers are redirected to the Easypaisa
  hosted page and return automatically.

## bKash (Bangladesh — BDT)

- **Where to get credentials:** bKash developer portal (Tokenized
  Checkout API app).
- **Required settings:** App Key, App Secret, Username, Password.
- **Test mode:** uses `tokenized.sandbox.bka.sh`; live uses
  `tokenized.pay.bka.sh`.
- **Callback URL:** `https://your-site.com/wc-api/nexpay_bkash/`
  The plugin passes it as the payment `callbackURL` automatically.
- **Currency:** BDT only. Checkout collects the customer's bKash mobile
  number (01XXXXXXXXX).

## Nagad (Bangladesh — BDT)

- **Where to get credentials:** Nagad merchant onboarding (PGW). You
  receive the Nagad PGW public key and generate your own merchant RSA
  key pair; Nagad keeps your public key, you paste your **private** key
  into the plugin.
- **Required settings:** Merchant ID, Merchant Number, Nagad Public Key,
  Merchant Private Key. **The PHP OpenSSL extension is required** —
  NexPay shows an admin warning when it is missing.
- **Test mode:** uses the Nagad sandbox host over HTTPS; live uses
  `api.mynagad.com`.
- **Callback URL:** `https://your-site.com/wc-api/nexpay_nagad/`
  The plugin sends it as the merchant callback URL automatically.
- **Currency:** BDT only. Checkout collects the customer's Nagad mobile
  number (01XXXXXXXXX).

## UPI (India — INR)

- **Where to get credentials:** none — you only need a UPI ID (VPA) that
  can receive payments, from any UPI app/PSP (e.g. `yourstore@okbank`).
- **Required settings:** Merchant UPI ID (VPA), Payee Name (shown to the
  customer in their UPI app).
- **Test mode:** not applicable — UPI intent payments are always "live"
  from the customer's app, but no money moves until the customer pays.
- **Callback URL:** none. **UPI has no automatic confirmation.**
- **Currency:** INR only. Checkout collects the customer's own UPI ID.
- **How it works:** the customer gets a UPI **intent link** on the
  thank-you page and in the order email, opens it with any UPI app and
  pays. The order stays **On hold**.

### Manual verification workflow (admin)

UPI payments must be verified by a human before the order is fulfilled:

1. Open the order (**WooCommerce → Orders**) — it is **On hold** and
   shows the customer's UPI ID in the order notes/meta.
2. In your bank/UPI app statement, find the incoming payment and check:
   - the **amount** equals the order total exactly, and
   - the UPI transaction reference matches the order reference
     (`NX<order-id>`, also visible in the payment note/reference the
     customer paid against).
3. Only when both match, change the order status to **Processing** (or
   **Completed** for downloadable/virtual orders) and save.
4. If no matching payment arrives, leave the order On hold or cancel it
   — never complete an order on a screenshot alone.

## M-Pesa (Kenya — KES)

- **Where to get credentials:** Safaricom Daraja portal — create an app
  for the Lipa Na M-Pesa Online (STK Push) API.
- **Required settings:** Consumer Key, Consumer Secret, Business
  Shortcode (Paybill/Till), Passkey. Sandbox shortcode `174379` with the
  public Daraja sandbox passkey works for testing.
- **Test mode:** uses `sandbox.safaricom.co.ke`; live uses
  `api.safaricom.co.ke`. Use sandbox credentials in test mode and live
  credentials in live mode (they are different apps).
- **Callback URL:** `https://your-site.com/wc-api/nexpay_mpesa/`
  The plugin sends it as the STK Push `CallBackURL` automatically; the
  URL must be publicly reachable over HTTPS or Daraja cannot deliver
  results.
- **Currency:** KES only. Checkout collects the customer's M-Pesa phone
  number (07…, 01…, +254… formats are accepted and normalized).
