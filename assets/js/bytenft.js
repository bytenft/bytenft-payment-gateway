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

            const self = this;

            self.bindClassicCheckout();
            self.bindBlockCheckout();
            self.bindInputSanitization();

            // 🔥 FunnelKit safety: rebind on DOM changes
           const observer = new MutationObserver(function () {

                self.bindInputSanitization();

                // rebind checkout safely (FunnelKit replaces DOM)
                $(document.body)
                    .off('checkout_place_order_' + self.PAYMENT_METHOD);

                self.bindClassicCheckout();

            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            console.log('[Bytenft] initialized (FunnelKit safe)');
        },

        getCheckoutForm: function () {

            let $form = $(
                'form.checkout, form.woocommerce-checkout, form.fkwc-checkout form'
            );

            if ($form.length) {
                return $form.first();
            }

            return $('form').filter(function () {

                const $f = $(this);

                return (
                    $f.find('#billing_email').length ||
                    $f.find('[name="billing_email"]').length ||
                    $f.find('button[name="woocommerce_checkout_place_order"]').length
                );

            }).first();
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {

            const self = this;

            $(document.body)
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on(
                    'checkout_place_order_' + self.PAYMENT_METHOD,
                    function () {

                        console.log('[Bytenft] classic checkout');

                        const $form = self.getCheckoutForm();

                        if (self.state.submitting) {
                            return false;
                        }

                        self.clearCheckoutErrors();

                        const requiredError = self.validateRequiredFields($form);

                        if (requiredError) {

                            self.showCheckoutError(
                                requiredError.message,
                                requiredError.fields
                            );

                            return false;
                        }

                        const validationError = self.validateAll($form);

                        if (validationError) {

                            self.showCheckoutError(validationError);

                            return false;
                        }

                        // Safari popup fix
                        self.openPopupImmediately();

                        // Start custom flow
                        self.handleClassicCheckout($form);

                        // STOP WooCommerce default flow
                        return false;
                    }
                );
        },

        handleClassicCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

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
                        '.wc-block-components-checkout-place-order-button, .fkwc-place-order, .place-order button'
                    );

                    if (!btn) {
                        return;
                    }

                    const $form = self.getCheckoutForm();

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

                    const requiredError = self.validateRequiredFields($form);

                    if (requiredError) {

                        self.showCheckoutError(
                            requiredError.message,
                            requiredError.fields
                        );

                        return;
                    }

                    const validationError = self.validateAll($form);

                    if (validationError) {

                        self.showCheckoutError(validationError);

                        return;
                    }

                    // Safari popup fix
                    self.openPopupImmediately();

                    // Start block flow
                    self.createBlockOrder($form);

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

        createBlockOrder: function ($form) {

            const self = this;

            self.state.submitting = true;

            $.ajax({

                type: 'POST',

                url: bytenft_params.ajax_url,

                data: {
                    action: 'bytenft_create_block_order',
                    nonce: bytenft_params.bytenft_nonce,
                    checkout_data: $form.serialize()
                },

                success: function (response) {

                    console.log('[Bytenft] create order response', response);

                    if (!response || !response.success || !response.data?.order_id) {

                        self.showCheckoutError(
                            response?.message || 'Unable to create order.'
                        );

                        self.cleanupPopup();
                        self.reset();

                        return;
                    }

                    self.state.orderId = response.data.order_id;

                    // IMPORTANT FIX
                    self.state.submitting = false;

                    // Continue payment flow
                    self.handleBlockCheckout($form);
                },

                error: function () {

                    self.showCheckoutError(
                        'Unable to initialize order.'
                    );

                    self.cleanupPopup();
                    self.reset();
                }
            });
        },

        handleBlockCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

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

            const orderId = self.state.orderId;

            // ✅ SAFE GUARD (improved UX + reset state)
            if (!orderId) {

                self.state.orderId = null;

                console.error('[Bytenft] Missing order_id');

                self.showCheckoutError(
                    'Order could not be initialized. Please refresh and try again.'
                );

                self.cleanupPopup();
                self.reset();

                return;
            }

            data += '&order_id=' + encodeURIComponent(orderId);

            $.ajax({

                type: 'POST',
                url: bytenft_params.ajax_url,
                data: data,

                success: function (response) {

                    console.log('[Bytenft] block response', response);

                    self.handleResponse(response);
                },

                error: function (xhr) {

                    console.log('[Bytenft] block ajax error');
                    console.log(xhr.responseText);

                    self.showCheckoutError(
                        'There was an error processing your order. Please try again.'
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

                // =====================================================
                // 1. SAFE JSON PARSE
                // =====================================================
                if (typeof response === 'string') {

                    try {
                        response = JSON.parse(response);
                    } catch (e) {

                        console.log('[Bytenft] invalid json response');

                        self.cleanupPopup();
                        self.reset();

                        self.showCheckoutError('Invalid server response.');

                        return;
                    }
                }

                console.log('[Bytenft] parsed response', response);

                // =====================================================
                // 2. EXTRACT CORE DATA SAFELY
                // =====================================================
                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect =
                    response?.redirect ||
                    response?.data?.redirect ||
                    null;

                const orderId =
                    response?.order_id ||
                    response?.data?.order_id ||
                    self.state.orderId ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your payment could not be completed. Please try again.';

                // =====================================================
                // 3. UPDATE STATE SAFELY
                // =====================================================
                if (orderId) {
                    self.state.orderId = orderId;
                }

                // =====================================================
                // 4. FAILURE HANDLING (STABLE UI)
                // =====================================================
                if (!success) {

                    console.log('[Bytenft] payment failed:', errorMessage);

                    self.cleanupPopup();

                    setTimeout(function () {

                        self.showCheckoutError(errorMessage);

                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 250);
                        }

                    }, 50);

                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // 5. SUCCESS HANDLING
                // =====================================================
                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    if (self.state.popup && !self.state.popup.closed) {

                        try {
                            self.state.popup.location.href = redirect;
                        } catch (e) {
                            window.location.href = redirect;
                        }

                        self.trackPopupClose();

                    } else {
                        window.location.href = redirect;
                    }

                    self.reset(true);
                    return;
                }

                // =====================================================
                // 6. EDGE CASE: NO REDIRECT
                // =====================================================
                console.warn('[Bytenft] Missing redirect URL');

                self.cleanupPopup();

                self.showCheckoutError('Payment completed but redirect missing.');

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

            this.state.popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
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

        cleanupPopup: function () {

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

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {

                if (
                    !self.state.popup ||
                    self.state.popup.closed
                ) {

                    clearInterval(self.state.popupInterval);

                    self.state.popup = null;

                    $.post(
                        bytenft_params.ajax_url,
                        {
                            action: 'bytenft_popup_closed_event',
                            order_id: self.state.orderId,
                            security: bytenft_params.bytenft_nonce
                        },
                        function (response) {

                            console.log(
                                '[Bytenft] popup close response',
                                response
                            );

                            const paymentSuccess =
                                response?.success === true ||
                                response?.data?.payment_status === 'success' ||
                                response?.data?.payment_status === 'paid';

                            const redirectUrl =
                                response?.data?.redirect ||
                                response?.redirect ||
                                null;

                            if (
                                paymentSuccess &&
                                redirectUrl
                            ) {

                                console.log('[Bytenft] redirecting to thank you page');

                                window.location.replace(redirectUrl);

                                return;
                            }

                            const failedMessage =
                                response?.message ||
                                response?.data?.message ||
                                'Your payment could not be completed. Please try again.';

                            console.log('[Bytenft] popup failed:', failedMessage);

                            self.cleanupPopup();

                            self.showCheckoutError(failedMessage);

                            self.reset();
                        },
                        'json'
                    );
                }

            }, 500);
        },

        /* =========================================================
         * VALIDATIONS
         * ========================================================= */

        validateAll: function ($form) {

            const email = this.getBillingEmail($form);

            if (!email) {
                return 'Please enter your email address.';
            }

            if (!this.isValidEmail(email)) {
                return 'Please enter a valid email address.';
            }

            const phone = this.getPhoneNumber($form);

            if (
                phone &&
                !this.isValidPhoneNumber(phone)
            ) {
                return 'Please enter a valid phone number.';
            }

            const poBox = this.validatePOBox($form);

            if (poBox) {
                return poBox;
            }

            return null;
        },

        validateRequiredFields: function ($form) {

            let missing = [];

            let firstInvalid = null;

            $form.find('[required]').each(function () {

                const $field = $(this);

                if (
                    !$field.is(':visible') ||
                    $field.attr('type') === 'hidden'
                ) {
                    return;
                }

                const val = ($field.val() || '').trim();

                const $wrapper = $field.closest(
                    '.form-row, .wc-block-components-text-input'
                );

                if (!val) {

                    $wrapper.addClass(
                        'woocommerce-invalid woocommerce-invalid-required-field'
                    );

                    let label =
                        $wrapper.find('label').first().text().trim()
                        || $field.attr('placeholder')
                        || $field.attr('name');

                    label = label
                        .replace('*', '')
                        .trim();

                    if (
                        label &&
                        !missing.includes(label)
                    ) {
                        missing.push(label);
                    }

                    if (!firstInvalid) {
                        firstInvalid = $field;
                    }

                } else {

                    $wrapper.removeClass(
                        'woocommerce-invalid woocommerce-invalid-required-field'
                    );
                }
            });

            if (firstInvalid) {

                setTimeout(function () {
                    firstInvalid.trigger('focus');
                }, 100);
            }

            if (missing.length) {

                return {
                    message: 'Please fill required fields.',
                    fields: missing
                };
            }

            return null;
        },

        getFieldValue: function ($form, selectors) {

            for (let selector of selectors) {

                const $field = $form.find(selector).first();

                if ($field.length) {

                    const val = ($field.val() || '').trim();

                    if (val) return val;
                }
            }

            return '';
        },

        validatePOBox: function ($form) {

            const fields = [

                $form.find('#billing_address_1').val(),

                $form.find('#billing_address_2').val(),
            ];

            for (let field of fields) {

                if (
                    field &&
                    this.containsPOBox(field)
                ) {

                    return 'PO Box addresses are not allowed.';
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

            return this.getFieldValue($form, [
                'input[name="billing_phone"]',
                'input[type="tel"]',
                'input[autocomplete="tel"]'
            ]);
        },

        getBillingEmail: function ($form) {

            return this.getFieldValue($form, [
                '#billing_email',
                'input[name="billing_email"]',
                'input[type="email"]',
                '#email'
            ]);
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
            $('.woocommerce-notices-wrapper').remove();

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

            const funnelkitTarget = $('.fkwc-checkout, .fkwc-step, .fkwc-order-review');

            if (funnelkitTarget.length) {
                funnelkitTarget.first().prepend(html);
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

            $('.bytenft-error-wrap').remove();

            $('.woocommerce-error').remove();

            $('.wc-block-components-notice-banner').remove();

            $('.woocommerce-message').remove();

            $('.woocommerce-info').remove();
        },

        reset: function (keepDisabled = false) {

            this.state.submitting = false;

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

            $('#billing_first_name, #billing_last_name, #billing_city')
                .on('input', function () {

                    this.value = this.value.replace(
                        /[^A-Za-z\s]/g,
                        ''
                    );
                });

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