jQuery(function ($) {
    var isSubmitting = false;
    var popupInterval;
    var paymentStatusInterval;
    var orderId;
    var $button;
    var originalButtonText;
    let popupWindow = null;


    /**
     * Reads phone number from either classic or block checkout form.
     * Tries all known selectors in priority order for billing/shipping/auto fields.
     */
    function getPhoneNumber($form) {
        var selectors = [
            'input[name="billing_phone"]',      // classic checkout
            'input[name="shipping_phone"]',     // shipping phone
            'input[autocomplete="tel"]',        // block checkout WC 8+
            'input[type="tel"]',                // universal — any tel input
        ];
        for (var i = 0; i < selectors.length; i++) {
            var val = $form.find(selectors[i]).first().val();
            if (val && val.trim() !== '') return val.trim();
        }
        return '';
    }
     function isUSCountry($form) {
        if ($('#billing_country').length) return $('#billing_country').val() === 'US';
        var text = $form.text() || '';
        return text.indexOf('United States') !== -1 || text.indexOf('US') !== -1;
    }


    // Helper: Validate phone number (US/EU/general)
     function isValidPhoneNumber(phone, isUS) {
        if (!phone || phone.trim() === '') return true;
        if ((phone.match(/\+/g) || []).length > 1) return false;

        var cleaned        = phone.replace(/[\s\-().]/g, '');
        var numbersOnly    = cleaned.replace(/[^\d]/g, '');

        if (numbersOnly.length < 5 || numbersOnly.length > 15) return false;

        if (isUS) {
             return /^(\+1|1)?\d{10}$/.test(cleaned);
        }

        var usPattern      = /^(\+1|1)?\d{10}$/;
        var euPattern      = /^(\+|00)[1-9]\d{6,14}$/;
        var generalPattern = /^\+?\d{5,15}$/;
        
        return usPattern.test(cleaned) || euPattern.test(cleaned) || generalPattern.test(cleaned);
    }

    function containsPOBox(value) {
        if (!value) return false;

        // Normalize: lowercase + remove spaces and dots
        var normalized = value.toLowerCase().replace(/[\.\s]/g, '');

        return normalized.includes('pobox') || normalized.includes('postofficebox');
    }

    function isValidEmail(email) {
        if (!email || email.trim() === '') return false;
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
    }

    /**
     * Reads billing email from either classic or block checkout form.
     * Block checkout (WC 8+) uses id="email" with no "billing_" prefix.
     * We try every known selector in priority order.
     */
    function getBillingEmail($form) {
        var selectors = [
            '#billing_email',           // classic checkout
            '#email',                   // block checkout WC 8+
            'input[type="email"]',      // universal — catches any email input
            'input[autocomplete="email"]',
            'input[name="billing_email"]',
        ];
        for (var i = 0; i < selectors.length; i++) {
            var val = $form.find(selectors[i]).first().val();
            if (val && val.trim() !== '') return val.trim();
        }
        return '';
    }

    function validateNoPOBox($form) {
        var addressFields = [
            $form.find('#billing_address_1').val(),
            $form.find('#billing_address_2').val(),
            $form.find('#shipping_address_1').val(),
            $form.find('#shipping_address_2').val(),
            $form.find('input[autocomplete="address-line1"]').val(),
            $form.find('input[autocomplete="address-line2"]').val(),
            ...($form.find('input[name*="address"]').map(function () { return $(this).val(); }).get())
        ];

        for (var i = 0; i < addressFields.length; i++) {
            if (addressFields[i] && containsPOBox(addressFields[i])) {
                return 'PO Box addresses are not accepted. Please enter a physical street address.';
            }
        }
        return null;
    }

    function openPopupEarly() {
        if (!popupWindow || popupWindow.closed) {
            popupWindow = window.open('', '_blank', 'width=700,height=700');
        }

        if (popupWindow) {
            var logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
            popupWindow.document.write(`
                <html>
                <head><title>Secure Payment</title></head>
                <body style="margin:0; display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; font-family:sans-serif; background:#ffffff; text-align:center;">
                    <div style="padding:20px;">
                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:150px; height:auto; margin-bottom:25px;" />` : ''}
                        <h2 style="font-size:18px; color:#333; margin:0;">Connecting to secure payment...</h2>
                        <p style="font-size:14px; color:#777; margin-top:10px;">Please do not refresh or close this window.</p>
                    </div>
                </body>
                </html>
            `);
        } else {
            alert("Popup blocked. Please allow popups for this site.");
        }
    }

    // Prevent default WooCommerce form submission for our method
    $('form.checkout').on('checkout_place_order', function () {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod === bytenft_params.payment_method) return false;
    });

    // Assign or remove custom form ID based on selected method
    function markCheckoutFormIfNeeded() {
        var $form = $("form.checkout");
        var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
        var expectedId = bytenft_params.payment_method + '-checkout-form';

        if (selectedMethod === bytenft_params.payment_method) {
            $form.attr('id', expectedId);
        } else if ($form.attr('id') === expectedId) {
            $form.removeAttr('id');
        }
    }

    function bindCheckoutHandler() {

        // ── Classic checkout ──────────────────────────────────────────────────
        var formId = '#' + bytenft_params.payment_method + '-checkout-form';
        $(formId).off("submit.bytenft").on("submit.bytenft", function (e) {
            if ($(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
                $(this).closest('.woocommerce').find(errorSelectors.join(',')).remove();
                handleFormSubmit.call(this, e);
                return false;
            }
        });

        // Classic checkout Safari fix — opens popup early if validation passes.
        // handleFormSubmit is called via submit.bytenft above, not here.
        $('form.checkout')
            .off('click.bytenft-classic')
            .on('click.bytenft-classic', 'button[name="woocommerce_checkout_place_order"]', function () {
                if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method) return;

                var email = getBillingEmail($('form.checkout'));
                if (!isValidEmail(email)) return;

                // Classic: Validate phone using getPhoneNumber
                var phone = getPhoneNumber($('form.checkout'));
                if (phone !== '' && !isValidPhoneNumber(phone, isUSCountry($('form.checkout')))) return;


                if (validateNoPOBox($('form.checkout'))) return;
                if ($('form.checkout .woocommerce-invalid').length > 0) return;


                openPopupEarly();
            });

        // ── Block checkout — single unified handler ───────────────────────────
        // Replaces the old split between click.bytenft-popup and click.bytenft-submit.
        // All validation, popup open, and AJAX happen here in sequence.
        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button')
            .off("click.bytenft-popup")
            .off("click.bytenft-submit")
            .on("click.bytenft", function (e) {

                if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method) {
                    return;
                }

                $('.wc_er, .wc-block-components-notice-banner').remove();

                // Step 1: Required fields
                var errorList = '';
                var errorFlag = false;

                $('form.wc-block-checkout__form input').each(function () {
                    if (this.hasAttribute('required') && ($(this).val() === "" && !$(this).is(':checked'))) {
                        const inputLabel = $(this).attr("aria-label");
                        const spanLabel  = $(this).closest("label").find("span").html();

                        if (inputLabel) {
                            errorFlag = true;
                            errorList += '<li>' + inputLabel + ' field is required</li>';
                        } else if (spanLabel) {
                            errorFlag = true;
                            errorList += '<li>Please accept <b>"' + spanLabel + '"</b></li>';
                        }

                        $(this).focus().blur();
                    }
                });

                if (errorFlag) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0">' + errorList + '</ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                // Step 2: Email — only block popup/AJAX if email is present but invalid.
                // If the field is empty, the required-fields check above already caught it.
                var email = getBillingEmail($('form.wc-block-checkout__form'));
                if (email !== '' && !isValidEmail(email)) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>Please enter a valid email address.</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                // Step 3: Phone (use getPhoneNumber helper)
                var phone = getPhoneNumber($('form.wc-block-checkout__form'));
                 if (phone !== '' && !isValidPhoneNumber(phone, isUSCountry($('form.wc-block-checkout__form')))) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>Please enter a valid phone number or leave it blank.</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                // Step 4: PO Box
                var poBoxError = validateNoPOBox($('form.wc-block-checkout__form'));
                if (poBoxError) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>' + poBoxError + '</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                // Step 5: All valid — open popup (Safari fix) then fire AJAX
                var hasNativeBlockErrors = false;
                $('.wc-block-components-validation-error').each(function() {
                    if ($(this).text().trim() !== '') hasNativeBlockErrors = true;
                });
                if ($('form.wc-block-checkout__form .has-error').length > 0 || $('form.wc-block-checkout__form .is-invalid').length > 0) {
                    hasNativeBlockErrors = true;
                }

                if (hasNativeBlockErrors) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>Please fix the errors in the form before continuing.</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }
                openPopupEarly();
                handleFormSubmit.call($('form.wc-block-checkout__form'), e);
                return false;
            });
    }

    $(document.body).on("updated_checkout change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    markCheckoutFormIfNeeded();
    bindCheckoutHandler();

    // Input sanitization
    $('#billing_first_name, #billing_last_name, #billing_city').on('input', function () {
        this.value = this.value.replace(/[^A-Za-z\s]/g, '');
    });
    $('#billing_address_1').on('input', function () {
        this.value = this.value.replace(/[^A-Za-z0-9\s,.\-#]/g, '');
    });

    function handleFormSubmit(e) {
        e.preventDefault();
        var $form = $(this);
        $('.wc_er, .wc-block-components-notice-banner').remove();

        // Classic: Validate phone using getPhoneNumber
        var phone = getPhoneNumber($form);
        if (phone !== '' && !isValidPhoneNumber(phone, isUSCountry($form))) {
            $form.find('.woocommerce-error, .wc_er, .wc-block-components-notice-banner, ul[role="alert"]').remove();
            var $errorUl = $('<ul class="woocommerce-error" role="alert" style="list-style:none;margin:0 0 32px 0;"></ul>');
            $errorUl.append('<li>Please enter a valid phone number or leave it blank.</li>');
            $form.prepend($errorUl);
            $('html, body').animate({ scrollTop: $form.find('.woocommerce-error').offset().top - 300 }, 500);
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            return false;
        }

        var poBoxError = validateNoPOBox($form);
        if (poBoxError) {
            var $poErr = $('<ul class="woocommerce-error" role="alert" style="list-style:none;margin:0 0 32px 0;"></ul>');
            $poErr.append('<li>' + poBoxError + '</li>');
            $form.prepend($poErr);
            $('html, body').animate({ scrollTop: $poErr.offset().top - 300 }, 500);
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            return false;
        }

        setTimeout(function () {
            var isBlockCheckout = !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();

            if (isSubmitting || $form.data('bytenft-processing')) return false;

            isSubmitting = true;
            $form.data('bytenft-processing', true);

            $button = isBlockCheckout ?
                $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button') :
                $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');

            originalButtonText = $button.text();
            $button.prop('disabled', true).text('Processing...');

            var ajaxUrl  = isBlockCheckout ? bytenft_params.ajax_url : wc_checkout_params.checkout_url;
            var ajaxData = isBlockCheckout ? { action: 'bytenft_block_gateway_process', nonce: bytenft_params.bytenft_nonce } : $form.serialize();

            $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: ajaxData,
                dataType: isBlockCheckout ? undefined : 'json',
                success: function (response) {
                    handleResponse(response, $form);
                },
                error: function () { handleError($form, "Server connection error."); },
                complete: function () { isSubmitting = false; }
            });

            return false;
        }, 10);
    }

    function openPaymentLink(paymentLink) {
        setTimeout(function () {
            if (popupWindow && !popupWindow.closed) {
                popupWindow.location.href = paymentLink;
            } else if (!popupWindow) {
                window.location.href = paymentLink;
            }
        }, 300);

        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                clearInterval(paymentStatusInterval);
                popupWindow = null;

                $.post(bytenft_params.ajax_url, {
                    action: 'bytenft_popup_closed_event',
                    order_id: orderId,
                    security: bytenft_params.bytenft_nonce
                }, function (response) {
                    var isBlockSelected = $('input[name="radio-control-wc-payment-method-options"]:checked').val() === bytenft_params.payment_method;
                    if (!isBlockSelected) {
                        $(document.body).trigger('update_checkout');
                    }
                    if (response.success && response.data?.redirect_url) {
                        window.location.replace(response.data.redirect_url);
                    } else if (response.data?.notices) {
                        var $targetForm = isBlockSelected ? $('form.wc-block-checkout__form') : $('form.checkout');
                        displayError(response.data.notices, $targetForm);
                    }
                    resetButton();
                }, 'json');
            }
        }, 500);
    }

    function handleResponse(response, $form) {
        $('.wc_er').remove();
        try {
            if (response.result === 'success') {
                orderId = response.order_id;
                openPaymentLink(response.redirect);
            } else {
                if (popupWindow) { popupWindow.close(); popupWindow = null; }
                displayError(response?.error || response?.notices || response?.messages || 'Payment failed.', $form);
            }
        } catch (err) {
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            displayError(err, $form);
        }
    }

    function handleError($form, err) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        $form.prepend('<div class="wc_er">' + err + '</div>');
        $('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
        resetButton();
    }

    function displayError(err, $form) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        $('.wc_er, .wc-block-components-notice-banner').remove();
        var errorMessage = (typeof err === 'string' ? err : err?.message || 'Payment failed').toString().trim();

        var $error = $('<div>', {
            class: 'wc_er wc-block-components-notice-banner is-error',
            text: errorMessage
        });

        $form.prepend($error);
        $('html, body').animate({ scrollTop: $error.offset().top - 300 }, 500);
        resetButton();
    }

    function resetButton() {
        isSubmitting = false;
        var $form = $('form.checkout, form.wc-block-checkout__form');
        $form.removeData('bytenft-processing');
        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }
    }

    function isValidPhoneNumber(phone, isUS) {
        if (!phone || phone.trim() === '') return true;
        if ((phone.match(/\+/g) || []).length > 1) return false;

        var cleaned        = phone.replace(/[\s\-().]/g, '');
        var numbersOnly    = cleaned.replace(/[^\d]/g, '');

        if (numbersOnly.length < 5 || numbersOnly.length > 15) return false;

        if (isUS) {
             return /^(\+1|1)?\d{10}$/.test(cleaned);
        }

        var usPattern      = /^(\+1|1)?\d{10}$/;
        var euPattern      = /^(\+|00)[1-9]\d{6,14}$/;
        var generalPattern = /^\+?\d{5,15}$/;

        return usPattern.test(cleaned) || euPattern.test(cleaned) || generalPattern.test(cleaned);
    }

    var errorSelectors = [
        '.woocommerce-error',
        '.wc_er',
        '.wc-block-components-notice-banner',
        'ul[role="alert"]'
    ];
});