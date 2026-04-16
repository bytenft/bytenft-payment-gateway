jQuery(function ($) {
    var isSubmitting = false;
    var popupInterval;
    var paymentStatusInterval;
    var orderId;
    var $button;
    var originalButtonText;
    let popupWindow = null;

    var errorSelectors = [
        '.woocommerce-error',
        '.wc_er',
        '.wc-block-components-notice-banner',
        'ul[role="alert"]'
    ];

    function getPhoneNumber($form) {
        var selectors = [
            'input[name="billing_phone"]',
            'input[name="shipping_phone"]',
            'input[autocomplete="tel"]',
            'input[type="tel"]',
        ];

        for (var i = 0; i < selectors.length; i++) {
            var val = $form.find(selectors[i]).first().val();
            if (val && val.trim() !== '') return val.trim();
        }
        return '';
    }

    function isValidPhoneNumber(phone) {
        if (!phone || phone.trim() === '') return true;
        var cleaned = phone.replace(/[\s\-().]/g, '');
        var usPattern = /^(\+1|1)?\d{10}$/;
        var euPattern = /^(\+|00)[1-9]\d{6,14}$/;
        var generalPattern = /^\+?\d{7,20}$/;
        return usPattern.test(cleaned) || euPattern.test(cleaned) || generalPattern.test(cleaned);
    }

    function containsPOBox(value) {
        const clean = value.replace(/[^a-z0-9]/gi, '').toLowerCase();
        return /pob|postoffice/.test(clean);
    }

    function isValidEmail(email) {
        if (!email || email.trim() === '') return false;
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
    }

    function getBillingEmail($form) {
        var selectors = [
            '#billing_email',
            '#email',
            'input[type="email"]',
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
            ...($form.find('input[name*="address"]').map(function () {
                return $(this).val();
            }).get())
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
            var logoUrl = bytenft_params.bytenft_loader
                ? encodeURI(bytenft_params.bytenft_loader)
                : '';

            popupWindow.document.write(`
                <html>
                <head><title>Secure Payment</title></head>
                <body style="margin:0; display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; font-family:sans-serif; background:#ffffff; text-align:center;">
                    <div style="padding:20px;">
                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:150px; height:auto; margin-bottom:25px;" />` : ''}
                        <h2 style="font-size:18px;color:#333;margin:0;">Connecting to secure payment...</h2>
                        <p style="font-size:14px;color:#777;margin-top:10px;">Please do not refresh or close this window.</p>
                    </div>
                </body>
                </html>
            `);
        } else {
            alert("Popup blocked. Please allow popups for this site.");
        }
    }

    $('form.checkout').on('checkout_place_order', function () {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod === bytenft_params.payment_method) return false;
    });

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

        var formId = '#' + bytenft_params.payment_method + '-checkout-form';

        $(formId).off("submit.bytenft").on("submit.bytenft", function (e) {
            if ($(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
                $(this).closest('.woocommerce').find(errorSelectors.join(',')).remove();
                handleFormSubmit.call(this, e);
                return false;
            }
        });

        $('form.checkout')
            .off('click.bytenft-classic')
            .on('click.bytenft-classic', 'button[name="woocommerce_checkout_place_order"]', function (e) {

                e.preventDefault();

                if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method) return;

                var email = getBillingEmail($('form.checkout'));
                if (!isValidEmail(email)) return;

                var phone = getPhoneNumber($('form.checkout'));
                var $form = $('form.checkout');
                if (phone !== '' && !isValidPhoneNumber(phone)) {
                    displayError("Invalid phone number", $form);
                    return false;
                }

                if (validateNoPOBox($('form.checkout'))) return;

                openPopupEarly();
            });

        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button')
            .off("click.bytenft-popup")
            .off("click.bytenft-submit")
            .on("click.bytenft", function (e) {

                e.preventDefault();

                if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method) {
                    return;
                }

                $('.wc_er, .wc-block-components-notice-banner').remove();

                var errorList = '';
                var errorFlag = false;

                $('form.wc-block-checkout__form input').each(function () {
                    if (this.hasAttribute('required') && ($(this).val() === "" && !$(this).is(':checked'))) {
                        const inputLabel = $(this).attr("aria-label");
                        const spanLabel = $(this).closest("label").find("span").html();

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

                var email = getBillingEmail($('form.wc-block-checkout__form'));
                if (email !== '' && !isValidEmail(email)) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>Please enter a valid email address</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                var $blockForm = $('form.wc-block-checkout__form');

                var phone = getPhoneNumber($blockForm);
                if (phone !== '' && !isValidPhoneNumber(phone)) {
                    $blockForm.prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>Please enter a valid phone number</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

                var poBoxError = validateNoPOBox($('form.wc-block-checkout__form'));
                if (poBoxError) {
                    $blockForm.prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><ul style="margin:0"><li>' + poBoxError + '</li></ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }

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

        var phone = getPhoneNumber($form);
        if (phone !== '' && !isValidPhoneNumber(phone)) {
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            return false;
        }

        var poBoxError = validateNoPOBox($form);
        if (poBoxError) {
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            return false;
        }

        var isBlockCheckout = !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();

        if (isSubmitting || $form.data('bytenft-processing')) return false;

        isSubmitting = true;
        $form.data('bytenft-processing', true);

        $button = isBlockCheckout
            ? $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button')
            : $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');

        originalButtonText = $button.text();
        $button.prop('disabled', true).text('Processing...');

        var ajaxUrl = isBlockCheckout ? bytenft_params.ajax_url : wc_checkout_params.checkout_url;

        var ajaxData = isBlockCheckout
            ? { action: 'bytenft_block_gateway_process', nonce: bytenft_params.bytenft_nonce }
            : $form.serialize();

        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: ajaxData,
            dataType: 'json',
            success: function (response) {
                handleResponse(response, $form);
            },
            error: function () {
                handleError($form, "Server connection error.");
            },
            complete: function () {
                isSubmitting = false;
            }
        });
    }

    function handleResponse(response, $form) {
        $('.wc_er').remove();

        try {
            if (response.result === 'success') {
                orderId = response.order_id;

                openPopupEarly();
                openPaymentLink(response.redirect);
            } else {
                if (popupWindow) { popupWindow.close(); popupWindow = null; }
                displayError(response?.error || 'Payment failed.', $form);
            }
        } catch (err) {
            if (popupWindow) { popupWindow.close(); popupWindow = null; }
            displayError(err, $form);
        }
    }

    function openPaymentLink(paymentLink) {
        setTimeout(function () {
            if (popupWindow && !popupWindow.closed) {
                popupWindow.location.href = paymentLink;
            } else {
                window.location.href = paymentLink;
            }
        }, 300);

        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                popupWindow = null;
                resetButton();
            }
        }, 500);
    }

    function handleError($form, err) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        $form.prepend('<div class="wc_er">' + err + '</div>');
        resetButton();
    }

    function displayError(err, $form) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        var msg = typeof err === 'string' ? err : err.message || 'Payment failed';
        $form.prepend('<div class="wc_er wc-block-components-notice-banner is-error">' + msg + '</div>');
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
});