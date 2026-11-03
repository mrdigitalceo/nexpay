=== NexPay — Multi-Country Payment Gateways for WooCommerce ===
Contributors: mrarslanceo
Tags: woocommerce, payments, payment gateway, jazzcash, easypaisa, bkash, nagad, upi, mpesa
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept local payments in 4 countries with one free plugin: JazzCash & Easypaisa (Pakistan), bKash & Nagad (Bangladesh), UPI (India) and M-Pesa (Kenya).

== Description ==

**NexPay** brings the payment methods your customers actually use to WooCommerce — in one lightweight, free and open-source plugin by **Mr Arslan CEO (NextGen Web Agency)**.

Instead of installing (and paying for) a separate plugin per country, NexPay gives you six gateways with a consistent settings experience, live/sandbox test modes, and secure, signature-verified callbacks:

* **Pakistan** — JazzCash (mobile account API with HMAC-SHA256 secure hash + IPN) and Easypaisa (hosted payment page with hash-verified postback)
* **Bangladesh** — bKash (tokenized checkout: grant → create → execute) and Nagad (RSA-signed checkout with server-side payment verification)
* **India** — UPI (intent deep link, order on-hold until manual verification)
* **Kenya** — M-Pesa (Daraja STK Push with callback handling)

A dedicated **WooCommerce → NexPay** admin page shows every gateway by country with its live enabled state and mode, and links straight to each gateway's settings.

= Features =

* 6 gateways, 4 countries, one plugin
* Sandbox / test mode for every gateway
* Provider-verified callbacks — HMAC-SHA256 (JazzCash), keyed-hash postback verification (Easypaisa), server-side verification (bKash, Nagad), Daraja STK Push Query confirmation (M-Pesa)
* HPOS (High-Performance Order Storage) compatible, with a real Cart/Checkout Blocks integration for all six gateways
* Clean, purple NexPay design at checkout and in the admin
* Fully translatable (text domain: `nexpay`)

== Installation ==

1. Upload the `nexpay` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **WooCommerce → NexPay** to see all gateways by country.
4. Click **Configure** on a gateway, enter your merchant credentials, enable it, and save.
5. Keep **Test mode** on while you test with sandbox credentials, then switch it off to go live.

== Frequently Asked Questions ==

= Is NexPay really free? =
Yes. NexPay is released under the GPL (v2 or later) with no premium version and no license keys.

= Which currencies are supported? =
Each gateway settles in its home currency: JazzCash/Easypaisa in **PKR**, bKash/Nagad in **BDT**, UPI in **INR**, and M-Pesa in **KES**. A gateway only appears at checkout when your store currency matches.

= How do I test before going live? =
Every gateway has a **Test mode** checkbox. Enable it and use the sandbox credentials from the provider (JazzCash, Easypaisa, bKash, Nagad or Safaricom Daraja all offer free sandbox accounts). No real money moves in test mode.

= Where do I get merchant credentials? =
From each provider's merchant/developer portal: JazzCash and Easypaisa (Pakistan), bKash and Nagad (Bangladesh), your UPI VPA from any UPI PSP app (India), and the Safaricom Daraja portal (Kenya).

= Does NexPay work with HPOS and the block checkout? =
Yes — NexPay is HPOS compatible, and since 1.1.0 it includes a real Cart/Checkout Blocks payment-method integration for all six gateways (not just a compatibility declaration).

= Is UPI payment confirmation automatic? =
No. UPI has no provider callback: the customer pays via a UPI intent link and the order stays On hold until the merchant verifies the payment (amount + transaction reference) in their bank/UPI statement. See the manual verification workflow in the setup guide.

== Screenshots ==

1. Checkout — customers pick their local gateway (JazzCash selected, with mobile number and CNIC fields).
2. WooCommerce → NexPay admin page — every gateway by country, with enabled state, Live/Sandbox mode and Configure links.

== Changelog ==

= 1.1.0 =
* Security: Easypaisa postback now uses the keyed merchant hash (new Merchant Hash Key setting) with store/amount/currency validation and fail-closed behaviour; the old unkeyed hash was forgeable. Existing Easypaisa users must enter their Hash Key after upgrading.
* Security: M-Pesa success callbacks are confirmed via the Daraja STK Push Query API; receipts are bound to a single order.
* Security: Nagad verification must match merchant, order reference and exact amount; sandbox endpoint moved to HTTPS.
* JazzCash IPN: exact amount (paisa) and PKR currency are now required to match the order; duplicates ignored.
* bKash: execute responses fully verified (Completed, trxID, amount, BDT) and can never execute twice.
* Feature: real WooCommerce Cart/Checkout Blocks integration for all six gateways.
* UPI: removed misleading "QR" wording; intent link + documented manual verification workflow.
* Settings validation errors for enabled gateways with missing credentials; secret-safe logging (credential redaction); 30s provider timeouts.
* Added unit tests, CI workflow, and docs (setup, security, compatibility).

= 1.0.0 =
* Initial release.
* JazzCash (Pakistan) — MWALLET API, HMAC-SHA256 secure hash, IPN handler.
* Easypaisa (Pakistan) — hosted payment page, hash-verified postback.
* bKash (Bangladesh) — tokenized checkout (grant/create/execute).
* Nagad (Bangladesh) — RSA-signed initialize/complete checkout + verification API.
* UPI (India) — intent deep link, on-hold flow.
* M-Pesa (Kenya) — Daraja OAuth + STK Push + callback handler.
* WooCommerce → NexPay admin overview page.
* HPOS + Cart/Checkout blocks compatibility declarations.

== Upgrade Notice ==

= 1.1.0 =
Security hardening release. Easypaisa users MUST enter their Merchant Hash Key in the gateway settings after upgrading — postbacks are rejected (fail closed) until it is set.

= 1.0.0 =
Initial release.
