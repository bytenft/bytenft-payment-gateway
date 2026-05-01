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
        return /^(\+1|1)?\d{10}$/.test(cleaned) ||
               /^(\+|00)[1-9]\d{6,14}$/.test(cleaned) ||
               /^\+?\d{7,20}$/.test(cleaned);
    }

    function containsPOBox(value) {
        const clean = value.replace(/[^a-z0-9]/gi, '').toLowerCase();
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

    // ❌ KEEP VISUAL ONLY — DO NOT TRUST THIS FOR PAYMENT FLOW
    function openPopupEarly() {
        if (!popupWindow || popupWindow.closed) {
            popupWindow = window.open('', '_blank', 'width=700,height=700');
        }

        if (popupWindow) {
            popupWindow.document.write(`
                <html>
                <head><title>Secure Payment</title></head>
                <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;">
                    <h3>Connecting to secure payment...</h3>
                </body>
                </html>
            `);
        }
    }

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

        // =========================
        // CLASSIC CHECKOUT
        // =========================
        $('form.checkout')
            .off('click.bytenft-classic')
            .on('click.bytenft-classic', 'button[name="woocommerce_checkout_place_order"]', function () {

                if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method) return;

                var $form = $('form.checkout');

                var email = getBillingEmail($form);
                var phone = getPhoneNumber($form);
                var poBoxError = validateNoPOBox($form);

                if (!isValidEmail(email)) return;
                if (phone !== '' && !isValidPhoneNumber(phone)) return;
                if (poBoxError) return;

                // ❌ REMOVED POPUP FROM HERE (BUG FIX)
                // openPopupEarly();
            });

        // =========================
        // BLOCK CHECKOUT
        // =========================
        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button')
            .off("click.bytenft")
            .on("click.bytenft", function (e) {

                if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method) {
                    return;
                }

                var $form = $('form.wc-block-checkout__form');

                var email = getBillingEmail($form);
                var phone = getPhoneNumber($form);

                if (!isValidEmail(email)) return;
                if (phone !== '' && !isValidPhoneNumber(phone)) return;
                if (validateNoPOBox($form)) return;

                // ❌ REMOVED POPUP FROM HERE (BUG FIX)
                // openPopupEarly();
            });
    }

    function handleFormSubmit(e) {
        e.preventDefault();
        var $form = $(this);

        if (isSubmitting) return;
        isSubmitting = true;

        $button = $form.find('button[name="woocommerce_checkout_place_order"]');
        originalButtonText = $button.text();

        $button.prop('disabled', true).text('Processing...');

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
    }

    // =========================
    // 🔥 FIXED FLOW HERE
    // =========================
    function handleResponse(response, $form) {
        if (response.result === 'success') {

            orderId = response.order_id;

            // ✅ ONLY NOW OPEN POPUP (SAFE POINT)
            openPopupEarly();

            if (response.redirect) {
                if (popupWindow && !popupWindow.closed) {
                    popupWindow.location.href = response.redirect;
                } else {
                    window.location.href = response.redirect;
                }
            }

        } else {
            if (popupWindow) {
                popupWindow.close();
                popupWindow = null;
            }
            displayError(response.error || 'Payment failed', $form);
        }
    }

    function handleError($form, msg) {
        if (popupWindow) {
            popupWindow.close();
            popupWindow = null;
        }

        $form.prepend('<div class="woocommerce-error">' + msg + '</div>');
        resetButton();
    }

    function displayError(msg, $form) {
        if (popupWindow) {
            popupWindow.close();
            popupWindow = null;
        }

        $form.prepend('<div class="woocommerce-error">' + msg + '</div>');
        resetButton();
    }

    function resetButton() {
        isSubmitting = false;
        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }
    }

    $(document.body).on("updated_checkout change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    markCheckoutFormIfNeeded();
    bindCheckoutHandler();
});