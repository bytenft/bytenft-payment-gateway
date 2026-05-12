(function ($, window, document, undefined) {

    'use strict';

    if (window.BytenftCheckoutInitialized) {
        return;
    }

    window.BytenftCheckoutInitialized = true;

    const BytenftCheckout = {

        state: {
            submitting: false,
            popup: null,
            orderId: null,
            button: null,
            buttonText: '',
            popupInterval: null,
            currentRequest: null
        },

        PAYMENT_METHOD: bytenft_params.payment_method,

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {
            this.bindEvents();
            this.track('event', 'init_loaded');
        },

        /* =========================================================
         * SILENT TELEMETRY (STRIPE-STYLE)
         * ========================================================= */

        track: function (event, data = {}) {

            if (!bytenft_params?.log_endpoint) return;

            const payload = {
                event,
                data,
                url: window.location.href,
                userAgent: navigator.userAgent,
                time: new Date().toISOString()
            };

            // NON-BLOCKING (Safari safe)
            try {
                navigator.sendBeacon(
                    bytenft_params.log_endpoint,
                    JSON.stringify(payload)
                );
            } catch (e) {}
        },

        /* =========================================================
         * DEBUG CONSOLE (MINIMAL)
         * ========================================================= */

        log: function (type, message, data) {

            const DEBUG = !!bytenft_params?.debug;

            if (!DEBUG) return;

            const prefix = '[BytenftCheckout]';

            if (type === 'error') {
                console.error(prefix, message, data || '');
            } else if (type === 'warn') {
                console.warn(prefix, message, data || '');
            } else {
                console.log(prefix, message, data || '');
            }
        },

        /* =========================================================
         * EVENTS
         * ========================================================= */

        bindEvents: function () {

            const self = this;

            $(document)
                .off('click.bytenft')
                .on(
                    'click.bytenft',
                    'button[name="woocommerce_checkout_place_order"], .wc-block-components-checkout-place-order-button',
                    function (e) {

                        const $form = $(this).closest('form');

                        const selected = $form.find(
                            'input[name="payment_method"]:checked, input[name="radio-control-wc-payment-method-options"]:checked'
                        ).val();

                        if (selected !== self.PAYMENT_METHOD) {
                            return;
                        }

                        e.preventDefault();

                        self.track('event', 'checkout_clicked');

                        self.startPaymentFlow(e, $form);
                    }
                );

            $(document.body).on('updated_checkout.bytenft', function () {
                self.bindEvents();
            });
        },

        /* =========================================================
         * VALIDATION HELPERS (UNCHANGED)
         * ========================================================= */

        getPhoneNumber: function ($form) {

            const selectors = [
                'input[name="billing_phone"]',
                'input[name="shipping_phone"]',
                'input[autocomplete="tel"]',
                'input[type="tel"]'
            ];

            for (let s of selectors) {
                const val = $form.find(s).first().val();
                if (val && val.trim()) return val.trim();
            }

            return '';
        },

        isValidPhoneNumber: function (phone) {

            if (!phone || phone.trim() === '') return true;

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
                const val = $form.find(s).first().val();
                if (val && val.trim()) return val.trim();
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

                    this.track('validation_failed', { reason: 'po_box' });

                    return 'PO Box addresses are not accepted. Please enter a physical street address.';
                }
            }

            return null;
        },

        validateAll: function ($form) {

            let email = this.getBillingEmail($form);

            if (email && !this.isValidEmail(email)) {

                this.track('validation_failed', { reason: 'email' });

                return 'Invalid email address';
            }

            let phone = this.getPhoneNumber($form);

            if (phone && !this.isValidPhoneNumber(phone)) {

                this.track('validation_failed', { reason: 'phone' });

                return 'Invalid phone number';
            }

            let po = this.validatePOBox($form);

            if (po) return po;

            return null;
        },

        /* =========================================================
         * POPUP (SAFARI SAFE)
         * ========================================================= */

        openPopupImmediately: function () {

            try {

                if (this.state.popup && !this.state.popup.closed) {
                    return this.state.popup;
                }

                this.state.popup = window.open('', '_blank', 'width=700,height=700');

                if (!this.state.popup) {

                    this.track('popup_blocked');

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

                this.track('popup_opened');

                return this.state.popup;

            } catch (e) {

                this.track('popup_error', { error: e.message });

                return null;
            }
        },

        redirectPopup: function (url) {

            try {

                this.track('redirect', { url });

                if (this.state.popup && !this.state.popup.closed) {
                    this.state.popup.location.href = url;
                    this.state.popup.focus();
                } else {
                    window.location.href = url;
                }

            } catch (e) {

                this.track('redirect_fallback', { error: e.message });

                window.location.href = url;
            }
        },

        /* =========================================================
         * BUTTON STATE
         * ========================================================= */

        setButtonLoading: function (isBlock) {

            if (!this.state.button) return;

            this.state.button.prop('disabled', true);

            if (!isBlock) {
                this.state.button.text('Processing...');
            }

            if (isBlock) {
                this.state.button.attr('aria-busy', 'true');
                this.state.button.addClass('is-busy');
            }
        },

        resetButton: function () {

            if (!this.state.button) return;

            this.state.button.prop('disabled', false);

            this.state.button.removeAttr('aria-busy');
            this.state.button.removeClass('is-busy');

            if (this.state.buttonText) {
                this.state.button.text(this.state.buttonText);
            }
        },

        /* =========================================================
         * MAIN FLOW
         * ========================================================= */

        startPaymentFlow: function (e, $form) {

            const self = this;

            if (self.state.submitting) {
                self.track('duplicate_submit_blocked');
                return false;
            }

            const error = self.validateAll($form);

            if (error) {
                self.track('validation_failed', { error });
                return false;
            }

            const popup = self.openPopupImmediately();
            if (!popup) return false;

            self.state.submitting = true;

            const isBlock = !!$form.find(
                'input[name="radio-control-wc-payment-method-options"]:checked'
            ).val();

            self.state.button = isBlock
                ? $('.wc-block-components-checkout-place-order-button')
                : $form.find('button[name="woocommerce_checkout_place_order"]');

            self.state.buttonText = $.trim(self.state.button.text());

            self.setButtonLoading(isBlock);

            const ajaxUrl = isBlock
                ? bytenft_params.ajax_url
                : wc_checkout_params.checkout_url;

            const ajaxData = isBlock
                ? {
                    action: 'bytenft_block_gateway_process',
                    nonce: bytenft_params.bytenft_nonce
                }
                : $form.serialize();

            if (self.state.currentRequest) {
                self.state.currentRequest.abort();
            }

            self.state.currentRequest = $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: ajaxData,

                success: function (res) {

                    self.track('ajax_success');

                    self.handleResponse(res, $form);
                },

                error: function (err) {

                    self.track('ajax_error', { err });

                    self.state.submitting = false;
                },

                complete: function () {
                    self.state.submitting = false;
                }
            });

            return false;
        },

        /* =========================================================
         * RESPONSE
         * ========================================================= */

        handleResponse: function (res, $form) {

            try {

                if (typeof res === 'string') {
                    try { res = JSON.parse(res); } catch (e) {}
                }

                if (res?.data?.redirect || res?.redirect) {

                    const url = res?.data?.redirect || res?.redirect;

                    this.state.orderId = res.order_id;

                    this.track('redirect_success', { url });

                    this.redirectPopup(url);

                    this.trackPopupClose();

                    return;
                }

                this.track('payment_failed', res);

                this.state.submitting = false;

            } catch (e) {

                this.track('response_error', { error: e.message });

                this.state.submitting = false;
            }
        },

        /* =========================================================
         * POPUP TRACKING
         * ========================================================= */

        trackPopupClose: function () {

            const self = this;

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {

                try {

                    if (!self.state.popup || self.state.popup.closed) {

                        clearInterval(self.state.popupInterval);

                        self.track('popup_closed', {
                            orderId: self.state.orderId
                        });

                        $.post(bytenft_params.ajax_url, {
                            action: 'bytenft_popup_closed_event',
                            order_id: self.state.orderId,
                            security: bytenft_params.bytenft_nonce
                        }, function (response) {

                            try {

                                const isBlockSelected =
                                    $('input[name="radio-control-wc-payment-method-options"]:checked').val()
                                    === bytenft_params.payment_method;

                                /*
                                * Refresh classic checkout fragments
                                */
                                if (!isBlockSelected) {
                                    $(document.body).trigger('update_checkout');
                                }

                                /*
                                * SUCCESS REDIRECT
                                */
                                if (
                                    response &&
                                    response.success &&
                                    response.data &&
                                    response.data.redirect_url
                                ) {

                                    self.track('redirect_to_thankyou', {
                                        orderId: self.state.orderId
                                    });

                                    window.location.replace(
                                        response.data.redirect_url
                                    );

                                    return;
                                }

                                /*
                                * OPTIONAL ERROR NOTICE
                                */
                                if (
                                    response &&
                                    response.data &&
                                    response.data.notices
                                ) {

                                    self.track('popup_closed_with_notice');

                                    alert(response.data.notices);
                                }

                            } catch (e) {

                                self.track('popup_close_response_error', {
                                    error: e.message
                                });
                            }

                            self.resetButton();
                            self.state.submitting = false;

                        }, 'json');
                    }

                } catch (e) {

                    clearInterval(self.state.popupInterval);

                    self.track('popup_tracking_error', {
                        error: e.message
                    });

                    self.resetButton();

                    self.state.submitting = false;
                }

            }, 500);
        },
    };

    $(document).ready(function () {
        BytenftCheckout.init();
    });

})(jQuery, window, document);