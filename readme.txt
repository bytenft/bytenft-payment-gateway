=== ByteNFT Payment Gateway ===
Contributors: ByteNFT
Tags: woocommerce, payment gateway, fiat, ByteNFT
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.6
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The ByteNFT Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates ByteNFT Payment Gateway with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 1.0.6 =
* Fixed expired and manually cancelled order redirection to the Success Page with correct status in the portal.
* Improved validation messages to specify exactly what needs to be corrected (email, address, etc.).
* Updated the default plugin title for better clarity.

= 1.0.5 =
* Updated plugin initialization hook priority on `plugins_loaded` from 11 to 10 for improved load order.
* Updated the default plugin title.

= 1.0.4 =
* Enhanced phone number normalization and validation with improved error messages.

= 1.0.3 =
* Added **Sardine payment support** to enhance security, compliance, and fraud protection for transactions.

= 1.0.2 =
* Added support for multiple currencies to enhance payment flexibility.

= 1.0.1 =
* Adjusted the default plugin title for improved clarity.
* Resolved an issue where payment links were not functioning correctly on iOS devices.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.6 =
* Expired and manually cancelled orders now redirect correctly with proper status.
* Validation messages now specify exactly what needs to be corrected.
* Default plugin title updated for clarity.

= 1.0.5 =
* Recommended update to ensure correct plugin load order and updated default title.

= 1.0.4 =
* Update to this version for improved phone number validation and better error messages during checkout.

= 1.0.3 =
* Update to this version to enable Sardine integration for improved fraud prevention and secure transaction processing.

= 1.0.2 =
* Introduces multi-currency support, allowing greater flexibility and a smoother checkout experience for international customers.

= 1.0.1 =
* This update refines the default plugin title and resolves iOS payment link issues to ensure smoother checkout experiences.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: [https://pay.bytenft.xyz/reach-out](https://pay.bytenft.xyz/reach-out)
