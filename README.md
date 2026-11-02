<p align="center">
  <img src="assets/images/banner.jpg" alt="NexPay — Multi-Country Payment Gateways for WooCommerce" width="100%">
</p>

<h1 align="center">NexPay — Multi-Country Payment Gateways for WooCommerce</h1>

<p align="center">
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2%2B-blue.svg" alt="License: GPL v2 or later"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/WooCommerce-7.0%2B-96588A.svg" alt="WooCommerce 7.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/Version-1.0.0-6D28D9.svg" alt="Version 1.0.0">
</p>

<p align="center">
  <strong>One free plugin. Six local payment gateways. Four countries.</strong><br>
  Accept JazzCash &amp; Easypaisa (Pakistan), bKash &amp; Nagad (Bangladesh), UPI (India) and M-Pesa (Kenya) in WooCommerce — no premium version, no license keys.
</p>

---

## ✨ Features

- 🌍 **6 gateways, 4 countries** — one consistent settings experience instead of six separate plugins
- 🧪 **Sandbox / test mode** for every gateway — test safely, then flip to Live
- 🔐 **Secure callbacks** — HMAC-SHA256 signed IPN (JazzCash), hash-verified postback (Easypaisa), server-side execution & verification (bKash, Nagad), Daraja result callback (M-Pesa)
- 🛒 **HPOS + Blocks ready** — declares compatibility with High-Performance Order Storage and the Cart/Checkout blocks
- 🎨 **Matching NexPay design** — purple gateway rows with brand badges at checkout, and a gateways-by-country overview in the admin
- 🌐 **Fully translatable** — text domain `nexpay`, POT file included
- 🆓 **100% free & open source** — GPL-2.0-or-later

## 💳 Supported Gateways

| Country | Gateway | Currency | How it works |
|---|---|---|---|
| 🇵🇰 Pakistan | **JazzCash** | PKR | Mobile Account (MWALLET) API — HMAC-SHA256 `pp_SecureHash` + verified IPN |
| 🇵🇰 Pakistan | **Easypaisa** | PKR | Hosted payment page — signed payload + hash-verified postback |
| 🇧🇩 Bangladesh | **bKash** | BDT | Tokenized Checkout — token grant → create payment → execute on callback |
| 🇧🇩 Bangladesh | **Nagad** | BDT | RSA-signed initialize/complete checkout + Payment Verification API |
| 🇮🇳 India | **UPI** | INR | UPI intent deep link (`upi://pay?...`) + QR payload, order on-hold flow |
| 🇰🇪 Kenya | **M-Pesa** | KES | Safaricom Daraja — OAuth + STK Push (Lipa na M-Pesa) + callback |

> A gateway only appears at checkout when your store currency matches its settlement currency (PKR / BDT / INR / KES).

## 📸 Screenshots

**Checkout** — customers choose their local gateway; JazzCash is selected with its mobile number and CNIC fields:

![NexPay checkout](assets/images/screenshot-checkout.jpg)

**Admin — WooCommerce → NexPay** — every gateway by country, with live enabled state, Live/Sandbox mode and Configure links:

![NexPay admin settings](assets/images/screenshot-settings.jpg)

## 📦 Installation

1. Download this repository as a ZIP (or clone it) and upload it via **Plugins → Add New → Upload Plugin** — or copy the `nexpay` folder to `wp-content/plugins/`.
2. Activate **NexPay** in WordPress.
3. Open **WooCommerce → NexPay** to see all gateways by country.
4. Click **Configure** on the gateway you want, enter your merchant credentials, tick **Enable**, and save.
5. Keep **Test mode** enabled while testing with sandbox credentials; disable it to accept real payments.

**Requirements:** WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

## ⚙️ Configuration (per gateway)

- **JazzCash** — Merchant ID, Password and Integrity Salt from the JazzCash merchant portal. Checkout asks for the customer's mobile number and CNIC (last 6 digits). Set your JazzCash IPN/return URL to the one shown by WooCommerce for this gateway (the plugin registers `wc-api=nexpay_jazzcash`).
- **Easypaisa** — Store ID and merchant Account Number. Customers are redirected to the Easypaisa hosted page and returned automatically; the postback hash is verified before the order is paid.
- **bKash** — App Key, App Secret, Username and Password from the bKash developer portal. Uses the tokenized checkout; the payment is executed server-side when the customer returns.
- **Nagad** — Merchant ID, Merchant Number, the Nagad PGW Public Key and your Merchant Private Key (used for RSA encryption/signing). Payments are confirmed with the Nagad Verification API.
- **UPI** — Your merchant UPI ID (VPA, e.g. `yourstore@okbank`) and Payee Name. The customer pays with any UPI app via the intent link/QR payload on the thank-you page; the order stays **On hold** until you verify and complete it.
- **M-Pesa** — Consumer Key, Consumer Secret, Business Shortcode and Passkey from the Safaricom Daraja portal. Customers get an STK Push prompt on their phone and approve with their PIN.

## ❓ FAQ

**Is NexPay free?**
Yes — free and open source under the GPL. There is no paid tier.

**How does test mode work?**
Every gateway has a *Test mode* checkbox. In test mode the plugin talks to the provider's sandbox endpoints, so no real money moves. JazzCash, Easypaisa, bKash, Nagad and Daraja all provide free sandbox credentials.

**Which currency does my store need?**
PKR for JazzCash/Easypaisa, BDT for bKash/Nagad, INR for UPI and KES for M-Pesa. Gateways hide automatically if the store currency doesn't match.

**Does it work with HPOS and the block checkout?**
Yes. NexPay declares compatibility with WooCommerce HPOS (custom order tables) and the Cart/Checkout blocks.

**Where do callbacks point?**
Each API gateway registers a WooCommerce API endpoint — `wc-api=nexpay_jazzcash`, `wc-api=nexpay_easypaisa`, `wc-api=nexpay_bkash`, `wc-api=nexpay_nagad`, `wc-api=nexpay_mpesa` — and sends it to the provider automatically (as the return/IPN/callback URL) during payment creation.

## 🤝 Contributing

Contributions are welcome — new gateways, new countries, bug fixes and translations.

1. Fork the repository and create a feature branch.
2. Follow the WordPress Coding Standards; every PHP file must pass `php -l`.
3. Never commit real merchant credentials or keys (sandbox test data only).
4. Open a pull request describing what you changed and how you tested it.

Please report bugs and request gateways via [GitHub Issues](https://github.com/mrdigitalceo/nexpay/issues).

## 📄 License

NexPay is free software, released under the **GNU General Public License v2.0 or later** — see the [LICENSE](LICENSE) file for the full text. You may use, copy, modify and redistribute it under the terms of that license.

## 👤 Author

**Mr Arslan CEO** — [NextGen Web Agency](https://mrarslanceo.site)

- 🌐 Website: [mrarslanceo.site](https://mrarslanceo.site)
- 💻 GitHub: [github.com/mrdigitalceo](https://github.com/mrdigitalceo)
- 📸 Instagram: [instagram.com/mrarslanceo](https://instagram.com/mrarslanceo)
- ▶️ YouTube: [youtube.com/@mrarslanceo](https://youtube.com/@mrarslanceo)
- 👍 Facebook: [facebook.com/mrdigitalceo](https://facebook.com/mrdigitalceo)
- 🛠️ Fiverr: [fiverr.com/mrdigitalceo](https://www.fiverr.com/mrdigitalceo)
- 💬 WhatsApp: [wa.me/923164309537](https://wa.me/923164309537)
- 🧩 Codester: [codester.com/mrdigitalceo](https://www.codester.com/mrdigitalceo)

---

<p align="center">Made with ❤️ by <strong>Mr Arslan CEO</strong> · NextGen Web Agency</p>
