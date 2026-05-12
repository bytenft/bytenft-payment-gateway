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
            return email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
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
            if (email && !this.isValidEmail(email)) return 'Invalid email address';

            let phone = this.getPhoneNumber($form);
            if (phone && !this.isValidPhoneNumber(phone)) return 'Invalid phone number';

            let po = this.validatePOBox($form);
            if (po) return po;

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

                try {

                    if (!self.state.popup || self.state.popup.closed) {

                        clearInterval(self.state.popupInterval);

                        console.log('[Bytenft] popup closed detected');

                        $.post(bytenft_params.ajax_url, {
                            action: 'bytenft_popup_closed_event',
                            order_id: self.state.orderId,
                            security: bytenft_params.bytenft_nonce
                        }, function (response) {

                            console.log('[Bytenft] popup close response:', response);

                            if (
                                response &&
                                response.success &&
                                response.data &&
                                response.data.redirect_url
                            ) {
                                window.location.href = response.data.redirect_url;
                                return;
                            }

                            self.reset();
                        });
                    }

                } catch (e) {

                    clearInterval(self.state.popupInterval);
                    console.error('[Bytenft] popup tracking error', e);
                    self.reset();
                }

            }, 500);
        },

        /* =========================================================
         * MAIN FLOW (SAFE ORDER)
         * ========================================================= */

        handleFlow: function ($form, e) {

            const self = this;

            const isBlock = !!$form.find(
                'input[name="radio-control-wc-payment-method-options"]:checked'
            ).val();

            if (self.state.submitting) return;

            const error = self.validateAll($form);
            if (error) {
                alert(error);
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

            try {
                if (typeof res === 'string') {
                    try { res = JSON.parse(res); } catch (e) {}
                }

                if (res?.data?.redirect || res?.redirect) {

                    const url = res?.data?.redirect || res?.redirect;

                    this.state.orderId = res.order_id;

                    this.redirectPopup(url);

                     // track Popup Close
                    this.trackPopupClose();

                    return;
                }

                alert(res?.message || 'Payment failed');
                this.reset();

            } catch (e) {
                this.reset();
            }
        },

        /* =========================================================
         * REDIRECT
         * ========================================================= */

        redirectPopup: function (url) {

            try {
                if (this.state.popup && !this.state.popup.closed) {
                    this.state.popup.location.href = url;
                } else {
                    window.location.href = url;
                }
            } catch (e) {
                window.location.href = url;
            }
        },

        /* =========================================================
         * RESET
         * ========================================================= */

        reset: function () {
            this.state.submitting = false;

            if (this.state.button) {
                this.state.button.prop('disabled', false).text(this.state.buttonText);
            }
        }
    };

    $(document).ready(function () {
        BytenftCheckout.init();
    });

})(jQuery, window, document);