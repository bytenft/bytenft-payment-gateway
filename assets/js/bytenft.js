(function ($, window, document) {
    'use strict';

    if (window.BytenftCheckoutInitialized) {
        return;
    }

    window.BytenftCheckoutInitialized = true;

    const BytenftCheckout = {

        /* =========================================================
         * CONFIG
         * ========================================================= */

        PAYMENT_METHOD: bytenft_params.payment_method,

        /* =========================================================
         * BANK-GRADE STATE MACHINE
         * ========================================================= */

        state: {
            status: 'idle', // idle | validating | popup | processing | done
            submitting: false,
            popup: null,
            popupInterval: null,
            orderId: null,
            button: null,
            buttonText: '',
            requestInFlightClassic: false,
            requestInFlightBlock: false,
            responseHandled: false,
            finalSuccess: false,
            buttonObserver: null,
            blockEventsBound: false,
            popupStarted: null,
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {
            const self = this;

            this.bindClassicCheckout();
            this.bindBlockCheckout();
            this.bindInputSanitization();

            // Re-bind events if layout structures refresh via multi-step AJAX changes
            $(document.body).on('updated_checkout updated_shipping_method fragments_refreshed fragments_loaded', function () {
                self.bindClassicCheckout();
            });

            console.log('[Bytenft] bank-grade initialized');
        },

        setStatus: function (status) {
            this.state.status = status;
        },

        canProceed: function (type) {
            if (type === 'Classic') {
                return !this.state.requestInFlightClassic;
            }
            if (type === 'Block') {
                return !this.state.requestInFlightBlock;
            }
            return true;
        },

        releaseLock: function (type) {
            if (!type) {
                this.state.requestInFlightClassic = false;
                this.state.requestInFlightBlock = false;
                return;
            }

            if (type === 'Classic') {
                this.state.requestInFlightClassic = false;
            }

            if (type === 'Block') {
                this.state.requestInFlightBlock = false;
            }
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {
            const self = this;

            $('form.checkout, form#wcf-embed-checkout-form, form.wcf-embed-checkout-form-steps')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on('checkout_place_order_' + self.PAYMENT_METHOD, function () {

                    const $form = $(this);

                    if (!self.canProceed('Classic')) return false;
                    if (self.state.requestInFlightClassic) return false;

                    self.state.requestInFlightClassic = true;

                    self.setStatus('validating');
                    self.clearCheckoutErrors();

                    const phone = self.getPhoneNumber($form);
                    if (phone && /[^0-9]/.test(phone)) {
                        self.showCheckoutError('Please enter a valid phone number (numeric values only).');
                        self.releaseLock('Classic');
                        self.setStatus('idle');
                        return false;
                    }

                    self.setStatus('popup');

                   const popup = self.openPopupImmediately();

                    if (!popup) {
                        self.releaseLock('Classic');
                        self.setStatus('idle');
                        return false;
                    }

                    // 3. NOW move to processing
                    self.setStatus('processing');

                    // 4. Start AJAX AFTER popup exists
                    self.handleClassicCheckout($form);
                    return false;
                });
        },

        buildCheckoutPayload: function () {

            const $form = $(
                'form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form, .wcf-embed-checkout-form-steps, #order_review'
            ).first();

            // Start with all normal form fields (Classic/FunnelKit)
            const data = new URLSearchParams($form.serialize());

            /**
             * Get a field value from:
             * 1. name attribute (Classic/FunnelKit)
             * 2. id attribute (Checkout Blocks)
             */
            const getFieldValue = function (name, id) {

                // Try by name first
                let field = document.querySelector(`[name="${name}"]`);

                if (field && field.value !== '') {
                    return field.value;
                }

                // Fallback for WooCommerce Blocks
                if (id) {
                    field = document.getElementById(id);

                    if (field && field.value !== '') {
                        return field.value;
                    }
                }

                return null;
            };

            /**
             * WooCommerce Blocks fields.
             * These usually don't have a name="" attribute.
             */
            const blockFields = {
                billing_country: 'billing-country',
                billing_state: 'billing-state',
                billing_first_name: 'billing-first_name',
                billing_last_name: 'billing-last_name',
                billing_company: 'billing-company',
                billing_address_1: 'billing-address_1',
                billing_address_2: 'billing-address_2',
                billing_city: 'billing-city',
                billing_postcode: 'billing-postcode',
                billing_phone: 'billing-phone',
                billing_email: 'billing-email',

                shipping_country: 'shipping-country',
                shipping_state: 'shipping-state',
                shipping_first_name: 'shipping-first_name',
                shipping_last_name: 'shipping-last_name',
                shipping_company: 'shipping-company',
                shipping_address_1: 'shipping-address_1',
                shipping_address_2: 'shipping-address_2',
                shipping_city: 'shipping-city',
                shipping_postcode: 'shipping-postcode',
                shipping_phone: 'shipping-phone'
            };

            // Add missing fields only
            Object.entries(blockFields).forEach(([name, id]) => {

                if (data.has(name)) {
                    return;
                }

                const value = getFieldValue(name, id);

                if (value !== null) {
                    data.set(name, value);
                }
            });

            /**
             * Shipping flag
             */
            const shipToDifferent =
                $('#ship-to-different-address-checkbox, input[name="ship_to_different_address"]').is(':checked')
                    ? '1'
                    : '0';

            data.set('ship_to_different_address', shipToDifferent);

           if (shipToDifferent === '0') {
                [
                    'first_name',
                    'last_name',
                    'company',
                    'address_1',
                    'address_2',
                    'city',
                    'state',
                    'postcode',
                    'country',
                    'phone'
                ].forEach(function(field){

                    const shippingKey = 'shipping_' + field;
                    const billingKey = 'billing_' + field;

                    if (!data.get(billingKey) && data.get(shippingKey)) {
                        data.set(billingKey, data.get(shippingKey));
                    }

                });

                data.set('wfacp_billing_same_as_shipping', '1');
            }

            /**
             * Backward compatibility
             */
            if (data.has('billing_country')) {
                data.set('country_code', data.get('billing_country'));
            }

            return data.toString();
        },

        handleClassicCheckout: function ($form) {
            const self = this;

            self.state.button = $('body').find('button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn').first();
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            // Form payload compilation handles scattered form fields elegantly
            const dataPayload = self.buildCheckoutPayload();

            $.ajax({
                type: 'POST',
                url: wc_checkout_params.checkout_url,
                data: dataPayload,
                dataType: 'json',
                success: function (response) {
                    self.state.requestInFlightClassic = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {
                    self.state.requestInFlightClassic = false;
                    console.log('[Bytenft] checkout network error:', xhr.responseText);
                    self.failSafe('There was an error processing your order.');
                }
            });
        },

        /* =========================================================
         * BLOCK CHECKOUT
         * ========================================================= */

        bindBlockCheckout: function () {

            if (this.state.blockEventsBound) {
                return;
            }

            this.state.blockEventsBound = true;
            
            const self = this;

            // UX: provide feedback if button is disabled due to background Store API sync
            const observeButton = function() {
                const btn = document.querySelector('.wc-block-components-checkout-place-order-button');
                if (!btn) {
                    setTimeout(observeButton, 500);
                    return;
                }
                
                let feedbackNote = document.getElementById('bytenft-sync-note');
                if (!feedbackNote) {
                    feedbackNote = document.createElement('div');
                    feedbackNote.id = 'bytenft-sync-note';
                    feedbackNote.style.color = '#777';
                    feedbackNote.style.fontSize = '13px';
                    feedbackNote.style.marginTop = '8px';
                    feedbackNote.style.display = 'none';
                    feedbackNote.innerText = 'Verifying payment option…';
                    btn.parentNode.insertBefore(feedbackNote, btn.nextSibling);
                }

                // Remove previous observer
                if (self.state.buttonObserver) {
                    self.state.buttonObserver.disconnect();
                    self.state.buttonObserver = null;
                }

                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'disabled') {
                            const $form = $('form.wc-block-checkout__form');
                            const selected = $form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
                            if (btn.hasAttribute('disabled') && selected === self.PAYMENT_METHOD && !self.state.requestInFlightBlock) {
                                feedbackNote.style.display = 'block';
                            } else {
                                feedbackNote.style.display = 'none';
                            }
                        }
                    });
                });
                observer.observe(btn, {
                    attributes: true
                });

                self.state.buttonObserver = observer;
            };
            observeButton();

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.wc-block-components-checkout-place-order-button');
                if (!btn) return;

                const $form = $('form.wc-block-checkout__form');
                if (!$form.length) return;

                const selected = $form.find(
                    'input[name="radio-control-wc-payment-method-options"]:checked'
                ).val();

                if (selected !== self.PAYMENT_METHOD) return;

                e.preventDefault();
                e.stopImmediatePropagation();

                if (!self.canProceed('Block')) return;
                if (self.state.requestInFlightBlock) return;

                self.state.requestInFlightBlock = true;

                self.setStatus('validating');
                self.clearCheckoutErrors();

                const phone = self.getPhoneNumber($form);
                if (phone && /[^0-9]/.test(phone)) {
                    self.showCheckoutError('Please enter a valid phone number (numeric values only).');
                    self.releaseLock('Block');
                    self.setStatus('idle');
                    return;
                }

                self.setStatus('popup'); 
                
                const popup = self.openPopupImmediately();

                if (!popup) {
                    self.releaseLock('Block');
                    self.setStatus('idle');
                    return;
                }

                self.setStatus('processing');
                self.handleBlockCheckout($form);
            }, true);

            // Prevent native block context from bypassing validation filters
            document.addEventListener('submit', function (e) {
                const form = e.target;
                if (!form.classList.contains('wc-block-checkout__form')) return;

                const selected = form.querySelector(
                    'input[name="radio-control-wc-payment-method-options"]:checked'
                )?.value;

                if (selected !== self.PAYMENT_METHOD) return;

                e.preventDefault();
                e.stopImmediatePropagation();
            }, true);
        },

        handleBlockCheckout: function ($form) {
            const self = this;

            self.state.button = $('.wc-block-components-checkout-place-order-button');
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            let data = self.buildCheckoutPayload();
            data += '&action=bytenft_block_gateway_process';
            data += '&nonce=' + encodeURIComponent(bytenft_params.bytenft_nonce);

            $.ajax({
                type: 'POST',
                url: bytenft_params.ajax_url,
                data: data,
                success: function (response) {
                    self.state.requestInFlightBlock = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {

                    self.state.requestInFlightBlock = false;

                    console.log('[Bytenft] block checkout error:', xhr.responseText);

                    let message = 'There was an error processing your order.';

                    try {

                        let response = JSON.parse(xhr.responseText);

                        message =
                            response?.message ||
                            response?.data?.message ||
                            response?.data?.error ||
                            message;


                        // WooCommerce checkout error format
                        if (
                            response?.payment_result?.payment_details &&
                            Array.isArray(response.payment_result.payment_details)
                        ) {

                            const errorItem = response.payment_result.payment_details.find(
                                item => item.key === 'message'
                            );

                            if (errorItem?.value) {
                                message = errorItem.value;
                            }
                        }


                        // WooCommerce blocks API error format
                        if (
                            response?.data?.payment_result?.payment_details &&
                            Array.isArray(response.data.payment_result.payment_details)
                        ) {

                            const errorItem = response.data.payment_result.payment_details.find(
                                item => item.key === 'message'
                            );

                            if (errorItem?.value) {
                                message = errorItem.value;
                            }
                        }


                    } catch (e) {

                        console.log('[Bytenft] Unable to parse error response');

                    }


                    self.failSafe(message);
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

        handleResponse: function (response) {
            const self = this;

            if (self.state.responseHandled) return;

            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }

                console.group('[Bytenft] API Response');
                console.log('Response:', response);
                console.groupEnd();

                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect = response?.redirect || response?.data?.redirect;
                const orderId = response?.order_id || response?.data?.order_id;

                self.state.orderId = orderId;

                if (!success) {
                    // FIXED: Keep the raw HTML string format from WooCommerce intact!
                    let errorMessage =
                        response?.messages ||
                        response?.message ||
                        response?.data?.message ||
                        'Payment failed. Please try again.';

                    self.cleanupPopup();          // Close loading popup
                    self.showCheckoutError(errorMessage);
                    self.reset();

                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {
                    self.state.responseHandled = true;

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
                        self.finish();
                    }
                    return;
                }

                self.failSafe('Missing redirect URL.');

            } catch (e) {
                console.log('[Bytenft] response processing exception', e);
                self.failSafe('Unexpected checkout error.');
            }
        },

        /* =========================================================
         * FAIL SAFE & STATE TERMINATION
         * ========================================================= */

        failSafe: function (message) {
            this.releaseLock();
            this.cleanupPopup();
            this.showCheckoutError(message);
            this.refreshCheckout();
            this.finish();
        },

        finish: function () {

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            this.setStatus('done');
            this.reset();

            this.state.responseHandled = false;
            this.state.requestInFlightClassic = false;
            this.state.requestInFlightBlock = false;
            this.state.finalSuccess = false;

            setTimeout(() => {
                this.setStatus('idle');
            }, 500);
        },

        /* =========================================================
         * POPUP HANDLERS
         * ========================================================= */

        openPopupImmediately: function () {

            if (
                this.state.popup &&
                !this.state.popup.closed
            ) {
                return this.state.popup;
            }

            this.state.popup = window.open(
                '',
                '_blank',
                'width=700,height=700'
            );

            if (!this.state.popup) {
                alert('Popup blocked. Please allow popups for your payment.');
                return null;
            }

            const logoUrl = bytenft_params.bytenft_loader
                ? encodeURI(bytenft_params.bytenft_loader)
                : '';

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
                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />` : ''}
                        <h3>Connecting to secure payment...</h3>
                        <p>Please do not close this window.</p>
                    </div>
                </body>
                </html>
            `);

            this.state.popup.document.close();

            return this.state.popup;
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
                var logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
                popup.document.open();
                popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Payment</title>
                    <meta name="referrer" content="no-referrer">
                    <meta http-equiv="refresh" content="0;url=` + url + `">
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

            if (this.state.popup && !this.state.popup.closed) {
                try { this.state.popup.close(); } catch (e) {}
            }
            this.state.popup = null;
        },

        trackPopupClose: function () {

            const self = this;

            self.state.popupStarted = Date.now();

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

                // Payment already completed
                if (self.state.finalSuccess) {
                    clearInterval(self.state.popupInterval);
                    self.state.popupInterval = null;
                    return;
                }

                // Popup still open
                if (self.state.popup && !self.state.popup.closed) {

                    // Optional: payment expired after 30 minutes
                    if (Date.now() - self.state.popupStarted > 30 * 60 * 1000) {
                        console.log('[Bytenft] Payment timeout');
                    }

                    return;
                }

                // Popup closed
                clearInterval(self.state.popupInterval);
                self.state.popupInterval = null;

                console.log('[Bytenft] Popup closed → checking payment');

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

                        if (success) {

                            self.state.finalSuccess = true;

                            self.cleanupPopup();

                            window.location.replace(
                                response?.data?.redirect ||
                                response?.redirect
                            );

                            return;
                        }

                        // Payment cancelled/failed
                        self.cleanupPopup();

                        if (response?.message) {
                            self.showCheckoutError(response.message);
                        }

                        self.refreshCheckout();

                        // IMPORTANT
                        self.finish();

                    },
                    'json'
                );

            }, 1000);
        },

        /* =========================================================
         * DATA FILTERS & VALIDATIONS
         * ========================================================= */

        validateAll: function ($form) {
            const email = this.getBillingEmail($form);
            if (!email) return 'Please enter your email address.';
            if (!this.isValidEmail(email)) return 'Please enter a valid email address.';

            const phone = this.getPhoneNumber($form);
            if (phone && !this.isValidPhoneNumber(phone)) return 'Please enter a valid phone number.';

            const poBox = this.validatePOBox($form);
            if (poBox) return poBox;

            const country = $('select[name="billing_country"]').val();
            const postcode = ($('input[name="billing_postcode"]').val() || '').trim();

            if (country === 'US') {
                if (!/^\d{5}(-\d{4})?$/.test(postcode)) {
                    return 'Please enter a valid US ZIP code.';
                }
            }

            return null;
        },

        getBillingEmail: function ($form) {
            let email = $form.find('input[name="billing_email"], #billing_email, input[type="email"]').val();
            if (!email) {
                email = $('body').find('input[name="billing_email"], #billing_email, input[type="email"], #email').first().val();
            }
            return (email || '').trim();
        },

        isValidEmail: function (email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },

        getPhoneNumber: function ($form) {
            let phone = $form.find('input[name="billing_phone"], #billing_phone, input[type="tel"]').val();
            if (!phone) {
                phone = $('body').find('input[name="billing_phone"], #billing_phone, input[type="tel"]').first().val();
            }
            return (phone || '').trim();
        },


        validateRequiredFields: function ($form) {

    let firstInvalid = null;
    const isShippingActive = this.getShippingState($form);

    const messages = {
        billing_first_name: 'Please enter a valid first name.',
        billing_last_name: 'Please enter a valid last name.',
        billing_address_1: 'Please enter a valid street address.',
        billing_city: 'Please enter a valid town / city.',
        billing_state: 'Please select a state.',
        billing_postcode: 'Please enter a valid postcode / ZIP.',
        billing_email: 'Please enter a valid email address.',

        shipping_first_name: 'Please enter a valid first name.',
        shipping_last_name: 'Please enter a valid last name.',
        shipping_address_1: 'Please enter a valid street address.',
        shipping_city: 'Please enter a valid town / city.',
        shipping_state: 'Please select a state.',
        shipping_postcode: 'Please enter a valid postcode / ZIP.'
    };

    const $fields = $('form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form')
        .find('[required], .validate-required input, .validate-required select, .validate-required textarea');

    $fields.each(function () {

        const $field = $(this);
        const name = $field.attr('name') || '';

        if ($field.attr('type') === 'hidden') return;

        // Phone is optional
        if (name === 'billing_phone') return;

        if (name.indexOf('shipping_') === 0 && !isShippingActive) return;

        const value = ($field.val() || '').trim();

        const $wrapper = $field.closest('.form-row, .wc-block-components-text-input, .form-row-first, .form-row-last');

        $wrapper.find('.bytenft-field-error').remove();

        if (!value) {

            $wrapper.addClass('woocommerce-invalid woocommerce-invalid-required-field');

            $field.css({
                borderColor: '#d63638',
                boxShadow: '0 0 0 1px #d63638'
            });

            $field.after(
                '<div class="bytenft-field-error" style="color:#d63638;font-size:13px;margin-top:10px;">' +
                (messages[name] || 'This field is required.') +
                '</div>'
            );

            if (!firstInvalid) {
                firstInvalid = $field;
            }

        } else {

            $wrapper.removeClass('woocommerce-invalid woocommerce-invalid-required-field');

            $field.css({
                borderColor: '',
                boxShadow: ''
            });

            $wrapper.find('.bytenft-field-error').remove();
        }

    });

    if (firstInvalid) {
        setTimeout(function () {
            firstInvalid.focus();
        }, 100);

        return {
            message: 'Please correct the highlighted fields.'
        };
    }

    return null;
},

        getShippingState: function ($form) {
            const $root = $('body');
            const $shipToDifferentCheckbox = $root.find('#ship-to-different-address-checkbox, input[name="ship_to_different_address"]');
            
            if ($shipToDifferentCheckbox.length) {
                if ($shipToDifferentCheckbox.is(':checkbox')) {
                    return $shipToDifferentCheckbox.is(':checked');
                }
                const val = $shipToDifferentCheckbox.val();
                return (val === '1' || val === 'yes' || val === 'true');
            }

            const $shippingWrapper = $root.find('.shipping_address, .wcf-shipping-address-fade');
            if ($shippingWrapper.length) {
                return $shippingWrapper.is(':visible') || $shippingWrapper.css('display') === 'block';
            }

            return false;
        },

        validatePOBox: function ($form) {
            const isShippingActive = this.getShippingState($form);
            const $root = $('body');
            
            const billing1 = $root.find('[name="billing_address_1"]').val();
            const billing2 = $root.find('[name="billing_address_2"]').val();
            
            if (this.containsPOBox(billing1) || this.containsPOBox(billing2)) {
                return 'PO Box addresses are not allowed for Billing.';
            }

            if (isShippingActive) {
                const shipping1 = $root.find('[name="shipping_address_1"]').val();
                const shipping2 = $root.find('[name="shipping_address_2"]').val();
                
                if (this.containsPOBox(shipping1) || this.containsPOBox(shipping2)) {
                    return 'PO Box addresses are not allowed for Shipping.';
                }
            }
            return null;
        },

        containsPOBox: function (value) {
            if (!value) return false;
            const cleaned = value.toLowerCase().replace(/[^a-z0-9]/g, '');
            return (cleaned.includes('pobox') || cleaned.includes('postofficebox'));
        },

        /* =========================================================
         * RESET & INTERFACE MANAGERS
         * ========================================================= */

        reset: function (keepDisabled = false) {

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            this.state.submitting = false;
            this.state.status = 'idle';

            this.state.popup = null;
            this.state.orderId = null;
            this.state.button = null;
            this.state.responseHandled = false;
            this.state.requestInFlightClassic = false;
            this.state.requestInFlightBlock = false;
            this.state.finalSuccess = false;
            this.state.popupStarted = null;

            const $form = $('form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form, .wcf-embed-checkout-form-steps');
            if ($form.length) {
                $form.removeClass('processing');
                if (typeof $form.unblock === 'function') {
                    $form.unblock();
                }
            }

            const $button = $('.wc-block-components-checkout-place-order-button, button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn');

            if (!$button.length) return;

            if (keepDisabled) {
                $button.prop('disabled', false)   // IMPORTANT FIX
                    .removeClass('loading')
                    .text(this.state.buttonText || 'Place order');
                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(this.state.buttonText || 'Place order');
        },

        showCheckoutError: function (message, fields = []) {
            // 1. Cleanly clear out any old error instances to avoid duplicates
            $('.bytenft-error-wrap, #bytenft-checkout-errors').remove();

            let finalMessage = message;

            // Prevent appending field names if the main message already contains them
            if (fields.length) {
                const lowerMessage = String(finalMessage).toLowerCase();
                
                const filteredFields = fields.filter(field => {
                    const cleanField = field.replace(/^(billing_|shipping_)/, '').replace(/_/g, ' ').toLowerCase();
                    return !lowerMessage.includes(cleanField);
                });

                if (filteredFields.length) {
                    finalMessage += '<br>' + filteredFields.join(', ');
                }
            }

            /**
             * Modern WooCommerce Blocks Checkout Handler
             */
            const blockCheckoutWrapper = document.querySelector('.wp-block-woocommerce-checkout');

            if (blockCheckoutWrapper) {
                let container = document.getElementById('bytenft-checkout-errors');

                // Create container outside the React tree if it doesn't exist
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'bytenft-checkout-errors';
                    container.className = 'wc-block-components-notices';
                    
                    // FIX: Safely insert BEFORE the entire block checkout tree, NOT inside it.
                    blockCheckoutWrapper.parentNode.insertBefore(container, blockCheckoutWrapper);
                }

                // Parse incoming string while preserving HTML
                if (typeof finalMessage === 'string') {

                    const decoder = document.createElement('div');
                    decoder.innerHTML = finalMessage;

                    // 1. WooCommerce Blocks notice already exists
                    const blockNotice = decoder.querySelector('.wc-block-components-notice-banner__content');

                    if (blockNotice) {

                        finalMessage = blockNotice.innerHTML.trim();

                    }
                    // 2. WooCommerce <ul class="woocommerce-error">
                    else if (decoder.querySelector('ul.woocommerce-error')) {

                        const listItems = decoder.querySelectorAll('ul.woocommerce-error li');

                        finalMessage = Array.from(listItems)
                            .map(li => `<div style="margin:0 0 8px 0;">${li.innerHTML}</div>`)
                            .join('');

                    }
                    // 3. Any generic <ul>
                    else if (decoder.querySelector('ul')) {

                        const listItems = decoder.querySelectorAll('ul li');

                        finalMessage = Array.from(listItems)
                            .map(li => `<div style="margin:0 0 8px 0;">${li.innerHTML}</div>`)
                            .join('');

                    }
                    // 4. Preserve existing HTML
                    else {

                        finalMessage = decoder.innerHTML;

                    }
                }

                container.innerHTML = `
                    <div class="wc-block-components-notice-banner is-error" role="alert">
                        <svg class="wc-block-components-notice-banner__icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
                            <path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>
                        </svg>
                        <div class="wc-block-components-notice-banner__content" style="display: block;">
                            ${finalMessage}
                        </div>
                    </div>
                `;

                // Smooth scroll to the newly injected safe wrapper
                setTimeout(function () {
                    const errorBox = document.getElementById('bytenft-checkout-errors');
                    if (errorBox) {
                        errorBox.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }, 100);

                return;
            }

            /**
             * Classic Checkout fallback
             */
            let classicHTML = finalMessage;
            
            if (typeof classicHTML === 'string') {

                const decoder = document.createElement('div');
                decoder.innerHTML = classicHTML;

                const blockNotice = decoder.querySelector('.wc-block-components-notice-banner__content');

                if (blockNotice) {

                    classicHTML = blockNotice.innerHTML;

                } else {

                    classicHTML = decoder.innerHTML;

                }
            }

            const html = `
                <div class="woocommerce-notices-wrapper bytenft-error-wrap">
                    <div class="woocommerce-error" role="alert">
                        ${classicHTML}
                    </div>
                </div>
            `;

            $('form.checkout').prepend(html);
            
            if ($('form.checkout').length) {
                $('html, body').animate({
                    scrollTop: ($('form.checkout').offset().top - 100)
                }, 300);
            }
        },

        clearCheckoutErrors: function () {

            $('.woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper, .woocommerce-error, .wc-block-components-notice-banner, .woocommerce-message, .woocommerce-info, .bytenft-error-wrap').remove();

            $('.bytenft-field-error').remove();

            $('.woocommerce-invalid').removeClass('woocommerce-invalid woocommerce-invalid-required-field');

            $('input, select, textarea').css({
                borderColor: '',
                boxShadow: ''
            });
        },

        refreshCheckout: function () {

            // WooCommerce Blocks Checkout
            if (document.querySelector('.wc-block-checkout')) {

                document.body.dispatchEvent(
                    new CustomEvent('wc-blocks_checkout_update_payment_methods')
                );

            } else {

                // Classic Checkout
                $(document.body).trigger('update_checkout');

            }
        },

        isValidPhoneNumber: function (p) {

            if (!p) return true;

            const cleaned = p.replace(/[\s\-().]/g, '');

            // digits only
            if (!/^\+?\d+$/.test(cleaned)) {
                return false;
            }

            const numberOnly = cleaned.replace('+','');

            // reject repeated digits
            if (/^(\d)\1+$/.test(numberOnly)) {
                return false;
            }

            // reject common test numbers
            const invalidNumbers = [
                '0000000000',
                '1111111111',
                '2222222222',
                '3333333333',
                '4444444444',
                '5555555555',
                '6666666666',
                '7777777777',
                '8888888888',
                '9999999999',
                '1234567890',
                '9876543210'
            ];

            if (invalidNumbers.includes(numberOnly)) {
                return false;
            }


            return (
                /^1?\d{10}$/.test(numberOnly) ||
                /^[1-9]\d{6,14}$/.test(numberOnly)
            );
        },

        bindInputSanitization: function () {
            const selectors = `
                #bytenft_card_holder, #billing_first_name, #billing_last_name, #billing_city,
                input[name="billing_first_name"], input[name="billing_last_name"], input[name="billing_city"],
                input[name="shipping_first_name"], input[name="shipping_last_name"], input[name="shipping_city"]
            `;

            $(document).off('input keyup blur change paste', selectors).on(
                'input keyup blur change paste',
                selectors,
                function () {
                    const clean = this.value.replace(/[^A-Za-z\s\-']/g, '');
                    if (this.value !== clean) {
                        this.value = clean;
                    }
                }
            );

            $(document).off('input', '#billing_address_1, #shipping_address_1').on(
                'input',
                '#billing_address_1, #shipping_address_1',
                function () {
                    this.value = this.value.replace(/[^A-Za-z0-9\s,.\-#]/g, '');
                }
            );
        }
    };

    $(document).ready(function () {
        BytenftCheckout.init();
    });

})(jQuery, window, document);
