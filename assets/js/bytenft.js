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
                // ✅ SUCCESS
                // =====================================================
                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    if (self.state.popup && !self.state.popup.closed) {

                        try {

                            self.state.popup.location.href = redirect;

                        } catch (e) {

                            // Safari fallback
                            self.state.popup = window.open(
                                redirect,
                                '_blank'
                            );
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

            let redirected = false;

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {

                // Prevent duplicate execution
                if (redirected) {
                    return;
                }

                const popupExists =
                    self.state.popup &&
                    !self.state.popup.closed;

                // =========================================
                // SAFARI + CHROME PAYMENT CHECK
                // =========================================
                $.post(
                    bytenft_params.ajax_url,
                    {
                        action: 'bytenft_popup_closed_event',
                        order_id: self.state.orderId,
                        security: bytenft_params.bytenft_nonce
                    },
                    function (response) {

                        if (redirected) {
                            return;
                        }

                        console.log(
                            '[Bytenft] popup status response',
                            response
                        );

                        const paymentSuccess =
                            response?.success === true ||
                            response?.data?.payment_status === 'success' ||
                            response?.data?.payment_status === 'paid';

                        const redirectUrl =
                            response?.data?.redirect ||
                            response?.redirect;

                        // =========================================
                        // PAYMENT SUCCESS
                        // =========================================
                        if (paymentSuccess && redirectUrl) {

                            redirected = true;

                            clearInterval(self.state.popupInterval);

                            try {

                                if (
                                    self.state.popup &&
                                    !self.state.popup.closed
                                ) {
                                    self.state.popup.close();
                                }

                            } catch (e) {
                                console.log(
                                    '[Bytenft] popup close blocked'
                                );
                            }

                            self.state.popup = null;

                            console.log(
                                '[Bytenft] redirect success page'
                            );

                            window.location.replace(redirectUrl);

                            return;
                        }

                        // =========================================
                        // USER MANUALLY CLOSED POPUP
                        // =========================================
                        if (!popupExists) {

                            redirected = true;

                            clearInterval(self.state.popupInterval);

                            const failedMessage =
                                response?.message ||
                                response?.data?.message ||
                                'Your payment could not be completed. Please try again.';

                            self.cleanupPopup();

                            self.showCheckoutError(failedMessage);

                            self.reset();
                        }

                    },
                    'json'
                );

            }, 1500);
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

        validatePOBox: function ($form) {

            const fields = [

                $form.find('#billing_address_1').val(),

                $form.find('#billing_address_2').val(),

                $form.find('#shipping_address_1').val(),

                $form.find('#shipping_address_2').val()
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