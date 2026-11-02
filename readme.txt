=== NexPay — Multi-Country Payment Gateways for WooCommerce ===
Contributors: mrarslanceo
Tags: woocommerce, payments, payment gateway, jazzcash, easypaisa, bkash, nagad, upi, mpesa
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept local payments in 4 countries with one free plugin: JazzCash & Easypaisa (Pakistan), bKash & Nagad (Bangladesh), UPI (India) and M-Pesa (Kenya).

== Description ==

**NexPay** brings the payment methods your customers actually use to WooCommerce — in one lightweight, free and open-source plugin by **Mr Arslan CEO (NextGen Web Agency)**.

Instead of installing (and paying for) a separate plugin per country, NexPay gives you six gateways with a consistent settings experience, live/sandbox test modes, and secure, signature-verified callbacks:

* **Pakistan** — JazzCash (mobile account API with HMAC-SHA256 secure hash + IPN) and Easypaisa (hosted payment page with hash-verified postback)
* **Bangladesh** — bKash (tokenized checkout: grant → create → execute) and Nagad (RSA-signed checkout with server-side payment verification)
* **India** — UPI (intent deep link + QR payload, order on-hold flow)
* **Kenya** — M-Pesa (Daraja STK Push with callback handling)

A dedicated **WooCommerce → NexPay** admin page shows every gateway by country with its live enabled state and mode, and links straight to each gateway's settings.

= Features =

* 6 gateways, 4 countries, one plugin
* Sandbox / test mode for every gateway
* Secure callbacks — HMAC-SHA256 (JazzCash), hash verification (Easypaisa), server-side verification (bKash, Nagad), Daraja callback (M-Pesa)
* HPOS (High-Performance Order Storage) and Cart/Checkout blocks compatible
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
Yes — NexPay declares compatibility with WooCommerce HPOS (custom order tables) and the Cart/Checkout blocks.

== Screenshots ==

1. Checkout — customers pick their local gateway (JazzCash selected, with mobile number and CNIC fields).
2. WooCommerce → NexPay admin page — every gateway by country, with enabled state, Live/Sandbox mode and Configure links.

== Changelog ==

= 1.0.0 =
* Initial release.
* JazzCash (Pakistan) — MWALLET API, HMAC-SHA256 secure hash, IPN handler.
* Easypaisa (Pakistan) — hosted payment page, hash-verified postback.
* bKash (Bangladesh) — tokenized checkout (grant/create/execute).
* Nagad (Bangladesh) — RSA-signed initialize/complete checkout + verification API.
* UPI (India) — intent deep link + QR payload, on-hold flow.
* M-Pesa (Kenya) — Daraja OAuth + STK Push + callback handler.
* WooCommerce → NexPay admin overview page.
* HPOS + Cart/Checkout blocks compatibility declarations.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
