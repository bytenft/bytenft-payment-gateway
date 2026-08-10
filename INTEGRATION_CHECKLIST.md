# ByteNFT Integration Verification Checklist

Welcome to ByteNFT! Before going live with your payment integration, please use this checklist to manually verify that everything is configured correctly. Completing these tests ensures your store can securely and reliably process transactions.

---

## 1. API / Integration Configuration

**What to test:** Ensure your ByteNFT API keys are valid and the plugin is connected correctly.
**How to test it:**
1. Navigate to **WooCommerce > Settings > Payments > ByteNFT**.
2. Click **Manage** to open the gateway settings.
3. Check the **Enable Sandbox Mode** box (this prevents real charges during testing).
4. Enter your **Sandbox Public Key** and **Sandbox Secret Key**.
5. Save your settings.
**What result to expect:** The API keys should save without error, and your account status should display as Active.
**What to check if it fails:** Double-check that there are no trailing spaces in your API keys and that they match the keys in your ByteNFT Merchant Dashboard.

---

## 2. Test Payment

**What to test:** Ensure the ByteNFT checkout loads and processes a test transaction.
**How to test it:**
1. Open your storefront (preferably in an Incognito/Private window) and add an inexpensive product to your cart.
2. Proceed to Checkout and select **ByteNFT** as your payment method.
3. Complete the checkout process.
**What result to expect:** You should be redirected to the secure ByteNFT payment page (or the modal should open). Upon successful test payment, you should be redirected back to the WooCommerce Order Received page.
**What to check if it fails:** Verify your Sandbox keys are correct and Sandbox Mode is enabled.

---

## 3. Webhook Verification

**What to test:** Ensure your website correctly receives and acknowledges payment updates from ByteNFT.
**How to test it:**
1. Log in to your **ByteNFT Merchant Dashboard**.
2. Navigate to **Developer > Webhooks**.
3. Confirm your webhook URL is correctly registered: `https://your-domain.com/wp-json/bytenft/v1/data`
4. Locate the test payment you just made and check the **Webhook Delivery Logs**.
**What result to expect:** You should see a webhook delivery log for the test transaction. The HTTP response code from your WooCommerce store should be **200 OK**.
**What to check if it fails:** 
- If the status is `4xx` or `5xx`, check your WooCommerce/server error logs.
- Ensure your server is not blocking external POST requests from the ByteNFT IPs (e.g., via Cloudflare, Wordfence, or other security plugins).

---

## 4. WooCommerce Order Verification

**What to test:** Ensure the test payment triggered the correct order status change in your WooCommerce store.
**How to test it:**
1. Go to **WooCommerce > Orders** in your WordPress dashboard.
2. Open the test order you just created.
**What result to expect:** 
- The order status should be automatically updated to **Processing** or **Completed**.
- In the Order Notes on the right, you should see a note similar to: *"Payment approved via ByteNFT."*
- Check the custom fields or order notes for the correct Payment ID and amount matching your ByteNFT Dashboard.
**What to check if it fails:** If the order remains "Pending Payment", your webhook is likely being blocked. Return to step 3.

---

## 5. Negative / Failure Scenarios

**What to test:** Ensure your store gracefully handles failed or cancelled transactions.
**How to test it:**
1. Initiate another test checkout on your storefront.
2. When the ByteNFT payment page appears, intentionally **Cancel** the payment or simulate a **Failed** transaction using test card numbers (if provided).
**What result to expect:** You should be redirected back to the checkout page with an error message. The corresponding WooCommerce order status should be updated to **Failed** or **Cancelled**.
**What to check if it fails:** Verify the webhook logs in your ByteNFT Dashboard for the cancelled event.

---

## 6. Final Pre-Go-Live Verification

Before unchecking "Enable Sandbox Mode" and entering your Live API Keys, verify the following:

- [ ] API Connection is successful.
- [ ] Test payment redirects and completes correctly.
- [ ] Webhooks deliver a **200 OK** response.
- [ ] WooCommerce order statuses update automatically.
- [ ] Failed/cancelled payments reflect correctly in WooCommerce.
- [ ] I know where to check WooCommerce Logs (WooCommerce > Status > Logs > bytenft-payment-gateway) if an issue arises.

**Congratulations! If you checked all the boxes above, you are ready to switch off Sandbox Mode, enter your Live API Keys, and start accepting live payments with ByteNFT.**
