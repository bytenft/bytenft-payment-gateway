(function ($, window, document) {
    'use strict';

    if (window.BytenftCheckoutInitialized) {
        return;
    }

    window.BytenftCheckoutInitialized = true;

    const BytenftCheckout = {

        PAYMENT_METHOD: bytenft_params.payment_method,

        state: {
            submitting: false,
            popup: null,
            popupInterval: null,
            orderId: null,
            button: null,
            buttonText: ''
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {

            this.bindClassicCheckout();

            this.bindBlockCheckout();

            this.bindInputSanitization();

            console.log('[Bytenft] initialized');
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {

            const self = this;

            $('form.checkout')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on(
                    'checkout_place_order_' + self.PAYMENT_METHOD,
                    function () {

                        console.log('[Bytenft] classic checkout');

                        const $form = $(this);

                        if (self.state.submitting) {
                            return false;
                        }

                        self.clearCheckoutErrors();

                        // =====================================================
                        // 1. REQUIRED FIELD VALIDATION (SYNC)
                        // =====================================================
                        const requiredError = self.validateRequiredFields($form);

                        if (requiredError) {
                            self.showCheckoutError(
                                requiredError.message,
                                requiredError.fields
                            );
                            return false;
                        }

                        // =====================================================
                        // 2. FULL VALIDATION (SYNC)
                        // =====================================================
                        const validationError = self.validateAll($form);

                        if (validationError) {
                            self.showCheckoutError(validationError);
                            return false;
                        }

                        // =====================================================
                        // 3. LOCK SUBMIT (IMPORTANT)
                        // =====================================================
                        self.state.submitting = true;

                        // =====================================================
                        // 4. SAFARI POPUP (MUST BE IMMEDIATE AFTER CLICK)
                        // =====================================================
                        self.openPopupImmediately();

                        self.trackPopupClose();

                        // =====================================================
                        // 5. START CUSTOM FLOW
                        // =====================================================
                        self.handleClassicCheckout($form);

                        // STOP WooCommerce default flow
                        return false;
                    }
                );
        },

        handleClassicCheckout: function ($form) {

            const self = this;

            self.state.button = $form
                .find('button[name="woocommerce_checkout_place_order"]');

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            $.ajax({

                type: 'POST',

                url: wc_checkout_params.checkout_url,

                data: $form.serialize(),

                dataType: 'json',

                success: function (response) {

                    console.log('[Bytenft] classic response', response);

                    self.handleResponse(response);
                },

                error: function (xhr, status, error) {

                    console.log('[Bytenft] classic ajax error');
                    console.log(xhr.responseText);

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.cleanupPopup();

                    self.reset();
                }
            });
        },

        /* =========================================================
         * BLOCK CHECKOUT
         * ========================================================= */

        bindBlockCheckout: function () {

            const self = this;

            document.addEventListener(
                'click',
                function (e) {

                    const btn = e.target.closest(
                        '.wc-block-components-checkout-place-order-button'
                    );

                    if (!btn) {
                        return;
                    }

                    const $form = $('form.wc-block-checkout__form');

                    if (!$form.length) {
                        return;
                    }

                    const selected = $form
                        .find(
                            'input[name="radio-control-wc-payment-method-options"]:checked'
                        )
                        .val();

                    if (selected !== self.PAYMENT_METHOD) {
                        return;
                    }

                    console.log('[Bytenft] block checkout');

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    if (self.state.submitting) {
                        return;
                    }

                    self.clearCheckoutErrors();

                    // =====================================================
                    // 1. REQUIRED VALIDATION (SYNC)
                    // =====================================================
                    const requiredError = self.validateRequiredFields($form);

                    if (requiredError) {
                        self.showCheckoutError(
                            requiredError.message,
                            requiredError.fields
                        );
                        return;
                    }

                    // =====================================================
                    // 2. FULL VALIDATION (SYNC)
                    // =====================================================
                    const validationError = self.validateAll($form);

                    if (validationError) {
                        self.showCheckoutError(validationError);
                        return;
                    }

                    // =====================================================
                    // 3. LOCK SUBMIT (IMPORTANT)
                    // =====================================================
                    self.state.submitting = true;

                    // =====================================================
                    // 4. SAFARI POPUP FIX (MUST BE IMMEDIATE)
                    // =====================================================
                    self.openPopupImmediately();

                    self.trackPopupClose();

                    // =====================================================
                    // 5. START BLOCK FLOW
                    // =====================================================
                    self.handleBlockCheckout($form);
                },
                true
            );

            // Prevent Woo block native submit
            document.addEventListener(
                'submit',
                function (e) {

                    const form = e.target;

                    if (!form.classList.contains('wc-block-checkout__form')) {
                        return;
                    }

                    const selected = form.querySelector(
                        'input[name="radio-control-wc-payment-method-options"]:checked'
                    )?.value;

                    if (selected !== self.PAYMENT_METHOD) {
                        return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                },
                true
            );
        },

        handleBlockCheckout: function ($form) {

            const self = this;

            self.state.button = $(
                '.wc-block-components-checkout-place-order-button'
            );

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            let data = $form.serialize();

            data += '&action=bytenft_block_gateway_process';
            data += '&nonce=' + encodeURIComponent(bytenft_params.bytenft_nonce);

            $.ajax({

                type: 'POST',

                url: bytenft_params.ajax_url,

                data: data,

                success: function (response) {

                    console.log('[Bytenft] block response', response);

                    self.handleResponse(response);
                },

                error: function (xhr, status, error) {

                    console.log('[Bytenft] block ajax error');
                    console.log(xhr.responseText);

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.cleanupPopup();

                    self.reset();
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

       handleResponse: function (response) {

        const self = this;

        try {

            if (typeof response === 'string') {

                    try {
                        response = JSON.parse(response);
                    } catch (e) {

                        console.log('[Bytenft] invalid json');

                        self.showCheckoutError(
                            'Invalid server response.'
                        );

                        self.cleanupPopup();

                        self.reset();

                        return;
                    }
                }

                console.log('[Bytenft] parsed response', response);

                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect =
                    response.redirect ||
                    response.data?.redirect ||
                    null;

                const orderId =
                    response.order_id ||
                    response.data?.order_id ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your payment could not be completed. Please try again.';

                self.state.orderId = orderId;

                // =====================================================
                // ❌ FAILURE (FIXED - ERROR DISPLAY STABLE)
                // =====================================================
                if (!success) {

                    const msg = errorMessage || 'Your payment could not be completed. Please try again.';

                    console.log('[Bytenft] showing failed message:', msg);

                    self.cleanupPopup();

                    // 🔥 IMPORTANT
                    setTimeout(function () {

                        self.showCheckoutError(msg);

                        // force scroll after Woo rerender
                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 300);
                        }

                    }, 50);

                    // 🔥 IMPORTANT
                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // ✅ SUCCESS — navigate popup WITHOUT sending referrer
                // =====================================================
                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    if (self.state.popup && !self.state.popup.closed) {

                        try {
                            // FIX: Use navigateWithoutReferrer instead of
                            // directly setting location.href, which would
                            // send the WP checkout URL as Referer header
                            // to the Laravel payment page.
                            self.navigateWithoutReferrer(self.state.popup, redirect);

                        } catch (e) {

                            // Safari fallback — re-open popup and use same method
                            if (!self.state.popup || self.state.popup.closed) {
                                self.state.popup = window.open(
                                    '',
                                    '_blank'
                                );
                            }

                            self.navigateWithoutReferrer(self.state.popup, redirect);
                        }

                        self.trackPopupClose();

                    } else {

                        window.location.href = redirect;
                    }

                    self.reset(true);
                    return;
                }

                self.cleanupPopup();

                self.showCheckoutError('Missing redirect URL.');

                self.reset();

            } catch (e) {

                console.log('[Bytenft] handleResponse exception', e);

                self.cleanupPopup();

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * POPUP
         * ========================================================= */

        openPopupImmediately: function () {

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {
                return;
            }

            // NOTE: window.open() only takes 3 arguments — the 4th is ignored
            // by all browsers. noopener/noreferrer do NOT go here when opening
            // about:blank because we need to keep the popup reference to write
            // into it. Referrer stripping is handled by navigateWithoutReferrer().
            this.state.popup = window.open(
                '',
                '_blank',
                'width=700,height=700'
            );

            if (!this.state.popup) {

                alert('Popup blocked. Please allow popups.');

                return;
            }

            const logoUrl = bytenft_params.bytenft_loader
                ? encodeURI(bytenft_params.bytenft_loader)
                : '';

            // FIX: Add <meta name="referrer" content="no-referrer"> so that
            // even this loading page does not leak referrer on any resource loads.
            this.state.popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                </head>

                <body style="
                    margin:0;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    height:100vh;
                    font-family:sans-serif;
                    background:#fff;
                    text-align:center;
                ">

                    <div>

                        ${
                            logoUrl
                                ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />`
                                : ''
                        }

                        <h3>Connecting to secure payment...</h3>

                        <p>Please do not close this window.</p>

                    </div>

                </body>
                </html>
            `);

            this.state.popup.document.close();
        },

        /* =========================================================
         * NAVIGATE WITHOUT REFERRER
         * ========================================================= */

        navigateWithoutReferrer: function (popup, url) {

            // The popup is still on about:blank so we own its document.
            // We write a new page into it that has:
            //   1. <meta name="referrer" content="no-referrer">  — referrer policy
            //   2. <meta http-equiv="refresh" content="0;url=..."> — immediate redirect
            //
            // The browser navigates from THIS intermediate page to the payment URL,
            // so document.referrer on the Laravel payment page will be empty string.
            // The SDK will see no referrer.
            try {
                popup.document.open();
                popup.document.write(
                    '<!DOCTYPE html><html><head>' +
                    '<meta name="referrer" content="no-referrer">' +
                    '<meta http-equiv="refresh" content="0;url=' + url + '">' +
                    '</head><body></body></html>'
                );
                popup.document.close();

            } catch (e) {

                // Last resort fallback — referrer may leak but payment still works
                console.log('[Bytenft] navigateWithoutReferrer fallback', e);
                popup.location.href = url;
            }
        },

        cleanupPopup: function () {

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {

                try {
                    this.state.popup.close();
                } catch (e) {}
            }

            this.state.popup = null;
        },

        trackPopupClose: function () {

            const self = this;

            if (!self.state.orderId) {
                console.log('[Bytenft] No order ID for popup tracking');
                return;
            }

            // clear any previous interval (important safety)
            if (self.state.popupInterval) {
                clearInterval(self.state.popupInterval);
                self.state.popupInterval = null;
            }

            self.state.popupInterval = setInterval(function () {

                const popupStillOpen =
                    self.state.popup &&
                    !self.state.popup.closed;

                // 👉 wait until popup closes
                if (popupStillOpen) {
                    return;
                }

                clearInterval(self.state.popupInterval);
                self.state.popupInterval = null;

                console.log('[Bytenft] Popup closed → single final check');

                $.post(
                    bytenft_params.ajax_url,
                    {
                        action: 'bytenft_popup_closed_event',
                        order_id: self.state.orderId,
                        security: bytenft_params.bytenft_nonce
                    },
                    function (response) {

                        const success =
                            response?.success === true ||
                            response?.data?.payment_status === 'success' ||
                            response?.data?.payment_status === 'paid';

                        const redirectUrl =
                            response?.data?.redirect ||
                            response?.redirect;

                        if (success && redirectUrl) {

                            console.log('[Bytenft] Payment success → redirect');

                            self.cleanupPopup();
                            window.location.replace(redirectUrl);
                            return;
                        }

                        console.log('[Bytenft] Payment failed / incomplete');

                        self.cleanupPopup();
                        self.showCheckoutError(
                            response?.message ||
                            'Your payment was not completed.'
                        );

                        self.reset();

                    },
                    'json'
                );

            }, 800); // small check ONLY for popup close detection
        },

        /* =========================================================
         * VALIDATIONS
         * ========================================================= */

        validateAll: function ($form) {
            const email = this.getBillingEmail($form);
            if (!email) return 'Please enter your email address.';
            if (!this.isValidEmail(email)) return 'Please enter a valid email address.';

            const phone = this.getPhoneNumber($form);
            if (phone && !this.isValidPhoneNumber(phone)) return 'Please enter a valid phone number.';

            const poBox = this.validatePOBox($form);
            if (poBox) return poBox;

            return null;
        },

        validateRequiredFields: function ($form) {
            let missing = [];
            let firstInvalid = null;

            // 1. Passively read if Shipping is active according to the platform
            const isShippingActive = this.getShippingState($form);

            // Look globally for required fields across any FunnelKit structural steps
            const $allFields = $form.find('input[required], select[required], textarea[required]');

            $allFields.each(function () {
                const $field = $(this);
                const name = $field.attr('name') || '';

                if ($field.attr('type') === 'hidden') {
                    return; 
                }

                // PASSIVE RULE: If it's a shipping field but shipping is disabled by the user, ignore it.
                if (name.indexOf('shipping_') === 0 && !isShippingActive) {
                    return;
                }

                // PASSIVE RULE: If it's a non-shipping field hidden by dynamic conditional logic (NOT steps), ignore it.
                // We check if it's hidden inside a conditional wrapper rather than checking the field itself.
                const $conditionalParent = $field.closest('.wcf-conditional-field, .woocommerce-validated');
                if ($conditionalParent.length && $conditionalParent.is(':hidden')) {
                    return;
                }

                const val = ($field.val() || '').trim();
                const $wrapper = $field.closest('.form-row, .wc-block-components-text-input, .form-row-first, .form-row-last');

                if (!val) {
                    $wrapper.addClass('woocommerce-invalid woocommerce-invalid-required-field');

                    let label = $wrapper.find('label').first().text().trim()
                        || $field.attr('placeholder')
                        || $field.attr('name');

                    label = label.replace('*', '').trim();

                    if (label && !missing.includes(label)) {
                        missing.push(label);
                    }

                    if (!firstInvalid) {
                        firstInvalid = $field;
                    }
                } else {
                    $wrapper.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            // Only attempt to focus if the element is currently rendered on screen
            if (firstInvalid && firstInvalid.is(':visible')) {
                setTimeout(function () { firstInvalid.trigger('focus'); }, 100);
            }

            if (missing.length) {
                return { message: 'Please fill required fields.', fields: missing };
            }

            return null;
        },

        getShippingState: function ($form) {
            // 1. Primary Check: Inspect the native structural state checkbox managed by WooCommerce/FunnelKit
            const $shipToDifferentCheckbox = $form.find('#ship-to-different-address-checkbox, input[name="ship_to_different_address"]');
            
            if ($shipToDifferentCheckbox.length) {
                // Read properties exactly as they sit in the DOM without changing them
                if ($shipToDifferentCheckbox.is(':checkbox')) {
                    return $shipToDifferentCheckbox.is(':checked');
                }
                const val = $shipToDifferentCheckbox.val();
                return (val === '1' || val === 'yes' || val === 'true');
            }

            // 2. Secondary Check: If FunnelKit unmounted the checkbox completely, look at the visibility of the wrapper panel itself
            const $shippingWrapper = $form.find('.shipping_address, .wcf-shipping-address-fade');
            if ($shippingWrapper.length) {
                // If FunnelKit has explicitly made the shipping panel block visible, validation is required
                return $shippingWrapper.is(':visible') || $shippingWrapper.css('display') === 'block';
            }

            // 3. Fallback: If no shipping panel structural wrappers are found at all, 
            // default safely to false so we don't block checkouts on digital-only / virtual checkouts.
            return false;
        },

        validatePOBox: function ($form) {
            const isShippingActive = this.getShippingState($form);
            
            const billing1 = $form.find('[name="billing_address_1"]').val();
            const billing2 = $form.find('[name="billing_address_2"]').val();
            
            if (this.containsPOBox(billing1) || this.containsPOBox(billing2)) {
                return 'PO Box addresses are not allowed for Billing.';
            }

            // Passively run PO Box check on shipping entries only if shipping state is verified active
            if (isShippingActive) {
                const shipping1 = $form.find('[name="shipping_address_1"]').val();
                const shipping2 = $form.find('[name="shipping_address_2"]').val();
                
                if (this.containsPOBox(shipping1) || this.containsPOBox(shipping2)) {
                    return 'PO Box addresses are not allowed for Shipping.';
                }
            }

            return null;
        },

        containsPOBox: function (value) {

            if (!value) {
                return false;
            }

            const cleaned = value
                .toLowerCase()
                .replace(/[^a-z0-9]/g, '');

            return (
                cleaned.includes('pobox')
                || cleaned.includes('postofficebox')
            );
        },

        getPhoneNumber: function ($form) {

            const selectors = [
                'input[name="billing_phone"]',
                'input[name="shipping_phone"]',
                'input[type="tel"]',
                'input[autocomplete="tel"]'
            ];

            for (let selector of selectors) {

                const val = $form.find(selector).first().val();

                if (val && val.trim()) {
                    return val.trim();
                }
            }

            return '';
        },

        getBillingEmail: function ($form) {

            const selectors = [
                '#billing_email',
                '#email',
                'input[type="email"]',
                'input[name="billing_email"]'
            ];

            for (let selector of selectors) {

                const val = $form.find(selector).first().val();

                if (val && val.trim()) {
                    return val.trim();
                }
            }

            return '';
        },

        isValidEmail: function (email) {

            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        isValidPhoneNumber: function (phone) {

            if (!phone) {
                return true;
            }

            const cleaned = phone.replace(
                /[\s\-().]/g,
                ''
            );

            return (
                /^(\+1|1)?\d{10}$/.test(cleaned)
                || /^(\+|00)[1-9]\d{6,14}$/.test(cleaned)
                || /^\+?\d{5,15}$/.test(cleaned)
            );
        },

        /* =========================================================
         * UI
         * ========================================================= */

        showCheckoutError: function (message, fields = []) {

            // Clear previous notices first
            $('.bytenft-error-wrap').remove();

            $('.wc-block-checkout__form .bytenft-error-wrap').remove();
            $('form.checkout .bytenft-error-wrap').remove();

            // Build fields list
            let fieldsHtml = '';

            if (fields.length) {

                fieldsHtml = `
                    <ul class="bytenft-error-fields">
                        ${fields.map(field => `<li>${field}</li>`).join('')}
                    </ul>
                `;
            }

            const html = `
                <div class="woocommerce-notices-wrapper bytenft-error-wrap">

                    <div class="woocommerce-error bytenft-error-box" role="alert">

                        <div class="bytenft-error-header">
                            <strong>${message}</strong>
                        </div>

                        ${fieldsHtml}

                    </div>

                </div>
            `;

            // Block checkout
            const blockTarget = $('.wc-block-checkout__form');

            if (blockTarget.length) {
                blockTarget.prepend(html);
            }

            // Classic checkout fallback
            const classicTarget = $('form.checkout');

            if (classicTarget.length) {
                classicTarget.prepend(html);
            }

            // Fallback
            if (!blockTarget.length && !classicTarget.length) {
                $('body').prepend(html);
            }

            // Scroll to top notice
            const $notice = $('.woocommerce-notices-wrapper');

            if ($notice.length) {

                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 300);
            }
        },

        clearCheckoutErrors: function () {

            $('.woocommerce-notices-wrapper').remove();

            $('.woocommerce-error').remove();

            $('.wc-block-components-notice-banner').remove();

            $('.woocommerce-message').remove();

            $('.woocommerce-info').remove();
        },

        reset: function (keepDisabled = false) {

            this.state.submitting = false;

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            const $blockButton = $(
                '.wc-block-components-checkout-place-order-button'
            );

            const $classicButton = $(
                'button[name="woocommerce_checkout_place_order"]'
            );

            const $button = $blockButton.length
                ? $blockButton
                : $classicButton;

            if (!$button.length) {
                return;
            }

            if (keepDisabled) {

                $button
                    .prop('disabled', true)
                    .addClass('loading')
                    .text('Processing...');

                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(
                    this.state.buttonText || 'Place order'
                );
        },

        /* =========================================================
         * SANITIZATION
         * ========================================================= */

        bindInputSanitization: function () {
            const selectors = `
                #billing_first_name,
                #billing-first_name,
                #billing_last_name,
                #billing-last_name,
                #billing_city,
                #billing-city,
                input[name="billing_first_name"],
                input[name="billing_last_name"],
                input[name="billing_city"]
            `;

            const sanitizeInput = (input) => {
                const clean = input.value.replace(
                    /[^A-Za-z\s]/g,
                    ''
                );

                if (input.value !== clean) {
                    Object.getOwnPropertyDescriptor(
                        HTMLInputElement.prototype,
                        'value'
                    ).set.call(input, clean);

                    input.dispatchEvent(
                        new Event('input', { bubbles: true })
                    );
                }
            };

            $(document).on(
                'input keyup blur change paste',
                selectors,
                function () {
                    const input = this;
                    setTimeout(() => {
                        sanitizeInput(input);
                    }, 0);
                }
            );
            

            $('#billing_address_1')
                .on('input', function () {

                    this.value = this.value.replace(
                        /[^A-Za-z0-9\s,.\-#]/g,
                        ''
                    );
                });
        }
    };

    $(document).ready(function () {

        BytenftCheckout.init();
    });

})(jQuery, window, document);