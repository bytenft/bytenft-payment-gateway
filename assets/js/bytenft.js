(function ($, window, document) {
    'use strict';

    if (window.BytenftCheckoutInitialized) {
        return;
    }

    window.BytenftCheckoutInitialized = true;

    const ENVELOPE_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="3" y="5" width="18" height="14" rx="2"></rect>' +
        '<path d="M3 7l9 6 9-6"></path>' +
        '</svg>';

    const SPINNER_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
        '<path d="M12 3a9 9 0 1 1-9 9"></path>' +
        '</svg>';

    const CHECK_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M5 12.5l4.5 4.5L19 7.5"></path>' +
        '</svg>';

    const BytenftCheckout = {

        PAYMENT_METHOD: bytenft_params.payment_method,

        // How often, and for how long, the "check your email" screen asks
        // whether the customer has paid from the emailed link.
        STATUS_POLL_INTERVAL: 5000,
        STATUS_POLL_TIMEOUT: 30 * 60 * 1000,

        state: {
            submitting: false,
            statusInterval: null,
            statusRequest: null,
            redirecting: false,
            paymentWindow: null,
            panel: null,
            panelDetails: null,
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

                        self.showCheckoutError(
                            'Invalid server response.'
                        );

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
                // ✅ SUCCESS — payment link was emailed to the customer
                // =====================================================
                const paymentEmail = response.data?.payment_email;

                if (paymentEmail) {

                    self.showPaymentEmailSent(paymentEmail);

                    self.watchPaymentStatus();

                    self.reset();

                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    window.location.href = redirect;

                    self.reset(true);
                    return;
                }

                self.showCheckoutError('Missing redirect URL.');

                self.reset();

            } catch (e) {

                console.log('[Bytenft] handleResponse exception', e);

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * PAYMENT LINK EMAILED
         * ========================================================= */

        showPaymentEmailSent: function (details) {

            const self = this;

            const text = function (value) {
                return document.createTextNode(value);
            };

            // Only ever link to an http(s) URL.
            const paymentLink = /^https?:\/\//i.test(details.payment_link || '')
                ? details.payment_link
                : '';

            // Customer-supplied values are only ever inserted as text.
            const strong = function (value) {
                return $('<strong>').text(value || '');
            };

            const $panel = $('<div>', {
                'class': 'bytenft-email-sent',
                role: 'status',
                tabindex: '-1',
                'data-state': 'waiting'
            }).append(
                $('<div>', {
                    'class': 'bytenft-email-sent__icon',
                    'aria-hidden': 'true'
                }).html(ENVELOPE_ICON),

                $('<h2>', { 'class': 'bytenft-email-sent__title' })
                    .text('Check your email to continue'),

                $('<p>', { 'class': 'bytenft-email-sent__lead' }).append(
                    text('We’ve sent a secure payment link to '),
                    strong(details.email),
                    text(' for order '),
                    strong('#' + details.order_number),
                    text('. Open it and follow the link to pay '),
                    strong(details.amount),
                    text(' and confirm your order.')
                ),

                $('<div>', { 'class': 'bytenft-email-sent__note' }).append(
                    $('<p>', { 'class': 'bytenft-email-sent__note-title' })
                        .text('Who takes the payment'),
                    $('<p>').text(
                        'The link opens a secure payment page hosted by ByteNFT, so your card details are never handled by this website.'
                    )
                ),

                $('<p>', { 'class': 'bytenft-email-sent__small' }).text(
                    'Nothing has been charged yet, and this page updates once your payment is confirmed.'
                ),

                $('<p>', { 'class': 'bytenft-email-sent__small' }).text(
                    paymentLink
                        ? 'Can’t find the email? Check your spam folder, or click below to open your payment link.'
                        : 'Didn’t get the email? Check your spam folder.'
                ),

                $('<div>', { 'class': 'bytenft-email-sent__actions' }).append(
                    paymentLink
                        ? $('<a>', {
                            'class': 'button bytenft-email-sent__pay',
                            href: paymentLink,
                            target: '_blank',
                            rel: 'noopener noreferrer'
                        }).text('Open payment page').on('click', function (e) {
                            // Keep a handle on the tab so it can be closed once paid;
                            // if the browser blocks it, the plain link opens instead.
                            if (self.openPaymentTab(paymentLink)) {
                                e.preventDefault();
                            }
                        })
                        : null,

                    $('<a>', {
                        'class': 'button bytenft-email-sent__back',
                        href: details.shop_url || '/'
                    }).text('Back to the shop')
                )
            );

            this.state.panel = $panel;
            this.state.panelDetails = details;

            // Replace the checkout with the panel. Hide rather than remove so
            // the block checkout's React tree stays intact.
            const $blockCheckout = $('.wp-block-woocommerce-checkout').first();

            const $target = $blockCheckout.length
                ? $blockCheckout
                : $('form.checkout').first();

            this.clearCheckoutErrors();

            $('.bytenft-email-sent').remove();

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

        /**
         * Open the payment link in a new tab this page keeps a reference to.
         * Returns false when the browser blocks the tab.
         */
        openPaymentTab: function (url) {

            const current = this.state.paymentWindow;

            if (current && !current.closed) {
                current.focus();
                return true;
            }

            const tab = window.open('', '_blank');

            if (!tab) {
                return false;
            }

            const safeUrl = String(url)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            try {

                // Redirect from a no-referrer page so the payment page never
                // receives the checkout URL as Referer.
                tab.document.open();
                tab.document.write(
                    '<!DOCTYPE html><html><head><title>Secure Payment</title>' +
                    '<meta name="referrer" content="no-referrer">' +
                    '<meta http-equiv="refresh" content="0;url=' + safeUrl + '">' +
                    '</head><body style="margin:0;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;">' +
                    '<p>Connecting to secure payment...</p></body></html>'
                );
                tab.document.close();

            } catch (err) {

                tab.location.href = url;
            }

            this.state.paymentWindow = tab;

            return true;
        },

        /**
         * Move the "check your email" panel on to "confirming" or "confirmed".
         */
        setPanelState: function (panelState) {

            const $panel = this.state.panel;

            if (!$panel || $panel.attr('data-state') === panelState) {
                return;
            }

            // Never step back from confirmed to confirming.
            if ($panel.attr('data-state') === 'confirmed') {
                return;
            }

            const orderNumber = $('<strong>').text('#' + (this.state.panelDetails?.order_number || ''));

            const views = {
                confirming: {
                    icon: SPINNER_ICON,
                    iconClass: 'bytenft-email-sent__icon--busy',
                    title: 'Confirming your payment',
                    lead: [
                        document.createTextNode('Your payment for order '),
                        orderNumber,
                        document.createTextNode(' is being processed. This can take a minute — keep this page open and you’ll be taken to your order confirmation automatically.')
                    ]
                },
                confirmed: {
                    icon: CHECK_ICON,
                    iconClass: 'bytenft-email-sent__icon--done',
                    title: 'Payment confirmed',
                    lead: [
                        document.createTextNode('Payment for order '),
                        orderNumber,
                        document.createTextNode(' is confirmed. Taking you to your order confirmation…')
                    ]
                }
            };

            const view = views[panelState];

            if (!view) {
                return;
            }

            $panel.attr('data-state', panelState);

            $panel.find('.bytenft-email-sent__icon')
                .removeClass('bytenft-email-sent__icon--busy bytenft-email-sent__icon--done')
                .addClass(view.iconClass)
                .html(view.icon);

            $panel.find('.bytenft-email-sent__title').text(view.title);

            $panel.find('.bytenft-email-sent__lead').empty().append(view.lead);

            // Email instructions and the pay/shop buttons no longer apply.
            $panel.find('.bytenft-email-sent__note, .bytenft-email-sent__small, .bytenft-email-sent__actions').hide();
        },

        closePaymentTab: function () {

            const tab = this.state.paymentWindow;

            this.state.paymentWindow = null;

            if (tab && !tab.closed) {
                try {
                    tab.close();
                } catch (err) {}
            }
        },

        watchPaymentStatus: function () {

            const self = this;

            const startedAt = Date.now();

            clearInterval(self.state.statusInterval);

            if (!self.state.orderId) {
                return;
            }

            const check = function () {

                if (Date.now() - startedAt > self.STATUS_POLL_TIMEOUT) {
                    clearInterval(self.state.statusInterval);
                    return;
                }

                self.checkPaymentStatus();
            };

            self.state.statusInterval = setInterval(check, self.STATUS_POLL_INTERVAL);

            // Check straight away when the customer returns from the payment tab.
            $(document)
                .off('visibilitychange.bytenft')
                .on('visibilitychange.bytenft', function () {
                    if (document.visibilityState === 'visible') {
                        check();
                    }
                });
        },

        checkPaymentStatus: function () {

            const self = this;

            if (self.state.statusRequest || self.state.redirecting) {
                return;
            }

            const request = $.post(
                bytenft_params.ajax_url,
                {
                    action: 'bytenft_check_payment_status',
                    order_id: self.state.orderId,
                    security: bytenft_params.bytenft_nonce
                },
                function (response) {

                    console.log('[Bytenft] payment status response', response);

                    const data = response?.data || {};

                    // Only a confirmed payment moves the customer on; they
                    // may not have opened the email yet.
                    if (data.status === 'success' && data.redirect_url) {

                        self.state.redirecting = true;

                        clearInterval(self.state.statusInterval);

                        self.setPanelState('confirmed');

                        // The hosted page never returns the customer, so
                        // finish here: close its tab, show the thank-you page.
                        self.closePaymentTab();

                        window.location.replace(data.redirect_url);

                        return;
                    }

                    // The provider is processing the customer's payment.
                    if (data.payment_status === 'processing') {
                        self.setPanelState('confirming');
                    }
                },
                'json'
            );

            self.state.statusRequest = request;

            request.always(function () {
                self.state.statusRequest = null;
            });
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
