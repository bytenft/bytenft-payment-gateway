# ByteNFT Payment Gateway

The ByteNFT Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

## Plugin Information

**Contributors:** ByteNFT  
**Tags:** woocommerce, payment gateway, fiat, ByteNFT  
**Requires at least:** 6.2  
**Tested up to:** 6.7  
**Stable tag:** 1.0.5  
**License:** GPLv3 or later  
**License URI:** [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

For any issues or enhancement requests with this plugin, please contact the ByteNFT support team. Ensure you provide your plugin, WooCommerce, and WordPress version where applicable to expedite troubleshooting.

## Getting Started

1. Download and install the plugin from [GitHub](https://github.com/bytenft/bytenft-payment-gateway).
2. Activate it via **Plugins > Installed Plugins** in your WordPress dashboard.
3. Go to **WooCommerce > Settings > Payments > ByteNFT** to configure:
   - API Key & Secret
   - Sandbox/Live Mode
   - Multiple account support
   - Order status behavior
4. Save your changes.

## Installation

### Minimum Requirements

- WooCommerce 8.9 or greater
- PHP version 8.0 or greater

### Plugin Setup & Usage Guide

## 1. Download Plugin from GitHub

- Visit the GitHub repository for the ByteNFT Payment Gateway plugin at [GitHub Repository URL](https://github.com/bytenft/bytenft-payment-gateway).
- Download the plugin ZIP file to your local machine.

## 2. Install the Plugin in WordPress

- **Extract the ZIP File** on your computer.
- **Upload via FTP or File Manager**:
  - Connect to your server via FTP or hosting control panel.
  - Navigate to the `/wp-content/plugins/` directory.
  - Upload the extracted plugin folder there.

## 3. Activate the Plugin

- Log in to your WordPress Admin Dashboard.
- Go to **Plugins > Installed Plugins**.
- Find **ByteNFT Payment Gateway** and click **Activate**.

## 4. Obtain API Keys from ByteNFT

- Log in to your [ByteNFT account](https://www.bytenft.xyz).
- Go to the **Developer Settings** section.
- Generate or copy your API credentials:
  - **Live Public Key**
  - **Live Secret Key**
  - **Sandbox Keys** (optional, for testing)

## 5. Update API Keys in WooCommerce Settings

- **Navigate to WooCommerce Settings:**
  Log in to your WordPress Admin Dashboard.
  Go to `WooCommerce` > `Settings`.
- **Access the Payments Tab:**
  Click on the `Payments` tab at the top of the settings page.
- **Select ByteNFT Payment Gateway:**
  Scroll down to find and select the ByteNFT Payment Gateway among the available payment methods.

- **Add Plugin General Details:**
    - **Title** : ByteNFT Payment Gateway
    Description
    - **Description** : Secure payments with ByteNFT Payment Gateway.
    - **Enable/Disable Sandbox Mode** : Toggle sandbox mode per account.
    - **Payment Accounts (Add Multiple Accounts)** : 
        - **Adding a New Account**
            1. Click on **Add Account** to create a new account.
            2. Enter a **unique account title**.
            3. Provide details for each account:
                - *Account Title*
                - *Priority:* Set an order for the accounts.
                - *Live Mode:* Public & Secret Keys (mandatory)
                - *Sandbox Mode:* Public & Secret Keys (optional)
    - **Order Status** : Select Processing or Completed.
    - **Show Consent Checkbox** : Enabling this option will display a consent checkbox on the checkout page.

- **Save Changes:**
  Click `Save changes` at the bottom of the page to update and save your API key settings.

## 6. Place Order via ByteNFT Payment Option

1. Customer places an order and selects **ByteNFT Payment Gateway**.
2. A secure **popup window** opens with 3 payment options:
   - Send payment link to the customer's checkout email
   - Scan a QR code to pay from another device
   - Send the payment link to a different email or phone number
3. The popup **tracks payment status in real-time** via polling.
4. Once payment is completed:
   - The popup auto-closes
   - The customer is **redirected** back to your site with a success message.

## 7. Real-Time Payment Tracking

- **After Successful Payment:**
  Once the payment is successfully processed, the popup window will automatically close.
  Customers will be redirected back to your WordPress site.

## 8. Check Orders in WordPress

- **Verify Order Status:**
  Log in to your WordPress Admin Dashboard.
  Navigate to `WooCommerce` > `Orders` to view all orders.
  Check for the latest orders placed using the ByteNFT Payment Gateway to verify their status.

### Notes

- No crypto or wallet setup is required for buyers.
- The payment flow is secure and offloaded to ByteNFT.
- Great for physical, digital, or tokenized product stores.

## Documentation

The official documentation for this plugin is available at: [https://www.bytenft.xyz/api/docs/wordpress-plugin](https://www.bytenft.xyz/api/docs/wordpress-plugin)

## Changelog

## Version 1.0.5

### What's New
- **Payment Link Expiry**  
   - Fixed a bug where the payment link expired after 30 minutes.

- **Invoice Page Redirect After Payment**  
  - Fixed an issue where users were not redirected to the Invoice page after a successful or failed transaction.

- **Payment Link Email Not Sent Automatically**  
  - Fixed a bug where the payment link email was not sent automatically to the customer.

## Version 1.0.4

- **Account Settings Validation Fix**  
   - Resolved an issue where the account key’s status and displays an error if it is inactive or invalid, preventing normal flow execution.

- **Sandbox Mode Key default enable**  
  - Fixed an bug where the settings now appear immediately when Sandbox Mode is enabled, without requiring a click inside the field.

## Version 1.0.3

- **Account Settings Sync Fix**  
   - Resolved an issue where updates to account settings were not retained after refreshing the admin settings page.

- **Sandbox Mode Key Handling**  
  - Fixed a bug where selecting the Sandbox environment didn’t correctly display or activate the corresponding key options.

- **Code Cleanup & Mode Switching Improvements**  
  - Refactored internal logic for account validation. 
  - Enhanced environment switching between Sandbox and Live modes.
  - Improved reliability and consistency of key loading.

## Version 1.0.2 – NFT-Backed Mastercard Checkout

- **New Feature:** Support for **Mastercard credit and debit cards**.
- **Utility NFTs:** Orders are now issued as **utility NFTs**, redeemable for the product purchased.
- **No Crypto Needed:** Customers don’t need a wallet or crypto, just pay with a card.
- **Frictionless UX:** Smooth, secure, and familiar checkout flow for mainstream users.

## Version 1.0.2 – NFT-Backed Mastercard Checkout

- **New Feature:** Support for **Mastercard credit and debit cards**.
- **Utility NFTs:** Orders are now issued as **utility NFTs**, redeemable for the product purchased.
- **No Crypto Needed:** Customers don’t need a wallet or crypto, just pay with a card.
- **Frictionless UX:** Smooth, secure, and familiar checkout flow for mainstream users.

### Version 1.0.1 (Initial Release)

- **Initial Release:** Launched the ByteNFT Payment Gateway plugin with core payment integration functionality for WooCommerce.

## Support

For customer support, visit: [https://www.bytenft.xyz/reach-out](https://www.bytenft.xyz/reach-out)

## Why Choose ByteNFT Payment Gateway?

With the ByteNFT Payment Gateway, you can easily transfer fiat payments to sell products. Choose ByteNFT Payment Gateway as your WooCommerce payment gateway to access your funds quickly through a powerful and secure payment engine provided by ByteNFT.
