# 🌐 SMSLink Ecosystem & Community Contributions

Welcome to the **SMSLink Ecosystem**! This directory is dedicated to community-maintained plugins, modules, CMS extensions, and Software Development Kits (SDKs) built around the **SMSLink Telephony Gateway API**.

Whether you are a developer looking to integrate SMSLink with your favorite CMS (WordPress, WHMCS, OpenCart, Magento) or build a client library for your programming language (PHP, Laravel, Python, Node.js, Android), this is the central hub for open-source contributions.

---

## 📂 Directory Structure

```
ecosystem/
├── plugins/
│   ├── wordpress/      # WordPress / WooCommerce SMS & OTP Plugin
│   ├── whmcs/          # WHMCS SMS Gateway Addon Module
│   └── other-cms/      # OpenCart, Magento, PrestaShop, Filament, etc.
└── sdks/
    ├── php-sdk/        # Native PHP Composer Library
    ├── laravel-sdk/    # Laravel Service Provider & Facade
    ├── android-sdk/    # Android Kotlin/Java Client Library
    ├── python-sdk/     # Python PyPI Package
    └── nodejs-sdk/     # Node.js NPM Package
```

---

## 🚀 Desired Community Contributions

We invite developers from the open-source community to contribute plugins, modules, and SDKs. Here are the priority projects:

### 🧩 CMS Plugins & Modules
- **[WordPress & WooCommerce Plugin](plugins/wordpress/):** OTP Login, Order Status SMS, Admin settings panel.
- **[WHMCS SMS Gateway Module](plugins/whmcs/):** Client notifications, Invoice payment alerts, Admin 2FA.
- **[Other CMS Extensions](plugins/other-cms/):** OpenCart, Magento, PrestaShop, Shopify webhooks.

### 📦 Language & Framework SDKs
- **[PHP SDK](sdks/php-sdk/):** Native PHP client library with Guzzle / cURL adapter.
- **[Laravel SDK](sdks/laravel-sdk/):** Laravel Notification Channel, Facade, and Config auto-discovery.
- **[Android SDK](sdks/android-sdk/):** Kotlin/Java helper library for custom gateway integrations.
- **[Python SDK](sdks/python-sdk/):** PyPI library for Django / Flask / FastAPI integrations.
- **[Node.js SDK](sdks/nodejs-sdk/):** Async NPM package for Express / NestJS apps.

---

## 🤝 How to Contribute a Plugin or SDK

1. **Fork the Repository:** Create a fork of [beingniloy/smslink](https://github.com/beingniloy/smslink).
2. **Choose your Category:** Create your module or SDK inside the appropriate subfolder in `ecosystem/`.
3. **Include Documentation:** Include a `README.md` with installation steps, requirements, and usage examples.
4. **Open a Pull Request:** Submit a PR with a clear description of your contribution.

For full contribution guidelines, code formatting standards, and review processes, please read **[CONTRIBUTING.md](../CONTRIBUTING.md)**.
