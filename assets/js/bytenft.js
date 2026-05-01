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

    /**
     * Reads phone number from either classic or block checkout form.
     */
    function getPhoneNumber($form) {
        var selectors = [
            'input[name="billing_phone"]',      // classic checkout
            'input[name="shipping_phone"]',     // shipping phone
            'input[autocomplete="tel"]',        // block checkout WC 8+
            'input[type="tel"]',                // universal
        ];
        for (var i = 0; i < selectors.length; i++) {
            var val = $form.find(selectors[i]).first().val();
            if (val && val.trim() !== '') return val.trim();
        }
        return '';
    }

    function isValidPhoneNumber(phone) {
        if (!phone || phone.trim() === '') return true;
        var cleaned        = phone.replace(/[\s\-().]/g, '');
        var usPattern      = /^(\+1|1)?\d{10}$/;
        var euPattern      = /^(\+|00)[1-9]\d{6,14}$/;
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
            popupWindow = window.open('about:blank', '_blank', 'width=700,height=700');
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
        // --- CLASSIC CHECKOUT ---
        $('form.checkout').off('click.bytenft-classic').on('click.bytenft-classic', 'button[name="woocommerce_checkout_place_order"]', function (e) {
            if ($('input[name="payment_method"]:checked').val() !== bytenft_params.payment_method) return;
            
            var $form = $(this).closest('form');
            
            // PERFORM ALL CHECKS FIRST
            var email = getBillingEmail($form);
            var phone = getPhoneNumber($form);
            var poBoxError = validateNoPOBox($form);

            // If ANY validation fails, stop here and do NOT open popup
            if (!isValidEmail(email)) return; 
            if (phone !== '' && !isValidPhoneNumber(phone)) return;
            if (poBoxError) return;

            // Only if validation passed, open the window
            openPopupEarly();
        });

        // --- BLOCK CHECKOUT ---
        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').off("click.bytenft").on("click.bytenft", function (e) {
            if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() !== bytenft_params.payment_method) return;

            var $form = $('form.wc-block-checkout__form');
            
            // Strict check for required fields in Blocks
            var missingFields = false;
            $form.find('input[required]').each(function() {
                if (!$(this).val()) { missingFields = true; }
            });

            if (missingFields || !isValidEmail(getBillingEmail($form)) || validateNoPOBox($form)) {
                // Let the natural validation show the errors, don't open popup
                return;
            }

            // Validation passed locally
            openPopupEarly();
            handleFormSubmit.call($form, e);
            return false;
        });
    }

    function handleFormSubmit(e) {
        if (e) e.preventDefault();
        var $form = $(this);
        $('.wc_er, .wc-block-components-notice-banner').remove();

        setTimeout(function () {
            var isBlockCheckout = $form.hasClass('wc-block-checkout__form') || !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
            if (isSubmitting || $form.data('bytenft-processing')) return false;

            isSubmitting = true;
            $form.data('bytenft-processing', true);

            $button = isBlockCheckout ? $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button') : $form.find('button[name="woocommerce_checkout_place_order"]');
            originalButtonText = $button.text();
            $button.prop('disabled', true).text('Processing...');

            var ajaxUrl  = isBlockCheckout ? bytenft_params.ajax_url : (wc_checkout_params ? wc_checkout_params.checkout_url : window.location.href);
            var ajaxData = isBlockCheckout ? { action: 'bytenft_block_gateway_process', nonce: bytenft_params.bytenft_nonce } : $form.serialize();

            $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: ajaxData,
                dataType: isBlockCheckout ? 'json' : 'json',
                success: function (response) { handleResponse(response, $form); },
                error: function () { handleError($form, "Server connection error."); },
                complete: function () { isSubmitting = false; }
            });
        }, 10);
    }

    function handleResponse(response, $form) {
        if (response.result === 'success' && response.redirect) {
            orderId = response.order_id;
            if (popupWindow && !popupWindow.closed) {
                // Move to center and resize to normal
                popupWindow.resizeTo(700, 700);
                popupWindow.moveTo(
                    (screen.width - 700) / 2,
                    (screen.height - 700) / 2
                );
                popupWindow.location.href = response.redirect;
            } else {
                window.location.href = response.redirect;
            }
            startPopupInterval();
        } else {
            // API FAILED: Close it before user sees it
            if (popupWindow) {
                popupWindow.close();
                popupWindow = null;
            }
            var err = response.error || response.messages || 'Payment failed.';
            displayError(err, $form);
        }
    }

    function startPopupInterval() {
        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                popupWindow = null;
                $.post(bytenft_params.ajax_url, {
                    action: 'bytenft_popup_closed_event',
                    order_id: orderId,
                    security: bytenft_params.bytenft_nonce
                }, function (res) {
                    if (res.success && res.data?.redirect_url) window.location.replace(res.data.redirect_url);
                    resetButton();
                });
            }
        }, 1000);
    }

    function handleError($form, err) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        displayError(err, $form);
    }

    function displayError(err, $form) {
        if (popupWindow) { popupWindow.close(); popupWindow = null; }
        $('.wc_er, .wc-block-components-notice-banner').remove();
        var msg = typeof err === 'string' ? err : 'An error occurred.';
        var $error = $('<div class="wc_er wc-block-components-notice-banner is-error"></div>').html(msg);
        $form.prepend($error);
        $('html, body').animate({ scrollTop: $error.offset().top - 200 }, 500);
        resetButton();
    }

    function resetButton() {
        isSubmitting = false;
        $('form.checkout, form.wc-block-checkout__form').removeData('bytenft-processing');
        if ($button) $button.prop('disabled', false).text(originalButtonText);
    }

    $(document.body).on("updated_checkout change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    markCheckoutFormIfNeeded();
    bindCheckoutHandler();

    // Sanitization
    $('#billing_first_name, #billing_last_name, #billing_city').on('input', function () {
        this.value = this.value.replace(/[^A-Za-z\s]/g, '');
    });
    $('#billing_address_1').on('input', function () {
        this.value = this.value.replace(/[^A-Za-z0-9\s,.\-#]/g, '');
    });
});