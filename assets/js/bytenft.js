(function ($, window, document) {
    'use strict';

    if (window.BytenftCheckoutInitialized) return;
    window.BytenftCheckoutInitialized = true;

    const BytenftCheckout = {

        state: {
            submitting: false,
            popup: null,
            orderId: null,
            button: null,
            buttonText: '',
            currentRequest: null,
            popupInterval: null
        },

        PAYMENT_METHOD: bytenft_params.payment_method,

        /* =========================================================
         * INIT
         * ========================================================= */
        init: function () {
            this.bindEvents();
        },

        /* =========================================================
         * POPUP (CRITICAL FIXED FLOW)
         * ========================================================= */
        openPopupImmediately: function () {
            try {
                if (this.state.popup && !this.state.popup.closed) {
                    return this.state.popup;
                }

                this.state.popup = window.open('', '_blank', 'width=700,height=700');

                if (!this.state.popup) {
                    alert("Popup blocked. Please allow popups.");
                    return null;
                }

                this.state.popup.document.write(`
                    <html>
                        <head><title>Secure Payment</title></head>
                        <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;">
                            <div>Connecting...</div>
                        </body>
                    </html>
                `);

                this.state.popup.document.close();
                return this.state.popup;

            } catch (e) {
                return null;
            }
        },

        /* =========================================================
         * HELPERS (UNCHANGED BUSINESS LOGIC)
         * ========================================================= */

        getPhoneNumber: function ($form) {
            const selectors = [
                'input[name="billing_phone"]',
                'input[name="shipping_phone"]',
                'input[autocomplete="tel"]',
                'input[type="tel"]'
            ];

            for (let s of selectors) {
                const v = $form.find(s).first().val();
                if (v && v.trim()) return v.trim();
            }
            return '';
        },

        isValidPhoneNumber: function (phone) {
            if (!phone) return true;
            const cleaned = phone.replace(/[\s\-().]/g, '');
            return /^(\+1|1)?\d{10}$/.test(cleaned)
                || /^(\+|00)[1-9]\d{6,14}$/.test(cleaned)
                || /^\+?\d{7,20}$/.test(cleaned);
        },

        isValidEmail: function (email) {
            if (!email) return false;
            email = email.trim();
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        containsPOBox: function (v) {
            const c = v.replace(/[^a-z0-9]/gi, '').toLowerCase();
            return /pob|postoffice/.test(c);
        },

        getBillingEmail: function ($form) {
            const selectors = [
                '#billing_email',
                '#email',
                'input[type="email"]',
                'input[autocomplete="email"]',
                'input[name="billing_email"]'
            ];

            for (let s of selectors) {
                const v = $form.find(s).first().val();
                if (v && v.trim()) return v.trim();
            }
            return '';
        },

        validatePOBox: function ($form) {
            const fields = [
                $form.find('#billing_address_1').val(),
                $form.find('#billing_address_2').val(),
                $form.find('#shipping_address_1').val(),
                $form.find('#shipping_address_2').val(),
                ...$form.find('input[name*="address"]').map(function () {
                    return $(this).val();
                }).get()
            ];

            for (let f of fields) {
                if (f && this.containsPOBox(f)) {
                    return 'PO Box addresses are not accepted.';
                }
            }
            return null;
        },

        validateAll: function ($form) {

            let email = this.getBillingEmail($form);

            // ✅ Required email check
            if (!email || !email.trim()) {
                return 'Please enter your email address.';
            }

            // ✅ Format validation
            if (!this.isValidEmail(email)) {
                return 'Please enter a valid email address.';
            }

            let phone = this.getPhoneNumber($form);

            if (phone && !this.isValidPhoneNumber(phone)) {
                return 'Please enter a valid phone number.';
            }

            let po = this.validatePOBox($form);

            if (po) {
                return po;
            }

            return null;
        },

        validateRequiredFields: function ($form) {

            let missing = [];

            $form.find('[required]').each(function () {

                const $field = $(this);

                // ignore hidden fields
                if (!$field.is(':visible')) {
                    return;
                }

                const val = ($field.val() || '').trim();

                if (!val) {

                    const label =
                        $field.closest('.form-row, .wc-block-components-text-input')
                            .find('label')
                            .first()
                            .text()
                            .replace('*', '')
                            .trim();

                    missing.push(label || 'Required field');

                    $field.addClass('woocommerce-invalid');
                } else {
                    $field.removeClass('woocommerce-invalid');
                }
            });

            if (missing.length) {
                return 'Please fill in all required fields.';
            }

            return null;
        },

        /* =========================================================
         * EVENTS (FIXED STABLE HANDLING)
         * ========================================================= */

       bindEvents: function () {

            const self = this;

            console.log('[Bytenft] bindEvents initialized');

            // Classic checkout (keep as-is if working)
            $('form.checkout')
                .off('submit.bytenft')
                .on('submit.bytenft', function (e) {

                    const selected = $(this)
                        .find('input[name="payment_method"]:checked')
                        .val();

                    if (selected !== self.PAYMENT_METHOD) return true;

                    e.preventDefault();
                    self.handleFlow($(this), e);
                    return false;
                });

            /* =========================================================
            * BLOCK CHECKOUT FIX (CAPTURE PHASE - IMPORTANT)
            * ========================================================= */

           document.addEventListener(
            'click',
            function (e) {

                const btn = e.target.closest('.wc-block-components-checkout-place-order-button');
                if (!btn) return;

                const $form = $('form.wc-block-checkout__form');

                const selected = $form
                    .find('input[name="radio-control-wc-payment-method-options"]:checked')
                    .val();

                if (selected !== self.PAYMENT_METHOD) return;

                // ❗ FULL STOP WOOCOMMERCE
                e.preventDefault();
                e.stopImmediatePropagation();

                console.log('[Bytenft] full override checkout');

               const popup = window.open('about:blank', '_blank', 'width=700,height=700');

                console.log('[Bytenft] popup created:', popup);

                if (!popup) {
                    alert('Popup blocked. Please enable popups.');
                    return;
                }

                self.state.popup = popup;

                // 🚨 IMPORTANT: open document stream FIRST
                popup.document.open();

                const logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';

                // ✅ FULL STABLE HTML
                popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Secure Payment</title>
                </head>

                <body style="margin:0; display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; font-family:sans-serif; background:#ffffff; text-align:center;">
                    <div style="padding:20px;">

                        ${logoUrl ? `<img src="${logoUrl}" />` : ''}

                        <h2>Secure Payment Processing</h2>

                        <p>Please do not close or refresh this window.</p>

                        <div class="spinner"></div>

                    </div>
                </body>
                </html>
                `);

                popup.document.close();
                popup.focus();

                console.log('[Bytenft] secure popup rendered successfully');

                // 🚀 YOU MUST HANDLE EVERYTHING YOURSELF
                self.handleFlow($form, e);
            },
            true
        );
        },

        trackPopupClose: function () {

            const self = this;

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {

                if (!self.state.popup || self.state.popup.closed) {

                    clearInterval(self.state.popupInterval);

                    self.state.popup = null;

                    $.post(bytenft_params.ajax_url, {
                        action: 'bytenft_popup_closed_event',
                        order_id: self.state.orderId,
                        security: bytenft_params.bytenft_nonce
                    }, function (response) {

                        // Optional Woo refresh (only for block checkout UX)
                        const isBlockSelected =
                            $('input[name="radio-control-wc-payment-method-options"]:checked').val()
                            === bytenft_params.payment_method;

                        if (!isBlockSelected) {
                            $(document.body).trigger('update_checkout');
                        }

                        const $targetForm = isBlockSelected
                            ? $('form.wc-block-checkout__form')
                            : $('form.checkout');

                        // ✅ SUCCESS → redirect WordPress page
                        if (response?.success && response?.data?.redirect) {

                            const url = response.data.redirect;

                            // final redirect (WordPress context)
                            window.location.replace(url);
                            return;
                        }

                        // ❌ ERROR HANDLING
                        if (response?.message) {
                            self.showCheckoutError(response.message);
                        } else if (response?.data?.message) {
                            self.showCheckoutError(response.data.message);
                        } else if (response?.data?.notices) {
                            self.showCheckoutError(response.data.notices);
                        } else {
                            self.showCheckoutError('Payment failed.');
                        }

                        self.reset();

                    }, 'json');
                }

            }, 500);
        },

        showCheckoutError: function (message) {

            // Clear previous notices first
            $('.woocommerce-notices-wrapper').remove();

            const html = `
                <div class="woocommerce-notices-wrapper">
                    <ul class="woocommerce-error" role="alert">
                        <li>${message}</li>
                    </ul>
                </div>
            `;

            // Block checkout
            const blockTarget = $('.wc-block-checkout__form');

            if (blockTarget.length) {
                blockTarget.prepend(html);
                return;
            }

            // Classic checkout fallback
            const classicTarget = $('form.checkout');

            if (classicTarget.length) {
                classicTarget.prepend(html);
            }
        },

        /* =========================================================
         * MAIN FLOW (SAFE ORDER)
         * ========================================================= */

        handleFlow: function ($form, e) {

            const self = this;

            // ✅ CLEAR OLD ERRORS FIRST
            self.clearCheckoutErrors();

            const isBlock = !!$form.find(
                'input[name="radio-control-wc-payment-method-options"]:checked'
            ).val();

            if (self.state.submitting) return;

            // Required fields validation first
            const requiredError = self.validateRequiredFields($form);

            if (requiredError) {

                self.showCheckoutError(requiredError);

                // close popup
                if (self.state.popup && !self.state.popup.closed) {
                    try {
                        self.state.popup.close();
                    } catch (e) {}
                }

                self.state.popup = null;
                self.reset();

                return;
            }

            // Custom validations
            const error = self.validateAll($form);
            if (error) {
                self.showCheckoutError(error);

                // 🔥 reset full flow state
                self.state.submitting = false;

                if (self.state.button) {
                    self.state.button.prop('disabled', false).text(self.state.buttonText);
                }

                // 🚨 close popup if opened
                if (self.state.popup && !self.state.popup.closed) {
                    try {
                        self.state.popup.close();
                    } catch (e) {}
                }

                self.state.popup = null;

                return;
            }

            self.state.submitting = true;

            self.state.button = isBlock
                ? $('.wc-block-components-checkout-place-order-button')
                : $form.find('button[name="woocommerce_checkout_place_order"]').first();

            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).text('Processing...');

            if (self.state.currentRequest) {
                self.state.currentRequest.abort();
            }

            const ajaxUrl = bytenft_params.ajax_url;

            const ajaxData = isBlock
                ? { action: 'bytenft_block_gateway_process', nonce: bytenft_params.bytenft_nonce }
                : $form.serialize();

            self.state.currentRequest = $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: ajaxData,

                success: function (res) {
                    self.handleResponse(res);
                },

                error: function () {
                    self.reset();
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

        handleResponse: function (res) {

            const self = this;

            try {

                // -----------------------------
                // SAFE PARSE
                // -----------------------------
                if (typeof res === 'string') {
                    try {
                        res = JSON.parse(res);
                    } catch (e) {
                        self.showCheckoutError('Invalid server response');
                        self.cleanupPopup();
                        self.reset();
                        return;
                    }
                }

                console.log('[Bytenft] Raw response:', res);

                // -----------------------------
                // NORMALIZE
                // -----------------------------
                const isSuccess =
                    res?.success === true ||
                    res?.success === 'true' ||
                    res?.success === 1 ||
                    res?.result === 'success';

                const message =
                    res?.message ||
                    res?.data?.message ||
                    'Payment failed';

                const redirectUrl =
                    res?.data?.redirect ||
                    res?.redirect ||
                    null;

                const orderId =
                    res?.data?.order_id ||
                    res?.order_id ||
                    null;

                console.log('[Bytenft] Parsed:', {
                    isSuccess,
                    redirectUrl,
                    orderId
                });

                // -----------------------------
                // STORE ORDER ID
                // -----------------------------
                this.state.orderId = orderId;

                // -----------------------------
                // FAILURE CASE (IMPORTANT FIX)
                // -----------------------------
                if (!isSuccess) {

                    // 🔥 CLOSE POPUP ON FAILURE
                    self.cleanupPopup();

                    self.showCheckoutError(message);
                    self.reset();
                    return;
                }

                // -----------------------------
                // SUCCESS CASE
                // -----------------------------

                // 🔥 KEEP BUTTON LOCKED
                self.reset(true);
                
                const popup = self.state.popup;

                // -----------------------------
                // POPUP FLOW
                // -----------------------------
                if (popup && !popup.closed) {

                    if (redirectUrl) {
                        try {
                            popup.location.href = redirectUrl;
                        } catch (e) {
                            window.open(redirectUrl, '_blank');
                        }
                    }

                    this.trackPopupClose();
                    return;
                }

                // -----------------------------
                // FALLBACK
                // -----------------------------
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }

                self.showCheckoutError('Missing redirect URL');
                self.cleanupPopup();
                self.reset();

            } catch (e) {
                console.error('[Bytenft] handleResponse error:', e);
                self.showCheckoutError('Unexpected error occurred.');
                self.cleanupPopup();
                self.reset();
            }
        },

        cleanupPopup: function () {
            if (this.state.popup && !this.state.popup.closed) {
                try {
                    this.state.popup.close();
                } catch (e) {}
            }
            this.state.popup = null;
        },

        clearCheckoutErrors: function () {

            // Classic notices
            $('.woocommerce-notices-wrapper').remove();

            // WooCommerce error blocks
            $('.woocommerce-error').remove();

            // Block checkout notices
            $('.wc-block-components-notice-banner').remove();

            // Store API validation errors
            $('.wc-block-store-notice').remove();

            // Generic notices
            $('.woocommerce-message').remove();
            $('.woocommerce-info').remove();

            // Remove field validation classes
            $('.woocommerce-invalid').removeClass(
                'woocommerce-invalid woocommerce-invalid-required-field woocommerce-invalid-email'
            );
        },

        /* =========================================================
         * RESET
         * ========================================================= */

        reset: function (keepProcessing = false) {

            this.state.submitting = false;

            // 🔥 Always re-fetch latest button from DOM
            const $blockBtn = $('.wc-block-components-checkout-place-order-button');
            const $classicBtn = $('button[name="woocommerce_checkout_place_order"]');

            const $btn = $blockBtn.length ? $blockBtn : $classicBtn;

            if (!$btn.length) {
                return;
            }

            // 🔥 KEEP PROCESSING STATE
            if (keepProcessing) {

                $btn
                    .prop('disabled', true)
                    .attr('disabled', 'disabled')
                    .addClass('loading')
                    .text('Processing...');

                return;
            }

            // Normal reset
            $btn
                .prop('disabled', false)
                .removeAttr('disabled')
                .removeClass('loading')
                .text(this.state.buttonText || 'Place order');
        }
    };

    $(document).ready(function () {
        BytenftCheckout.init();
    });

})(jQuery, window, document);