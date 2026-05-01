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

    /* =========================
       HELPERS (UNCHANGED LOGIC)
    ========================== */

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
        return /^(\+1|1)?\d{10}$/.test(cleaned) ||
               /^(\+|00)[1-9]\d{6,14}$/.test(cleaned) ||
               /^\+?\d{7,20}$/.test(cleaned);
    }

    function containsPOBox(value) {
        var clean = (value || '').replace(/[^a-z0-9]/gi, '').toLowerCase();
        return /pob|postoffice/.test(clean);
    }

    function isValidEmail(email) {
        return email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
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
            ...($form.find('input[name*="address"]').map(function () { return $(this).val(); }).get())
        ];

        for (var i = 0; i < addressFields.length; i++) {
            if (addressFields[i] && containsPOBox(addressFields[i])) {
                return 'PO Box addresses are not accepted. Please enter a physical street address.';
            }
        }
        return null;
    }

    /* =========================
       POPUP (SAFE FIX)
    ========================== */

    function openPopupEarly() {
        if (!popupWindow || popupWindow.closed) {
            popupWindow = window.open('', '_blank', 'width=700,height=700');
        }

        if (!popupWindow) {
            console.warn("Popup blocked");
            return;
        }

        popupWindow.document.open();
        popupWindow.document.write(`
            <html>
            <head><title>Secure Payment</title></head>
            <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;">
                <h3>Connecting to secure payment...</h3>
            </body>
            </html>
        `);
        popupWindow.document.close();
    }

    /* =========================
       FORM CONTROL FIX
    ========================== */

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

    /* =========================
       🚨 CRITICAL FIX: STOP AUTO SUBMIT
    ========================== */

    $(document).on('submit', 'form.checkout', function (e) {
        if ($('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
            e.preventDefault();
            return false;
        }
    });

    /* =========================
       MAIN HANDLER
    ========================== */

    function bindCheckoutHandler() {

        $('form.checkout')
            .off('click.bytenft-classic')
            .on('click.bytenft-classic', 'button[name="woocommerce_checkout_place_order"]', function (e) {

                if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method) return;

                e.preventDefault();

                if (isSubmitting) return;
                isSubmitting = true;

                var $form = $('form.checkout');

                $button = $(this);
                originalButtonText = $button.text();

                var email = getBillingEmail($form);
                var phone = getPhoneNumber($form);
                var poBoxError = validateNoPOBox($form);

                // 🚨 VALIDATE FIRST
                if (!isValidEmail(email)) return handleError($form, "Invalid email");
                if (phone !== '' && !isValidPhoneNumber(phone)) return handleError($form, "Invalid phone");
                if (poBoxError) return handleError($form, poBoxError);

                $button.prop('disabled', true).text('Processing...');

                // 🚨 OPEN ONLY AFTER VALIDATION PASSES
                openPopupEarly();

                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.checkout_url,
                    data: $form.serialize(),
                    dataType: 'json',

                    success: function (response) {
                        handleResponse(response, $form);
                    },

                    error: function () {
                        handleError($form, "Server error");
                    },

                    complete: function () {
                        isSubmitting = false;
                    }
                });
            });

        /* BLOCK CHECKOUT */
        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button')
            .off("click.bytenft")
            .on("click.bytenft", function (e) {

                if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method) {
                    return;
                }

                e.preventDefault();

                var $form = $('form.wc-block-checkout__form');

                var email = getBillingEmail($form);
                var phone = getPhoneNumber($form);
                var poBoxError = validateNoPOBox($form);

                if (!isValidEmail(email)) return handleError($form, "Invalid email");
                if (phone !== '' && !isValidPhoneNumber(phone)) return handleError($form, "Invalid phone");
                if (poBoxError) return handleError($form, poBoxError);

                openPopupEarly();

                handleFormSubmit.call($form, e);
            });
    }

    /* =========================
       AJAX RESPONSE
    ========================== */

    function handleResponse(response, $form) {

        if (response && response.result === 'success') {

            orderId = response.order_id;

            if (response.redirect) {
                if (popupWindow && !popupWindow.closed) {
                    popupWindow.location.href = response.redirect;
                } else {
                    window.location.href = response.redirect;
                }
            }

            return;
        }

        var msg = extractErrorMessage(response);
        handleError($form, msg);
    }

    function extractErrorMessage(response) {

        if (!response) return 'Payment failed';

        if (response.error) return response.error;
        if (response.message) return response.message;

        if (response.payment_result?.payment_details) {
            for (var i = 0; i < response.payment_result.payment_details.length; i++) {
                var item = response.payment_result.payment_details[i];
                if (item.key === 'message' || item.key === 'error') {
                    return item.value;
                }
            }
        }

        return 'Payment failed. Please try again.';
    }

    /* =========================
       ERROR HANDLING (FIXED UI)
    ========================== */

    function handleError($form, msg) {

        isSubmitting = false;

        if (popupWindow) {
            popupWindow.close();
            popupWindow = null;
        }

        $('.woocommerce-error, .wc_er, .wc-block-components-notice-banner').remove();

        var $error = $('<ul class="woocommerce-error" role="alert"><li>' + msg + '</li></ul>');

        var $wrap = $('.woocommerce-notices-wrapper').first();

        if ($wrap.length) {
            $wrap.prepend($error);
        } else {
            $form.prepend($error);
        }

        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }

        $('html, body').animate({
            scrollTop: $error.offset().top - 200
        }, 500);
    }

    /* =========================
       INIT
    ========================== */

    $(document.body).on("updated_checkout change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    markCheckoutFormIfNeeded();
    bindCheckoutHandler();
});