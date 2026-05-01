jQuery(function ($) {

    let isSubmitting = false;
    let $button;
    let originalButtonText;

    /* -----------------------------
     * Helpers (UNCHANGED)
     * ----------------------------- */

    function getPhoneNumber($form) {
        const selectors = [
            'input[name="billing_phone"]',
            'input[name="shipping_phone"]',
            'input[autocomplete="tel"]',
            'input[type="tel"]',
        ];

        for (let s of selectors) {
            let val = $form.find(s).first().val();
            if (val && val.trim()) return val.trim();
        }
        return '';
    }

    function isValidPhoneNumber(phone) {
        if (!phone) return true;
        const cleaned = phone.replace(/[\s\-().]/g, '');
        return /^(\+1|1)?\d{10}$/.test(cleaned) ||
               /^(\+|00)[1-9]\d{6,14}$/.test(cleaned) ||
               /^\+?\d{7,20}$/.test(cleaned);
    }

    function getBillingEmail($form) {
        const selectors = [
            '#billing_email',
            '#email',
            'input[type="email"]',
            'input[autocomplete="email"]',
            'input[name="billing_email"]'
        ];

        for (let s of selectors) {
            let val = $form.find(s).first().val();
            if (val && val.trim()) return val.trim();
        }
        return '';
    }

    function isValidEmail(email) {
        return email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function containsPOBox(v) {
        return /pob|postoffice/i.test((v || '').replace(/[^a-z0-9]/gi, ''));
    }

    function validateNoPOBox($form) {
        let fields = [
            $form.find('#billing_address_1').val(),
            $form.find('#billing_address_2').val(),
            $form.find('#shipping_address_1').val(),
            $form.find('#shipping_address_2').val()
        ];

        for (let v of fields) {
            if (v && containsPOBox(v)) {
                return 'PO Box not allowed';
            }
        }
        return null;
    }

    /* -----------------------------
     * Popup early (Safari fix)
     * ----------------------------- */

    function openPopupEarly() {
        window.bytenftPopupManager.openBlank(bytenft_params.bytenft_loader);
    }

    /* -----------------------------
     * FORM SUBMIT
     * ----------------------------- */

    function handleFormSubmit(e) {
        e.preventDefault();

        const $form = $(this);

        if (isSubmitting || $form.data('processing')) return;
        isSubmitting = true;
        $form.data('processing', true);

        $button = $form.find('button[type="submit"]');
        originalButtonText = $button.text();
        $button.prop('disabled', true).text('Processing...');

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url,
            data: $form.serialize(),
            dataType: 'json',

            success: function (res) {
                handleResponse(res, $form);
            },

            error: function () {
                handleError($form, "Server error");
            },

            complete: function () {
                isSubmitting = false;
            }
        });
    }

    /* -----------------------------
     * RESPONSE HANDLER
     * ----------------------------- */

    function handleResponse(response, $form) {

        try {

            // ❌ ERROR CASE
            if (!response || response.result !== 'success') {
                bytenftPopupManager.close();
                displayError(response?.error || 'Payment failed', $form);
                return;
            }

            // ✅ SUCCESS CASE ONLY HERE
            const orderId = response.order_id;
            const redirect = response.redirect;

            if (!redirect) {
                displayError("Missing payment link", $form);
                return;
            }

            bytenftPopupManager.setOrderId(orderId);

            if (!window.bytenftPopupManager.getWindow()) {
                window.bytenftPopupManager.openBlank(bytenft_params.bytenft_loader);
            }

            window.bytenftPopupManager.redirect(redirect);

        } catch (e) {
            bytenftPopupManager.close();
            displayError(e.message, $form);
        }
    }

    /* -----------------------------
     * ERROR HANDLING
     * ----------------------------- */

    function handleError($form, msg) {
        bytenftPopupManager.close();
        $form.prepend(`<div class="wc_er">${msg}</div>`);
        resetButton();
    }

    function displayError(msg, $form) {
        bytenftPopupManager.close();
        $form.prepend(`<div class="wc_er wc-block-components-notice-banner is-error">${msg}</div>`);
        resetButton();
    }

    function resetButton() {
        const $form = $('form.checkout, form.wc-block-checkout__form');
        $form.removeData('processing');
        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }
    }

    /* -----------------------------
     * CHECKOUT HOOKS
     * ----------------------------- */

    $(document.body).on('updated_checkout change', 'input[name="payment_method"]', function () {
        bindHandlers();
    });

    function bindHandlers() {

        // CLASSIC
        $('form.checkout').off('submit.bytenft')
            .on('submit.bytenft', function (e) {

                if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method)
                    return;

                const $form = $(this);

                if (!isValidEmail(getBillingEmail($form))) return;
                if (!isValidPhoneNumber(getPhoneNumber($form))) return;
                if (validateNoPOBox($form)) return;

                openPopupEarly();
                handleFormSubmit.call(this, e);
            });

        // BLOCK
        $('form.wc-block-checkout__form button').off('click.bytenft')
            .on('click.bytenft', function (e) {

                if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method)
                    return;

                const $form = $('form.wc-block-checkout__form');

                if (!isValidEmail(getBillingEmail($form))) return;
                if (!isValidPhoneNumber(getPhoneNumber($form))) return;
                if (validateNoPOBox($form)) return;

                openPopupEarly();
                handleFormSubmit.call($form[0], e);
            });
    }

    bindHandlers();
});