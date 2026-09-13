# Contributing to SMSLink

First of all, thank you for taking the time to contribute! 🎉

**SMSLink** is an open-source project, and we welcome contributions from the community. Whether you are building a WordPress plugin, a WHMCS gateway module, a language SDK (PHP, Laravel, Python, Node.js, Android), fixing a bug, or improving the documentation, your help is appreciated!

---

## 🌐 Community Ecosystem Contributions (Plugins & SDKs)

We are actively seeking community contributions for integrations and client SDKs. You do **not** need core repository write access to contribute—simply submit your work inside the [`ecosystem/`](ecosystem/) directory via a Pull Request.

### 🔌 Available Plugin Categories (`ecosystem/plugins/`)
- **WordPress & WooCommerce Plugin:** [`ecosystem/plugins/wordpress/`](ecosystem/plugins/wordpress/)
- **WHMCS SMS Gateway Module:** [`ecosystem/plugins/whmcs/`](ecosystem/plugins/whmcs/)
- **Other CMS Extensions (OpenCart, Magento, PrestaShop, Filament):** [`ecosystem/plugins/other-cms/`](ecosystem/plugins/other-cms/)

### 📦 Available SDK Categories (`ecosystem/sdks/`)
- **PHP SDK (Composer Package):** [`ecosystem/sdks/php-sdk/`](ecosystem/sdks/php-sdk/)
- **Laravel SDK & Notification Channel:** [`ecosystem/sdks/laravel-sdk/`](ecosystem/sdks/laravel-sdk/)
- **Android SDK (Kotlin / Java):** [`ecosystem/sdks/android-sdk/`](ecosystem/sdks/android-sdk/)
- **Python SDK (PyPI Package):** [`ecosystem/sdks/python-sdk/`](ecosystem/sdks/python-sdk/)
- **Node.js SDK (NPM Package):** [`ecosystem/sdks/nodejs-sdk/`](ecosystem/sdks/nodejs-sdk/)

---

## 🚀 How to Submit a Pull Request (PR)

1. **Fork the Repository:**
   Click the **Fork** button at the top right of the [SMSLink GitHub Repository](https://github.com/beingniloy/smslink).

2. **Clone your Fork locally:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/smslink.git
   cd smslink
   ```

3. **Create a Feature Branch:**
   ```bash
   git checkout -b feature/wordpress-plugin
   # or
   git checkout -b sdk/laravel-package
   ```

4. **Add your Plugin / SDK / Fix:**
   - Place your plugin code in the corresponding `ecosystem/plugins/` or `ecosystem/sdks/` subfolder.
   - Include a `README.md` file inside your subfolder explaining requirements, installation steps, and code examples.

5. **Commit your Changes:**
   ```bash
   git add .
   git commit -m "feat(ecosystem): add initial WordPress OTP & SMS plugin"
   ```

6. **Push to your Fork:**
   ```bash
   git push origin feature/wordpress-plugin
   ```

7. **Open a Pull Request:**
   Navigate to the original [SMSLink Repository](https://github.com/beingniloy/smslink) and click **New Pull Request**.

---

## 📋 Code & Quality Standards

- **PHP Code Style:** Follow PSR-12 coding standards where applicable.
- **Documentation:** Provide clear setup and usage instructions in a `README.md` file for any new plugin or SDK.
- **Security:** Do not commit hardcoded credentials, secret keys, or passwords.
- **Clean Commits:** Write clear commit messages summarizing what was added or changed.

---

## 📄 License & Ownership

By contributing to SMSLink, you agree that your contributions will be licensed under the project's [MIT License](LICENSE).

---

## 💬 Questions or Feedback?

If you have questions before starting a plugin or SDK contribution:
- **GitHub Issues:** [https://github.com/beingniloy/smslink/issues](https://github.com/beingniloy/smslink/issues)
- **Developer Email:** [hello@niloy.io](mailto:hello@niloy.io)
