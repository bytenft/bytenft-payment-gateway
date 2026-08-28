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
            popupBlocked: false,
            customerUserId: null,
            createNewCustomer: false,
            accountAction: null,
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

                    if (!self.canProceed('Classic')) {
                        return false;
                    }

                    self.state.requestInFlightClassic = true;
                    self.setStatus('validating');

                    self.clearCheckoutErrors();

                    /*
                    * =========================================================
                    * STEP 1: VALIDATE CHECKOUT FIELDS
                    * =========================================================
                    */

                    const requiredError = self.validateRequiredFields($form);

                    if (requiredError) {

                        self.releaseLock('Classic');
                        self.setStatus('idle');

                        self.showCheckoutError(requiredError.message);

                        return false;
                    }

                    const validationError = self.validateAll($form);

                    if (validationError) {

                        self.releaseLock('Classic');
                        self.setStatus('idle');

                        self.showCheckoutError(validationError);

                        return false;
                    }

                    /*
                    * =========================================================
                    * STEP 2: CHECK CUSTOMER ACCOUNT
                    * =========================================================
                    */

                    self.checkCustomerAccount()
                    .then(function (customerResult) {

                        console.log(
                            '[Bytenft] Customer account decision:',
                            customerResult
                        );

                        self.state.customerUserId =
                            customerResult.user_id || null;

                        self.state.createNewCustomer =
                            customerResult.create_new_user === true;

                        self.state.accountAction =
                            self.state.create_new_customer
                                ? 'create_new'
                                : 'use_existing';

                        /*
                        * Phone + customer validation passed.
                        * Now open payment popup.
                        */
                        self.setStatus('popup');

                        /*
                        * A blocked window is not a failure. On mobile Safari
                        * and Chrome this call is outside the original click
                        * gesture, so the window may simply not open - the
                        * payment then continues in this tab. Do not abort.
                        */
                        self.openPopupImmediately();

                        /*
                        * Start payment.
                        */
                        self.setStatus('processing');

                        self.handleClassicCheckout($form);
                    })
                    .catch(function (error) {

                        self.cleanupPopup();

                        self.releaseLock('Classic');
                        self.setStatus('idle');

                        /*
                        * User closed/cancelled the customer confirmation modal.
                        * This is NOT an error, so do not show an error message.
                        */
                        if (error && error.cancelled === true) {

                            console.log(
                                '[Bytenft] Customer confirmation cancelled by user'
                            );

                            return;
                        }

                        const message =
                            typeof error === 'string'
                                ? error
                                : error?.message ||
                                'Unable to validate customer information. Please try again.';

                        self.showCheckoutError(message);
                    });

                    /*
                    * Prevent native WooCommerce checkout.
                    */
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

            self.state.button = $('body').find(
                'button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn'
            ).first();

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            /*
            * Build the COMPLETE checkout payload.
            *
            * This contains:
            *
            * billing_first_name
            * billing_last_name
            * billing_email
            * billing_phone
            * billing_address_1
            * billing_address_2
            * billing_city
            * billing_state
            * billing_postcode
            * billing_country
            * etc.
            */
            const dataPayload = self.buildCheckoutPayload();

            const payload = new URLSearchParams(dataPayload);

            /*
            * =========================================================
            * CUSTOMER ACCOUNT DECISION
            * =========================================================
            */

            /*
            * CREATE NEW ACCOUNT
            *
            * IMPORTANT:
            * Do NOT send the existing customer user ID.
            */
            if (self.state.accountAction === 'create_new') {

                payload.set(
                    'bytenft_create_new_customer',
                    '1'
                );

                payload.set(
                    'bytenft_account_action',
                    'create_new'
                );

                payload.delete(
                    'bytenft_customer_user_id'
                );

                console.log(
                    '[Bytenft] Sending CREATE NEW ACCOUNT checkout'
                );

            }

            /*
            * USE EXISTING ACCOUNT
            */
            else {

                payload.set(
                    'bytenft_create_new_customer',
                    '0'
                );

                payload.set(
                    'bytenft_account_action',
                    'use_existing'
                );

                if (self.state.customerUserId) {

                    payload.set(
                        'bytenft_customer_user_id',
                        self.state.customerUserId
                    );

                } else {

                    payload.delete(
                        'bytenft_customer_user_id'
                    );
                }

                console.log(
                    '[Bytenft] Sending EXISTING ACCOUNT checkout',
                    {
                        customerUserId: self.state.customerUserId
                    }
                );
            }

            /*
            * Useful debugging.
            *
            * Do not log API secrets here.
            */
            console.log(
                '[Bytenft] Final checkout account data:',
                {
                    accountAction: self.state.accountAction,
                    createNewCustomer: self.state.createNewCustomer,
                    customerUserId: self.state.customerUserId,
                    email: payload.get('billing_email'),
                    phone: payload.get('billing_phone'),
                    firstName: payload.get('billing_first_name'),
                    lastName: payload.get('billing_last_name')
                }
            );

            $.ajax({

                type: 'POST',

                url: wc_checkout_params.checkout_url,

                data: payload.toString(),

                dataType: 'json',

                success: function (response) {

                    self.state.requestInFlightClassic = false;

                    self.handleResponse(response);
                },

                error: function (xhr) {

                    self.state.requestInFlightClassic = false;

                    console.log(
                        '[Bytenft] checkout network error:',
                        xhr.responseText
                    );

                    self.failSafe(
                        'There was an error processing your order.'
                    );
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
            const observeButton = function () {

                const btn = document.querySelector(
                    '.wc-block-components-checkout-place-order-button'
                );

                if (!btn) {
                    setTimeout(observeButton, 500);
                    return;
                }

                let feedbackNote = document.getElementById(
                    'bytenft-sync-note'
                );

                if (!feedbackNote) {

                    feedbackNote = document.createElement('div');

                    feedbackNote.id = 'bytenft-sync-note';

                    feedbackNote.style.color = '#777';
                    feedbackNote.style.fontSize = '13px';
                    feedbackNote.style.marginTop = '8px';
                    feedbackNote.style.display = 'none';

                    feedbackNote.innerText =
                        'Verifying payment option…';

                    btn.parentNode.insertBefore(
                        feedbackNote,
                        btn.nextSibling
                    );
                }

                // Remove previous observer
                if (self.state.buttonObserver) {

                    self.state.buttonObserver.disconnect();

                    self.state.buttonObserver = null;
                }

                const observer = new MutationObserver(
                    function (mutations) {

                        mutations.forEach(function (mutation) {

                            if (mutation.attributeName !== 'disabled') {
                                return;
                            }

                            const $form = $(
                                'form.wc-block-checkout__form'
                            );

                            const selected = $form.find(
                                'input[name="radio-control-wc-payment-method-options"]:checked'
                            ).val();

                            if (
                                btn.hasAttribute('disabled') &&
                                selected === self.PAYMENT_METHOD &&
                                !self.state.requestInFlightBlock
                            ) {

                                feedbackNote.style.display = 'block';

                            } else {

                                feedbackNote.style.display = 'none';
                            }
                        });
                    }
                );

                observer.observe(btn, {
                    attributes: true
                });

                self.state.buttonObserver = observer;
            };

            observeButton();


            /*
            * =========================================================
            * BLOCK CHECKOUT PLACE ORDER
            * =========================================================
            */

            document.addEventListener(
                'click',
                function (e) {

                    const btn = e.target.closest(
                        '.wc-block-components-checkout-place-order-button'
                    );

                    if (!btn) {
                        return;
                    }

                    const $form = $(
                        'form.wc-block-checkout__form'
                    );

                    if (!$form.length) {
                        return;
                    }

                    const selected = $form.find(
                        'input[name="radio-control-wc-payment-method-options"]:checked'
                    ).val();

                    /*
                    * Not ByteNFT payment method.
                    * Let WooCommerce handle it normally.
                    */
                    if (selected !== self.PAYMENT_METHOD) {
                        return;
                    }

                    /*
                    * Stop WooCommerce Blocks from submitting
                    * before our validation is complete.
                    */
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    if (!self.canProceed('Block')) {
                        return;
                    }

                    if (self.state.requestInFlightBlock) {
                        return;
                    }

                    self.state.requestInFlightBlock = true;

                    self.setStatus('validating');

                    self.clearCheckoutErrors();

                    const requiredError = self.validateRequiredFields($form);

                    if (requiredError) {
                        self.releaseLock('Block');
                        self.setStatus('idle');
                        self.showCheckoutError(requiredError.message);
                        return;
                    }

                    const validationError = self.validateAll($form);

                    if (validationError) {
                        self.releaseLock('Block');
                        self.setStatus('idle');
                        self.showCheckoutError(validationError);
                        return;
                    }


                    /*
                    * =================================================
                    * STEP 1: CHECK CUSTOMER ACCOUNT
                    * =================================================
                    *
                    * This calls /api/check-customer through the
                    * WordPress AJAX endpoint.
                    *
                    * If no confirmation is required:
                    *      continue automatically.
                    *
                    * If confirmation is required:
                    *      show the customer confirmation popup.
                    */

                    self.checkCustomerAccount()

                        .then(function (customerResult) {

                            console.log(
                                '[Bytenft] Customer validation result:',
                                customerResult
                            );

                           self.state.customerUserId =
                                customerResult.user_id || null;

                            self.state.createNewCustomer =
                                customerResult.create_new_user === true;

                            self.state.accountAction =
                                self.state.createNewCustomer
                                    ? 'create_new'
                                    : 'use_existing';

                            console.log(
                                '[Bytenft] Block checkout account decision:',
                                {
                                    accountAction: self.state.accountAction,
                                    createNewCustomer: self.state.createNewCustomer,
                                    customerUserId: self.state.customerUserId
                                }
                            );

                            console.log(
                                '[Bytenft] Selected customer user ID:',
                                self.state.customerUserId
                            );


                            /*
                            * =================================================
                            * STEP 2: OPEN PAYMENT POPUP
                            * =================================================
                            *
                            * We only open the payment popup after the
                            * customer confirmation has been completed.
                            */

                            self.setStatus('popup');

                            /*
                            * A blocked window is not a failure. On mobile
                            * Safari and Chrome this call is outside the
                            * original click gesture, so the window may simply
                            * not open - the payment then continues in this
                            * tab. Do not abort.
                            */
                            self.openPopupImmediately();


                            /*
                            * =================================================
                            * STEP 3: START PAYMENT PROCESSING
                            * =================================================
                            */

                            self.setStatus('processing');

                            self.handleBlockCheckout($form);

                        })

                        .catch(function (error) {

                            console.log(
                                '[Bytenft] Customer validation failed:',
                                error
                            );

                            self.releaseLock('Block');
                            self.setStatus('idle');

                            /*
                            * User intentionally closed the confirmation modal.
                            * Do not show an error.
                            */
                            if (error && error.cancelled === true) {

                                console.log(
                                    '[Bytenft] Customer confirmation cancelled by user'
                                );

                                return;
                            }

                            const message =
                                typeof error === 'string'
                                    ? error
                                    : error?.message ||
                                    'Unable to validate customer information. Please try again.';

                            self.showCheckoutError(message);
                        });
                },
                true
            );


            /*
            * =========================================================
            * PREVENT NATIVE BLOCK SUBMIT
            * =========================================================
            *
            * This prevents WooCommerce Blocks from bypassing our
            * customer validation and directly submitting the checkout.
            */

            document.addEventListener(
                'submit',
                function (e) {

                    const form = e.target;

                    if (
                        !form.classList.contains(
                            'wc-block-checkout__form'
                        )
                    ) {
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

            self.state.button =
                $('.wc-block-components-checkout-place-order-button');

            self.state.buttonText =
                self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            /*
            * Build complete checkout payload.
            */
            let data = self.buildCheckoutPayload();

            const payload = new URLSearchParams(data);

            /*
            * =========================================================
            * CUSTOMER ACCOUNT DECISION
            * =========================================================
            */

            /*
            * CREATE NEW ACCOUNT
            *
            * Do not send existing customer ID.
            */
            if (self.state.accountAction === 'create_new') {

                payload.set(
                    'bytenft_create_new_customer',
                    '1'
                );

                payload.set(
                    'bytenft_account_action',
                    'create_new'
                );

                payload.delete(
                    'bytenft_customer_user_id'
                );

                console.log(
                    '[Bytenft] Block checkout → CREATE NEW ACCOUNT'
                );
            }

            /*
            * EXISTING ACCOUNT
            */
            else {

                payload.set(
                    'bytenft_create_new_customer',
                    '0'
                );

                payload.set(
                    'bytenft_account_action',
                    'use_existing'
                );

                if (self.state.customerUserId) {

                    payload.set(
                        'bytenft_customer_user_id',
                        self.state.customerUserId
                    );

                } else {

                    payload.delete(
                        'bytenft_customer_user_id'
                    );
                }

                console.log(
                    '[Bytenft] Block checkout → USE EXISTING ACCOUNT',
                    {
                        customerUserId: self.state.customerUserId
                    }
                );
            }

            /*
            * Block checkout AJAX endpoint.
            */
            payload.set(
                'action',
                'bytenft_block_gateway_process'
            );

            payload.set(
                'nonce',
                bytenft_params.bytenft_nonce
            );

            /*
            * Debug only.
            * Never log API secrets.
            */
            console.log(
                '[Bytenft] Final Block checkout account data:',
                {
                    accountAction: self.state.accountAction,
                    createNewCustomer: self.state.createNewCustomer,
                    customerUserId: self.state.customerUserId,
                    email: payload.get('billing_email'),
                    phone: payload.get('billing_phone'),
                    firstName: payload.get('billing_first_name'),
                    lastName: payload.get('billing_last_name')
                }
            );

            $.ajax({

                type: 'POST',

                url: bytenft_params.ajax_url,

                data: payload.toString(),

                success: function (response) {

                    self.state.requestInFlightBlock = false;

                    self.handleResponse(response);
                },

                error: function (xhr) {

                    self.state.requestInFlightBlock = false;

                    console.log(
                        '[Bytenft] block checkout error:',
                        xhr.responseText
                    );

                    let message =
                        'There was an error processing your order.';

                    try {

                        let response =
                            JSON.parse(xhr.responseText);

                        message =
                            response?.message ||
                            response?.data?.message ||
                            response?.data?.error ||
                            message;

                        if (
                            response?.payment_result?.payment_details &&
                            Array.isArray(
                                response.payment_result.payment_details
                            )
                        ) {

                            const errorItem =
                                response.payment_result.payment_details.find(
                                    item => item.key === 'message'
                                );

                            if (errorItem?.value) {
                                message = errorItem.value;
                            }
                        }

                        if (
                            response?.data?.payment_result?.payment_details &&
                            Array.isArray(
                                response.data.payment_result.payment_details
                            )
                        ) {

                            const errorItem =
                                response.data.payment_result.payment_details.find(
                                    item => item.key === 'message'
                                );

                            if (errorItem?.value) {
                                message = errorItem.value;
                            }
                        }

                    } catch (e) {

                        console.log(
                            '[Bytenft] Unable to parse error response'
                        );
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

                        /*
                        * No window - either it was blocked (mobile Safari /
                        * Chrome) or the customer closed it. Send this tab to
                        * the payment page instead. Same no-referrer rule as
                        * the popup path: the Laravel payment page must not
                        * receive the WP checkout URL as its Referer.
                        */
                        self.navigateSameTabWithoutReferrer(redirect);

                        /*
                        * Do NOT unlock the checkout here.
                        *
                        * This tab is on its way to the payment page, but on a
                        * slow mobile connection that takes a moment. Calling
                        * finish() immediately re-enables Place Order, and a
                        * customer who taps it again during that gap fires a
                        * second payment request. Hold the lock and let the
                        * navigation unload the page.
                        *
                        * The timer is only a safety net for the case where the
                        * navigation never happens, so the checkout cannot end
                        * up locked forever.
                        */
                        if (self.state.button && self.state.button.length) {
                            self.state.button.text('Redirecting to secure payment...');
                        }

                        setTimeout(function () {
                            self.finish();
                        }, 15000);
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

        /*
        * Should this device get a payment popup at all?
        *
        * On a phone the answer is no. The payment window can only be opened
        * after checkCustomerAccount() has answered, which is one AJAX round
        * trip after the tap - and by then the user gesture is spent. Safari
        * responds to a non-gesture window.open() with "This site is
        * attempting to open a pop-up window / Block / Allow", and Chrome
        * mobile blocks it outright. Either way the customer is interrupted
        * by a decision they should never have been asked to make.
        *
        * Desktop keeps the popup: it has room for it, and the browsers there
        * do not prompt. Phones go straight to the payment page in this tab,
        * which is the normal mobile checkout pattern anyway - and they return
        * through the same ByteNFT return URL the popup flow uses.
        */
        shouldUsePaymentPopup: function () {

            try {

                const ua = navigator.userAgent || '';

                if (/Android|iPhone|iPad|iPod|IEMobile|Opera Mini|Mobile|Silk/i.test(ua)) {
                    return false;
                }

                /*
                * iPadOS 13+ reports a desktop Mac user agent, so fall back to
                * the touch-capable-and-narrow test to catch it.
                */
                const touchPoints = navigator.maxTouchPoints || 0;

                if (touchPoints > 1 && window.innerWidth <= 1024) {
                    return false;
                }

                return true;

            } catch (e) {
                // If detection itself fails, keep the desktop behaviour.
                return true;
            }
        },

        openPopupImmediately: function () {

            /*
            * Never even attempt the window on a device that would prompt or
            * block. Leaving state.popup null routes handleResponse() to its
            * same-tab navigation branch.
            */
            if (!this.shouldUsePaymentPopup()) {

                console.log(
                    '[Bytenft] Mobile browser - skipping popup, ' +
                    'payment will continue in this tab'
                );

                this.state.popup = null;
                this.state.popupBlocked = false;

                return null;
            }


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

            /*
            * Blocked - almost always Safari or Chrome on mobile.
            *
            * Those browsers only honour window.open() while the browser is
            * still inside the user gesture that triggered it, and by the time
            * we get here the check-customer request (and possibly the account
            * confirmation modal) has already broken that gesture.
            *
            * This is NOT an error and must not stop the payment. The caller
            * carries on without a window and handleResponse() sends the
            * customer to the payment link in this same tab instead, which is
            * how mobile checkouts normally behave anyway. They come back
            * through the ByteNFT return URL exactly as the popup flow does.
            */
            if (!this.state.popup) {

                console.log(
                    '[Bytenft] Payment window blocked by the browser - ' +
                    'falling back to same-tab redirect'
                );

                this.state.popupBlocked = true;

                return null;
            }

            this.state.popupBlocked = false;

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

        navigateSameTabWithoutReferrer: function (url) {

            /*
            * rel="noreferrer" suppresses the Referer header on a same-tab
            * navigation, which a plain location.href assignment cannot do.
            */
            try {

                const link = document.createElement('a');

                link.href = url;
                link.rel = 'noreferrer noopener';
                link.target = '_self';
                link.style.display = 'none';

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

            } catch (e) {

                console.log('[Bytenft] same-tab navigation fallback', e);

                window.location.href = url;
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

             /*
            * US phone validation
            */
            const phone = this.getPhoneNumber($form);

            if (phone) {

                const digits = phone.replace(/\D/g, '');

                if (digits.length !== 10) {
                    return 'Please enter a valid 10-digit US phone number.';
                }
            }


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
            this.state.customerUserId = null;
            this.state.createNewCustomer = false;
            this.state.accountAction = null;

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

        hideCheckoutLoader: function () {

            /*
            * The "Existing Account Found" modal is a QUESTION, not a wait.
            *
            * Place Order puts the checkout into its loading state, and that
            * overlay/spinner is still up when the modal appears - so the
            * customer sees a spinner sitting on top of a dialog that is
            * waiting on them. Take the loader down first.
            *
            * Only jQuery blockUI is touched here. It is what WooCommerce and
            * FunnelKit use, and its nodes are safe to remove; React-owned
            * spinners in the block checkout are left alone deliberately.
            */
            const $forms = $(
                'form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form, .wcf-embed-checkout-form-steps'
            );

            $forms.removeClass('processing');

            if (typeof $forms.unblock === 'function') {
                $forms.unblock();
            }

            /*
            * blockUI orphans its overlay whenever the element it blocked has
            * been re-rendered underneath it, so sweep up any strays too.
            */
            $('.blockUI').remove();
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

                    container.className = 'bytenft-checkout-errors';

                    /*
                    * Keep this OUTSIDE the WooCommerce React tree.
                    */
                    const parent = blockCheckoutWrapper.parentNode;

                    if (parent) {
                        parent.insertBefore(container, blockCheckoutWrapper);
                    }
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

            /*
            * Remove ONLY ByteNFT's own error container.
            *
            * This container is intentionally created OUTSIDE the
            * WooCommerce Blocks React tree.
            */
            const byteNFTError = document.getElementById(
                'bytenft-checkout-errors'
            );

            if (byteNFTError && byteNFTError.parentNode) {
                byteNFTError.parentNode.removeChild(byteNFTError);
            }

            /*
            * Classic checkout:
            * ByteNFT owns this wrapper, so it is safe to remove.
            */
            $('.bytenft-error-wrap').remove();

            /*
            * ByteNFT's own field errors are also safe to remove.
            */
            $('.bytenft-field-error').remove();

            /*
            * Remove only classes/styles that ByteNFT itself added.
            */
            $('.woocommerce-invalid').removeClass(
                'woocommerce-invalid woocommerce-invalid-required-field'
            );

            $('input, select, textarea').each(function () {

                /*
                * Only clear styles that ByteNFT adds.
                */
                if (
                    $(this).css('box-shadow') === 'rgb(214, 54, 56) 0px 0px 0px 1px' ||
                    $(this).css('border-color') === 'rgb(214, 54, 56)'
                ) {
                    $(this).css({
                        borderColor: '',
                        boxShadow: ''
                    });
                }
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
        },

        /**
         * =========================================================
         * CUSTOMER ACCOUNT CONFIRMATION
         * =========================================================
         */

        checkCustomerAccount: function () {

            const self = this;

            return new Promise(function (resolve, reject) {

                const checkoutPayload =
                    self.buildCheckoutPayload();

                $.ajax({

                    type: 'POST',

                    url: bytenft_params.ajax_url,

                    dataType: 'json',

                    data: {

                        action: 'bytenft_check_customer_account',

                        security: bytenft_params.bytenft_nonce,

                        checkout_data: checkoutPayload
                    },

                    success: function (response) {

                        console.log(
                            '[Bytenft] Customer account response:',
                            response
                        );

                        if (
                            !response ||
                            response.success !== true
                        ) {

                            reject(
                                response?.data?.message ||
                                response?.message ||
                                'Unable to validate customer information.'
                            );

                            return;
                        }

                        const data =
                            response.data || {};

                        /*
                        * =====================================================
                        * NO CONFIRMATION REQUIRED
                        * =====================================================
                        */

                        if (
                            data.action !== 'confirmation_required' ||
                            !data.requires_confirmation
                        ) {

                            resolve({

                                confirmed: false,

                                create_new_user: false,

                                user_id:
                                    data.user_id || null,

                                account_action:
                                    data.user_id
                                        ? 'use_existing'
                                        : null
                            });

                            return;
                        }

                        /*
                        * =====================================================
                        * EXISTING ACCOUNT FOUND
                        * =====================================================
                        */

                        self.showCustomerConfirmation(

                            data.message ||

                            'This email is already associated with another phone number. Would you like to continue with the existing account or create a new account with the entered phone number?',

                            data.user_id || null

                        ).then(function (confirmed) {

                            /*
                            * =================================================
                            * CONTINUE
                            * =================================================
                            *
                            * User selected:
                            *
                            * Continue
                            *
                            * Use existing account.
                            */

                            if (confirmed) {

                                console.log(
                                    '[Bytenft] User selected EXISTING ACCOUNT',
                                    {
                                        userId: data.user_id
                                    }
                                );

                                /*
                                * Save the user's selection.
                                *
                                * This is the IMPORTANT part:
                                * the selected existing customer ID is saved before
                                * payment processing starts.
                                */
                                self.saveCustomerAccountAction(
                                    'use_existing',
                                    data.user_id || 0
                                ).then(function (saveResponse) {

                                    if (!saveResponse || saveResponse.success !== true) {
                                        reject(
                                            saveResponse?.data?.message ||
                                            'Unable to save customer account selection.'
                                        );
                                        return;
                                    }

                                    resolve({

                                        confirmed: true,

                                        create_new_user: false,

                                        user_id: data.user_id || null,

                                        account_action: 'use_existing'
                                    });

                                }).catch(function () {

                                    reject(
                                        'Unable to save customer account selection. Please try again.'
                                    );

                                });

                                return;
                            }

                            /*
                            * =================================================
                            * CREATE NEW ACCOUNT
                            * =================================================
                            *
                            * User selected:
                            *
                            * Create New Account
                            *
                            * Do NOT use existing user ID.
                            */

                           console.log(
                                '[Bytenft] User selected CREATE NEW ACCOUNT'
                            );

                            /*
                            * Save CREATE NEW selection.
                            *
                            * user_id must be 0 because we explicitly do NOT
                            * want to use the existing customer.
                            */
                            self.saveCustomerAccountAction(
                                'create_new',
                                0
                            ).then(function (saveResponse) {

                                if (!saveResponse || saveResponse.success !== true) {
                                    reject(
                                        saveResponse?.data?.message ||
                                        'Unable to save customer account selection.'
                                    );
                                    return;
                                }

                                resolve({

                                    confirmed: true,

                                    create_new_user: true,

                                    user_id: null,

                                    account_action: 'create_new'
                                });

                            }).catch(function () {

                                reject(
                                    'Unable to save customer account selection. Please try again.'
                                );

                            });

                        }).catch(function (error) {

                            /*
                            * User closed the confirmation modal.
                            * This is cancellation, not validation failure.
                            */
                            if (error && error.cancelled === true) {
                                reject({
                                    cancelled: true
                                });
                                return;
                            }

                            reject(
                                error || {
                                    cancelled: true
                                }
                            );
                        });
                    },

                    error: function (xhr) {

                        console.log(
                            '[Bytenft] Customer account validation error:',
                            xhr.responseText
                        );

                        let message =
                            'Unable to validate customer information. Please try again.';

                        try {

                            const response = JSON.parse(xhr.responseText);

                            message =
                                response?.data?.phone_validation?.error ||
                                response?.data?.message ||
                                response?.message ||
                                message;

                            console.log(
                                '[Bytenft] Extracted customer validation error:',
                                message
                            );

                        } catch (e) {

                            console.log(
                                '[Bytenft] Unable to parse customer validation error response:',
                                e
                            );
                        }

                        reject(message);
                    }
                });
            });
        },

        /**
         * Show customer confirmation popup.
         */
        showCustomerConfirmation: function (message, userId) {

            const self = this;

            return new Promise(function (resolve, reject) {

                // Remove any existing confirmation modal
                $('#bytenft-customer-confirmation').remove();

                const html = `
                    <div id="bytenft-customer-confirmation"
                        style="
                            position:fixed;
                            top:0;
                            left:0;
                            right:0;
                            bottom:0;
                            width:100%;
                            height:100%;
                            background:rgba(0,0,0,.55);
                            z-index:2147483647;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            padding:20px;
                            pointer-events:auto;
                        ">

                        <div
                            style="
                                position:relative;
                                z-index:2147483647;
                                background:#fff;
                                width:100%;
                                max-width:500px;
                                border-radius:8px;
                                padding:30px;
                                box-shadow:0 10px 40px rgba(0,0,0,.25);
                                text-align:center;
                                pointer-events:auto;
                            "
                        >
                            <button
                                type="button"
                                id="bytenft-customer-confirm-close"
                                aria-label="Close"
                                style="
                                    position:absolute;
                                    top:10px;
                                    right:12px;
                                    width:32px;
                                    height:32px;
                                    border:0;
                                    background:transparent;
                                    color:#666;
                                    font-size:26px;
                                    line-height:32px;
                                    cursor:pointer;
                                    padding:0;
                                "
                            >
                                &times;
                            </button>
                            <h3 style="
                                margin:0 0 15px;
                                font-size:20px;
                            ">
                                Existing Account Found
                            </h3>

                            <p style="
                                margin:0 0 25px;
                                font-size:15px;
                                line-height:1.6;
                            ">
                                ${message}
                            </p>

                            <div style="
                                display:flex;
                                gap:12px;
                                justify-content:center;
                            ">

                                <button
                                    type="button"
                                    id="bytenft-customer-confirm-cancel"
                                    style="
                                        position:relative;
                                        z-index:2147483647;
                                        pointer-events:auto;
                                        padding:12px 24px;
                                        border:1px solid #ccc;
                                        background:#fff;
                                        border-radius:5px;
                                        cursor:pointer;
                                    "
                                >
                                    Create New Account
                                </button>

                                <button
                                    type="button"
                                    id="bytenft-customer-confirm-continue"
                                    style="
                                        position:relative;
                                        z-index:2147483647;
                                        pointer-events:auto;
                                        padding:12px 24px;
                                        border:0;
                                        background:#000;
                                        color:#fff;
                                        border-radius:5px;
                                        cursor:pointer;
                                    "
                                >
                                    Continue
                                </button>

                            </div>

                        </div>

                    </div>
                `;

                /*
                * Drop the checkout loading overlay before the modal appears -
                * this dialog waits on the customer, so a spinner over it is
                * wrong.
                */
                self.hideCheckoutLoader();

                $('body').append(html);

                /*
                * =========================================================
                * REMOVE OLD DELEGATED HANDLERS
                * =========================================================
                */

                $(document)
                    .off(
                        'click.bytenftCustomer',
                        '#bytenft-customer-confirm-close'
                    )
                    .off(
                        'click.bytenftCustomer',
                        '#bytenft-customer-confirm-cancel'
                    )
                    .off(
                        'click.bytenftCustomer',
                        '#bytenft-customer-confirm-continue'
                    );


                $(document).on(
                    'click.bytenftCustomer',
                    '#bytenft-customer-confirm-close',
                    function (e) {

                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        console.log(
                            '[Bytenft] Confirmation modal closed by user'
                        );

                        $('#bytenft-customer-confirmation').remove();

                        self.state.customerUserId = null;
                        self.state.createNewCustomer = false;
                        self.state.accountAction = null;

                        reject({
                            cancelled: true
                        });

                        return false;
                    }
                );  

                /*
                * =========================================================
                * CREATE NEW ACCOUNT
                * =========================================================
                */

                $(document).on(
                    'click.bytenftCustomer',
                    '#bytenft-customer-confirm-cancel',
                    function (e) {

                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        console.log(
                            '[Bytenft] Confirmation button clicked → CREATE NEW ACCOUNT'
                        );

                        $('#bytenft-customer-confirmation').remove();

                        /*
                        * Important:
                        * Explicitly tell the checkout state that we want
                        * a completely new customer.
                        */
                        self.state.customerUserId = null;
                        self.state.createNewCustomer = true;
                        self.state.accountAction = 'create_new';

                        resolve(false);

                        return false;
                    }
                );


                /*
                * =========================================================
                * USE EXISTING ACCOUNT
                * =========================================================
                */

                $(document).on(
                    'click.bytenftCustomer',
                    '#bytenft-customer-confirm-continue',
                    function (e) {

                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        console.log(
                            '[Bytenft] Confirmation button clicked → USE EXISTING ACCOUNT'
                        );

                        /*
                        * Get the existing user ID from the response.
                        *
                        * We need to keep this ID available until
                        * checkCustomerAccount() resolves.
                        */

                        $('#bytenft-customer-confirmation').remove();

                        self.state.customerUserId = userId || null;
                        self.state.createNewCustomer = false;
                        self.state.accountAction = 'use_existing';

                        resolve(true);

                        return false;
                    }
                );

            });
        },

        saveCustomerAccountAction: function (action, userId = 0) {

            const self = this;

            return $.ajax({
                url: bytenft_params.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'bytenft_save_customer_account_action',
                    security: bytenft_params.bytenft_nonce,
                    account_action: action,
                    customer_user_id: userId
                }
            })
            .then(function (response) {

                console.log(
                    '[ByteNFT] saveCustomerAccountAction response:',
                    response
                );

                if (!response || response.success !== true) {

                    const message =
                        response?.data?.message ||
                        response?.message ||
                        'Unable to save customer account selection.';

                    return $.Deferred()
                        .reject(message)
                        .promise();
                }

                return response;

            })
            .catch(function (error) {

                console.error(
                    '[ByteNFT] saveCustomerAccountAction failed:',
                    error
                );

                return $.Deferred()
                    .reject(
                        typeof error === 'string'
                            ? error
                            : 'Unable to save customer account selection.'
                    )
                    .promise();
            });
        },
    };

    $(document).ready(function () {
        BytenftCheckout.init();
    });

})(jQuery, window, document);
