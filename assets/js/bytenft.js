(function ($, window, document) {
    'use strict';

    if (window.BytenftCheckoutInitialized) {
        return;
    }

    window.BytenftCheckoutInitialized = true;

    const CHECK_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M5 12.5l4.5 4.5L19 7.5"></path>' +
        '</svg>';

    const SHIELD_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M12 3l7 3v6c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9V6l7-3z"></path>' +
        '</svg>';

    const BytenftCheckout = {

        PAYMENT_METHOD: bytenft_params.payment_method,

        state: {
            submitting: false,
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

            // Extract email and phone directly to avoid Store API sync race conditions
            let emailField = document.querySelector('input[type="email"], #email');
            if (emailField && emailField.value) {
                data += '&billing_email=' + encodeURIComponent(emailField.value);
            }
            let phoneField = document.querySelector('input[type="tel"], #phone, input[name="phone"]');
            if (phoneField && phoneField.value) {
                data += '&billing_phone=' + encodeURIComponent(phoneField.value);
            }

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

                        self.showCheckoutError('Invalid server response.');

                        self.reset();

                        return;
                    }
                }

                console.log('[Bytenft] parsed response', response);

                const success =
                    response?.result === 'success' ||
                    response?.success === true;

                const redirect =
                    response.redirect ||
                    response.data?.redirect ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your order could not be placed. Please try again.';

                self.state.orderId =
                    response.order_id ||
                    response.data?.order_id ||
                    null;

                // =====================================================
                // FAILURE
                // =====================================================
                if (!success) {

                    console.log('[Bytenft] showing failed message:', errorMessage);

                    setTimeout(function () {

                        self.showCheckoutError(errorMessage);

                        // force scroll after Woo rerender
                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 300);
                        }

                    }, 50);

                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // SUCCESS — the voucher email is on its way
                // =====================================================
                const orderReceived = response.data?.order_received;

                if (orderReceived) {

                    self.showOrderReceived(orderReceived);

                    self.reset();

                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    window.location.href = redirect;

                    self.reset(true);

                    return;
                }

                self.showCheckoutError('Your order could not be placed. Please try again.');

                self.reset();

            } catch (e) {

                console.log('[Bytenft] handleResponse exception', e);

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * ORDER RECEIVED
         * ========================================================= */

        showOrderReceived: function (details) {

            const text = function (value) {
                return document.createTextNode(value);
            };

            const siteName = details.site_name || window.location.hostname;

            const $summary = $('<div>', { 'class': 'bytenft-order-received__summary' });

            const row = function (label, value, modifier) {
                return $('<div>', {
                    'class': 'bytenft-order-received__row' + (modifier ? ' ' + modifier : '')
                }).append(
                    $('<span>').text(label),
                    $('<span>').text(value)
                );
            };

            // Customer-supplied values are only ever inserted as text.
            $summary.append(row('Order', '#' + (details.order_number || '')));

            // The reference the voucher email prints under the amount. Skipped
            // when it is just the order number again, which is the row above.
            if (details.reference && String(details.reference) !== String(details.order_number || '')) {
                $summary.append(row('Voucher reference', details.reference));
            }

            (details.items || []).forEach(function (item) {
                $summary.append(row(item.label, item.value));
            });

            $summary.append(
                row('Amount due', details.amount_due || '', 'bytenft-order-received__row--total')
            );

            const $panel = $('<div>', {
                'class': 'bytenft-order-received',
                role: 'status',
                tabindex: '-1'
            }).append(

                $('<div>', {
                    'class': 'bytenft-order-received__icon',
                    'aria-hidden': 'true'
                }).html(CHECK_ICON),

                $('<h2>', { 'class': 'bytenft-order-received__title' })
                    .text('Order received'),

                // What the voucher service said, shown as it came back.
                details.message
                    ? $('<p>', { 'class': 'bytenft-order-received__status' }).text(details.message)
                    : '',

                $('<p>', { 'class': 'bytenft-order-received__lead' }).text(
                    'As part of our secure checkout process, next you’ll receive your digital redemption voucher along with an NFT confirmation. This voucher can be used to redeem your order. You’ll get an email shortly, on your own time, to purchase the voucher and complete this transaction.'
                ),

                $summary,

                $('<div>', { 'class': 'bytenft-order-received__note' }).append(
                    $('<div>', {
                        'class': 'bytenft-order-received__note-icon',
                        'aria-hidden': 'true'
                    }).html(SHIELD_ICON),
                    $('<div>').append(
                        $('<p>', { 'class': 'bytenft-order-received__note-title' })
                            .text('Independent voucher & payment partner'),
                        $('<p>').append(
                            text('Your voucher and payment are completed through a separate, independent partner. That partner is never hosted on, embedded in, or otherwise associated with '),
                            $('<span>').text(siteName),
                            text('.')
                        )
                    )
                ),

                $('<p>', { 'class': 'bytenft-order-received__foot' }).append(
                    text('No further action is needed here — check the inbox for '),
                    $('<strong>').text(details.email || ''),
                    text(' whenever you’re ready.')
                )
            );

            // Replace the checkout with the panel. Hide rather than remove so
            // the block checkout's React tree stays intact.
            const $blockCheckout = $('.wp-block-woocommerce-checkout').first();

            const $target = $blockCheckout.length
                ? $blockCheckout
                : $('form.checkout').first();

            this.clearCheckoutErrors();

            $('.bytenft-order-received').remove();

            $('.woocommerce-form-coupon-toggle, .woocommerce-form-login-toggle, form.checkout_coupon, form.woocommerce-form-login').hide();

            if ($target.length) {
                $panel.insertBefore($target);
                $target.hide();
            } else {
                $('body').prepend($panel);
            }

            $('html, body').animate({
                scrollTop: Math.max($panel.offset().top - 80, 0)
            }, 300);

            $panel[0].focus({ preventScroll: true });
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
