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
            lock: false, // GLOBAL MUTEX
            popup: null,
            popupInterval: null,
            orderId: null,
            button: null,
            buttonText: '',
            requestInFlight: false,
            responseHandled: false
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

        /* =========================================================
         * GLOBAL LOCK HELPERS (CRITICAL)
         * ========================================================= */

        acquireLock: function () {
            if (this.state.lock) return false;
            this.state.lock = true;
            return true;
        },

        releaseLock: function () {
            this.state.lock = false;
        },

        setStatus: function (status) {
            this.state.status = status;
        },

        canProceed: function () {
            return !this.state.lock && this.state.status !== 'processing';
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

                    if (!self.canProceed()) return false;
                    if (!self.acquireLock()) return false;

                    self.setStatus('validating');
                    self.clearCheckoutErrors();

                    const requiredError = self.validateRequiredFields($form);
                    if (requiredError) {
                        self.releaseLock();
                        self.setStatus('idle');
                        self.showCheckoutError(requiredError.message, requiredError.fields);
                        return false;
                    }

                    const validationError = self.validateAll($form);
                    if (validationError) {
                        self.releaseLock();
                        self.setStatus('idle');
                        self.showCheckoutError(validationError);
                        return false;
                    }

                    self.setStatus('popup');
                    self.openPopupImmediately();
                    self.setStatus('processing');

                    self.handleClassicCheckout($form);
                    return false;
                });
        },

        handleClassicCheckout: function ($form) {
            const self = this;

            if (self.state.requestInFlight) return;
            self.state.requestInFlight = true;

            self.state.button = $('body').find('button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn').first();
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            // Form payload compilation handles scattered form fields elegantly
            const dataPayload = $('form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form, #order_review').serialize();

            $.ajax({
                type: 'POST',
                url: wc_checkout_params.checkout_url,
                data: dataPayload,
                dataType: 'json',
                success: function (response) {
                    self.state.requestInFlight = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {
                    self.state.requestInFlight = false;
                    console.log('[Bytenft] checkout network error:', xhr.responseText);
                    self.failSafe('There was an error processing your order.');
                }
            });
        },

        /* =========================================================
         * BLOCK CHECKOUT
         * ========================================================= */

        bindBlockCheckout: function () {
            const self = this;

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

                if (!self.canProceed()) return;
                if (!self.acquireLock()) return;

                self.setStatus('validating');
                self.clearCheckoutErrors();

                const requiredError = self.validateRequiredFields($form);
                if (requiredError) {
                    self.releaseLock();
                    self.setStatus('idle');
                    self.showCheckoutError(requiredError.message, requiredError.fields);
                    return;
                }

                const validationError = self.validateAll($form);
                if (validationError) {
                    self.releaseLock();
                    self.setStatus('idle');
                    self.showCheckoutError(validationError);
                    return;
                }

                self.setStatus('popup');
                self.openPopupImmediately();
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

            if (self.state.requestInFlight) return;
            self.state.requestInFlight = true;

            self.state.button = $('.wc-block-components-checkout-place-order-button');
            self.state.buttonText = self.state.button.text();

            self.state.button.prop('disabled', true).addClass('loading').text('Processing...');

            let data = $('form.wc-block-checkout__form, form.checkout, #order_review').serialize();
            data += '&action=bytenft_block_gateway_process';
            data += '&nonce=' + encodeURIComponent(bytenft_params.bytenft_nonce);

            $.ajax({
                type: 'POST',
                url: bytenft_params.ajax_url,
                data: data,
                success: function (response) {
                    self.state.requestInFlight = false;
                    self.handleResponse(response);
                },
                error: function (xhr) {
                    self.state.requestInFlight = false;
                    console.log('[Bytenft] block checkout error:', xhr.responseText);
                    self.failSafe('There was an error processing your order.');
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

                console.log('[Bytenft] parsed response', response);

                const success =
                    response?.result === 'success' ||
                    response?.success === true ||
                    response?.data?.payment_status === 'success' ||
                    response?.data?.payment_status === 'paid';

                const redirect = response?.redirect || response?.data?.redirect;
                const orderId = response?.order_id || response?.data?.order_id;

                self.state.orderId = orderId;

                if (!success) {
                    self.failSafe(response?.message || response?.data?.message || 'Payment failed. Please try again.');
                    return;
                }

                if (redirect) {
                    self.state.responseHandled = true;

                    if (self.state.popup && !self.state.popup.closed) {
                        try {
                            self.state.popup.location.href = redirect;
                        } catch (e) {
                            // Safari cross-domain window write mitigation fallback
                            self.state.popup = window.open(redirect, '_blank');
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
            this.cleanupPopup();
            this.showCheckoutError(message);
            this.finish();
        },

        finish: function () {
            this.setStatus('done');
            this.reset(true);
            this.releaseLock();

            this.state.responseHandled = false;
            this.state.requestInFlight = false;

            setTimeout(() => {
                this.setStatus('idle');
            }, 500);
        },

        /* =========================================================
         * POPUP HANDLERS
         * ========================================================= */

        openPopupImmediately: function () {
            if (this.state.popup && !this.state.popup.closed) return;

            this.state.popup = window.open('', '_blank', 'width=700,height=700');

            if (!this.state.popup) {
                alert('Popup blocked. Please allow popups for your payment.');
                return;
            }

            const logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';

            this.state.popup.document.write(`
                <!DOCTYPE html>
                <html>
                <head><title>Secure Payment</title></head>
                <body style="margin:0;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;background:#fff;text-align:center;">
                    <div>
                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:120px;margin-bottom:20px;" />` : ''}
                        <h3>Connecting to secure payment...</h3>
                        <p>Please do not close this window.</p>
                    </div>
                </body>
                </html>
            `);
            this.state.popup.document.close();
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
            let redirected = false;

            clearInterval(self.state.popupInterval);

            self.state.popupInterval = setInterval(function () {
                if (redirected) return;

                const popupExists = self.state.popup && !self.state.popup.closed;

                $.post(
                    bytenft_params.ajax_url,
                    {
                        action: 'bytenft_popup_closed_event',
                        order_id: self.state.orderId,
                        security: bytenft_params.bytenft_nonce
                    },
                    function (response) {
                        if (redirected) return;

                        const paymentSuccess =
                            response?.success === true ||
                            response?.data?.payment_status === 'success' ||
                            response?.data?.payment_status === 'paid';

                        const redirectUrl = response?.data?.redirect || response?.redirect;

                        if (paymentSuccess && redirectUrl) {
                            redirected = true;
                            clearInterval(self.state.popupInterval);
                            self.cleanupPopup();
                            window.location.replace(redirectUrl);
                            return;
                        }

                        if (!popupExists) {
                            redirected = true;
                            clearInterval(self.state.popupInterval);
                            const failedMessage = response?.message || response?.data?.message || 'Your payment could not be completed. Please try again.';
                            self.failSafe(failedMessage);
                        }
                    },
                    'json'
                );
            }, 1500);
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

            return null;
        },

        validateRequiredFields: function ($form) {
            let missing = [];
            let firstInvalid = null;
            const isShippingActive = this.getShippingState($form);

            const $allFields = $('form.checkout, form.wc-block-checkout__form, form#wcf-embed-checkout-form').find('[required]');

            $allFields.each(function () {
                const $field = $(this);
                const name = $field.attr('name') || '';

                if ($field.attr('type') === 'hidden') return;

                if (name.indexOf('shipping_') === 0 && !isShippingActive) return;

                const $conditionalParent = $field.closest('.wcf-conditional-field, .woocommerce-validated');
                if ($conditionalParent.length && $conditionalParent.is(':hidden')) return;

                const val = ($field.val() || '').trim();
                const $wrapper = $field.closest('.form-row, .wc-block-components-text-input, .form-row-first, .form-row-last');

                if (!val) {
                    $wrapper.addClass('woocommerce-invalid woocommerce-invalid-required-field');

                    let label = $wrapper.find('label').first().text().trim() || $field.attr('placeholder') || name;
                    label = label.replace('*', '').trim();

                    if (label && !missing.includes(label)) {
                        missing.push(label);
                    }
                    if (!firstInvalid) firstInvalid = $field;
                } else {
                    $wrapper.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            if (firstInvalid && firstInvalid.is(':visible')) {
                setTimeout(function () { firstInvalid.trigger('focus'); }, 100);
            }

            return missing.length ? { message: 'Please fill required fields.', fields: missing } : null;
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
            this.state.submitting = false;

            if (this.state.popupInterval) {
                clearInterval(this.state.popupInterval);
                this.state.popupInterval = null;
            }

            const $button = $('.wc-block-components-checkout-place-order-button, button[name="woocommerce_checkout_place_order"], #wcf-order-place-btn');
            if (!$button.length) return;

            if (keepDisabled) {
                $button.prop('disabled', true).addClass('loading').text('Processing...');
                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(this.state.buttonText || 'Place order');
        },

        showCheckoutError: function (message, fields = []) {
            $('.bytenft-error-wrap, .woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper').remove();

            let fieldsHtml = '';
            if (fields.length) {
                fieldsHtml = `
                    <ul class="bytenft-error-fields" style="margin-top: 5px; padding-left: 20px;">
                        ${fields.map(field => `<li>${field}</li>`).join('')}
                    </ul>`;
            }

            const html = `
                <div class="woocommerce-notices-wrapper wcf-woocommerce-notices-wrapper bytenft-error-wrap">
                    <div class="woocommerce-error bytenft-error-box" role="alert" style="border-left: 3px solid #cc0000; padding: 1em; background: #fff1f1;">
                        <div class="bytenft-error-header"><strong>${message}</strong></div>
                        ${fieldsHtml}
                    </div>
                </div>`;

            const targets = ['.wc-block-checkout__form', 'form.checkout', 'form#wcf-embed-checkout-form', '.wcf-embed-checkout-form-steps'];
            let inserted = false;

            for (let target of targets) {
                const $el = $(target);
                if ($el.length) {
                    $el.prepend(html);
                    inserted = true;
                    break;
                }
            }

            if (!inserted) {
                $('body').prepend(html);
            }

            const $notice = $('.woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper');
            if ($notice.length) {
                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 300);
            }
        },

        clearCheckoutErrors: function () {
            $('.woocommerce-notices-wrapper, .wcf-woocommerce-notices-wrapper, .woocommerce-error, .wc-block-components-notice-banner, .woocommerce-message, .woocommerce-info, .bytenft-error-wrap').remove();
        },

        getBillingEmail: function ($f) {
            return $('body').find('#billing_email, #email, input[type="email"]').first().val();
        },

        getPhoneNumber: function ($f) {
            return $('body').find('input[name="billing_phone"], input[type="tel"]').first().val();
        },

        isValidEmail: function (e) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
        },

        isValidPhoneNumber: function (p) {
            if (!p) return true;
            const cleaned = p.replace(/[\s\-().]/g, '');
            return (/^(\+1|1)?\d{10}$/.test(cleaned) || /^(\+|00)[1-9]\d{6,14}$/.test(cleaned) || /^\+?\d{5,15}$/.test(cleaned));
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