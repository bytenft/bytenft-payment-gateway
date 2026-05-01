jQuery(function ($) {

    var isSubmitting = false;
    var popupWindow = null;
    var popupInterval = null;
    var orderId = null;
    var $button;
    var originalButtonText;

    var errorSelectors = [
        '.woocommerce-error',
        '.wc_er',
        '.wc-block-components-notice-banner',
        'ul[role="alert"]'
    ];

    /* =========================
       HARD BLOCK WC SUBMISSION
    ========================== */

    $(document).on('submit', 'form.checkout', function (e) {
        if (getSelectedMethod() === bytenft_params.payment_method) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
    });

    /* =========================
       HELPERS (UNCHANGED)
    ========================== */

    function getSelectedMethod() {
        return $('input[name="payment_method"]:checked').val() ||
               $('input[name="radio-control-wc-payment-method-options"]:checked').val();
    }

    function getActiveForm() {
        return $('form.checkout:visible, form.wc-block-checkout__form:visible').first();
    }

    function getPhoneNumber($form) {
        var selectors = [
            'input[name="billing_phone"]',
            'input[name="shipping_phone"]',
            'input[autocomplete="tel"]',
            'input[type="tel"]'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var val = $form.find(selectors[i]).first().val();
            if (val && val.trim() !== '') return val.trim();
        }
        return '';
    }

    function isValidPhoneNumber(phone) {
        if (!phone) return true;
        var cleaned = phone.replace(/[\s\-().]/g, '');
        return /^(\+1|1)?\d{10}$/.test(cleaned) ||
               /^(\+|00)[1-9]\d{6,14}$/.test(cleaned) ||
               /^\+?\d{7,20}$/.test(cleaned);
    }

    function isValidEmail(email) {
        return email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
    }

    function getBillingEmail($form) {
        return $form.find('#billing_email, #email, input[type="email"]').first().val() || '';
    }

    function containsPOBox(value) {
        var clean = (value || '').replace(/[^a-z0-9]/gi, '').toLowerCase();
        return /pob|postoffice/.test(clean);
    }

    function validateNoPOBox($form) {
        var fields = $form.find('input[name*="address"]').map(function () {
            return $(this).val();
        }).get();

        for (var i = 0; i < fields.length; i++) {
            if (fields[i] && containsPOBox(fields[i])) {
                return 'PO Box addresses are not accepted.';
            }
        }
        return null;
    }

    /* =========================
       UI HELPERS
    ========================== */

    function clearErrors() {
        $(errorSelectors.join(',')).remove();
    }

    function showError($form, msg) {

        if (popupWindow) {
            popupWindow.close();
            popupWindow = null;
        }

        clearErrors();

        var $error = $('<ul class="woocommerce-error"><li>' + msg + '</li></ul>');

        var $wrap = $('.woocommerce-notices-wrapper').first();

        if ($wrap.length) {
            $wrap.prepend($error);
        } else {
            $form.prepend($error);
        }

        setTimeout(function () {
            $('html, body').animate({
                scrollTop: $error.offset().top - 200
            }, 400);
        }, 50);

        unlockButton();
    }

    function lockButton($form) {
        $button = $form.find('button[type="submit"], .wc-block-components-checkout-place-order-button');
        originalButtonText = $button.text();
        $button.prop('disabled', true).text('Processing...');
    }

    function unlockButton() {
        isSubmitting = false;
        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }
    }

    /* =========================
       VALIDATION
    ========================== */

    function validateCheckout($form) {

        var email = getBillingEmail($form);
        if (!isValidEmail(email)) return "Invalid email";

        var phone = getPhoneNumber($form);
        if (phone && !isValidPhoneNumber(phone)) return "Invalid phone";

        var po = validateNoPOBox($form);
        if (po) return po;

        return null;
    }

    /* =========================
       POPUP
    ========================== */

    function openPopup(url) {

        popupWindow = window.open('about:blank', '_blank', 'width=700,height=700');

        if (!popupWindow) {
            window.location.href = url;
            return;
        }

        popupWindow.location.href = url;

        monitorPopup();
    }

    function monitorPopup() {

        popupInterval = setInterval(function () {

            if (!popupWindow || popupWindow.closed) {

                clearInterval(popupInterval);
                popupWindow = null;

                checkPaymentStatus();
            }

        }, 500);
    }

    /* =========================
       PAYMENT STATUS CHECK
    ========================== */

    function checkPaymentStatus() {

        $.post(bytenft_params.ajax_url, {
            action: 'bytenft_popup_closed_event',
            order_id: orderId,
            security: bytenft_params.bytenft_nonce
        }, function (res) {

            if (res.success && res.data?.redirect_url) {
                window.location.href = res.data.redirect_url;
            } else {
                showError(getActiveForm(), "Payment not completed");
            }

        }, 'json');
    }

    /* =========================
       RESPONSE HANDLER
    ========================== */

    function handleResponse(response, $form) {

        var success = response && response.result === 'success' && response.redirect;

        if (!success) {
            return showError($form, extractErrorMessage(response));
        }

        orderId = response.order_id;

        openPopup(response.redirect);
    }

    function extractErrorMessage(response) {

        if (!response) return "Payment failed";

        if (response.error) return response.error;
        if (response.message) return response.message;

        if (response.payment_result && response.payment_result.payment_details) {
            for (var i = 0; i < response.payment_result.payment_details.length; i++) {
                var item = response.payment_result.payment_details[i];
                if (item.key === 'message' || item.key === 'error') {
                    return item.value;
                }
            }
        }

        return "Payment failed";
    }

    /* =========================
       MAIN FLOW (ENGINE)
    ========================== */

    function startCheckout() {

        if (isSubmitting) return;

        var $form = getActiveForm();

        clearErrors();

        var error = validateCheckout($form);
        if (error) return showError($form, error);

        isSubmitting = true;

        lockButton($form);

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url,
            data: $form.serialize(),
            dataType: 'json',

            success: function (response) {
                handleResponse(response, $form);
            },

            error: function () {
                showError($form, "Server error");
            },

            complete: function () {
                isSubmitting = false;
            }
        });
    }

    /* =========================
       EVENT BINDING
    ========================== */

    function bindCheckoutHandler() {

        // Classic
        $('form.checkout')
            .off('click.bytenft')
            .on('click.bytenft', 'button[name="woocommerce_checkout_place_order"]', function (e) {

                if (getSelectedMethod() !== bytenft_params.payment_method) return;

                e.preventDefault();
                startCheckout();
            });

        // Block
        $('form.wc-block-checkout__form')
            .off('click.bytenft')
            .on('click.bytenft', '.wc-block-components-checkout-place-order-button', function (e) {

                if (getSelectedMethod() !== bytenft_params.payment_method) return;

                e.preventDefault();
                startCheckout();
            });
    }

    /* =========================
       INIT
    ========================== */

    $(document.body).on("updated_checkout change", function () {
        bindCheckoutHandler();
    });

    bindCheckoutHandler();

    /* =========================
       SAFARI FIX
    ========================== */

    $(window).on('pagehide beforeunload', function () {
        if (popupWindow) {
            popupWindow.close();
            popupWindow = null;
        }
    });

});