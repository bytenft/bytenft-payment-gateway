jQuery(function ($) {
    var isSubmitting = false;
    var popupInterval;
    var paymentStatusInterval;
    var orderId;
    var $button;
    var originalButtonText;
    let popupWindow = null;

    // Prevent default WooCommerce form submission for our method
    $('form.checkout').on('checkout_place_order', function () {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod === bytenft_params.payment_method) return false;
    });

    $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on('click', function () {
        var selectedPaymentMethod = $('input[name="radio-control-wc-payment-method-options"]:checked').val();
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
        var formId = '#' + bytenft_params.payment_method + '-checkout-form';
        $(formId).off("submit.bytenft").on("submit.bytenft", function (e) {
            if ($(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
                // Remove all known error messages for classic checkout (update errorSelectors if you use a custom template)
                $(this).closest('.woocommerce').find(errorSelectors.join(',')).remove();
                handleFormSubmit.call(this, e);
                return false;
            }
        });

        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on("click", function (e) {
            if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() === bytenft_params.payment_method) {
                var errorList = '';
                var errorFlag = false;
                $('.wc_er, .wc-block-components-notice-banner').remove();
                $('form.wc-block-checkout__form input').each(function() {
                    if (this.hasAttribute('required') && ($(this).val() === "" && !$(this).is(':checked'))) {
                        const inputLabel = $(this).attr("aria-label");
                        const spanLabel = $(this).closest("label").find("span").html();
                        if(inputLabel){
                            errorFlag = true;
                            errorList += '<li>' + inputLabel + ' field is required</li>';
                        }
                        else if(spanLabel){
                            errorFlag = true;
                            errorList += '<li>Please accept <b>"' + spanLabel + '"</b></li>';
                        }
                        $(this).focus().blur();
                    }
                });
                if(errorFlag) {
                    $('form.wc-block-checkout__form').prepend(
                        '<div class="wc_er wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><ul style="margin:0">' + errorList + '</ul></div>'
                    );
                    window.scrollTo(0, 0);
                    return false;
                }
                handleFormSubmit.call($('form.wc-block-checkout__form'), e);
                return false;
            }
        });
    }

    $(document.body).on("updated_checkout change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    // Initial binding
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

        // Phone validation: allow empty, but if not empty, must be valid (US/EU/international)
        var phone = $form.find('input[name="billing_phone"]').val();
        if (!isValidPhoneNumber(phone)) {
            // Remove any previous WooCommerce error banners
            $form.find('.woocommerce-error, .wc_er, .wc-block-components-notice-banner, ul[role="alert"]').remove();
            // Add error in WooCommerce style
            var $errorUl = $('<ul class="woocommerce-error" role="alert" style="list-style:none;margin:0 0 32px 0;"></ul>');
            $errorUl.append('<li>Please enter a valid phone number (US, Europe, or international format) or leave it blank.</li>');
            $form.prepend($errorUl);
            $('html, body').animate({ scrollTop: $form.find('.woocommerce-error').offset().top - 300 }, 500);
            return false;
        }

        setTimeout(function() {
            var isBlockCheckout = !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
            var selectedPaymentMethod = isBlockCheckout ? 
                $form.find('input[name="radio-control-wc-payment-method-options"]:checked').val() : 
                $form.find('input[name="payment_method"]:checked').val();

            if (selectedPaymentMethod !== bytenft_params.payment_method) {
                isSubmitting = false;
                return true;
            }

            if (isSubmitting || $form.data('bytenft-processing')) {
                return false;
            }

            isSubmitting = true;
            $form.data('bytenft-processing', true);

            $button = isBlockCheckout ? 
                $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button') :
                $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
            originalButtonText = $button.text();
            $button.prop('disabled', true).text('Processing...');

            var ajaxUrl = isBlockCheckout ? bytenft_params.ajax_url : wc_checkout_params.checkout_url;
            var ajaxData = isBlockCheckout ? { action: 'bytenft_block_gateway_process', nonce: bytenft_params.bytenft_nonce } : $form.serialize();

            $.ajax({
                type: 'POST',
                url: ajaxUrl,
                data: ajaxData,
                dataType: isBlockCheckout ? undefined : 'json',
                success: function (response) {
                    // Only open popup if result is success
                    if (response && response.result === 'success') {
                        var logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
                        popupWindow = window.open('', '_blank', 'width=700,height=700');
                        if (popupWindow) {
                            popupWindow.document.write(`
                                <html>
                                <head><title>Secure Payment</title></head>
                                <body style="margin:0; display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; font-family:sans-serif; background:#ffffff; text-align:center;">
                                    <div style="padding:20px;">
                                        ${logoUrl ? `<img src="${logoUrl}" style="max-width:150px; height:auto; margin-bottom:25px;" />` : ''}
                                        <h2 style="font-size:18px; color:#333; margin:0;">Connecting to secure payment...</h2>
                                        <p style="font-size:14px; color:#777; margin-top:10px;">Please do not refresh or close this window.</p>
                                    </div>
                                    <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
                                </body>
                                </html>
                            `);
                        }
                    }
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
            } else if (!popupWindow || popupWindow.closed) {
                // ✅ Only fallback if popup was truly blocked (never opened), not just closed by user
                if (!popupWindow) {
                    window.location.href = paymentLink;
                }
                // ✅ If user closed it manually, do nothing — let popupInterval handle it
            }
        }, 300); // small delay to ensure popup is fully initialized

        // Polling for popup close
        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                clearInterval(paymentStatusInterval);
                popupWindow = null;

                $.post(bytenft_params.ajax_url, {
                    action: 'bytenft_popup_closed_event',
                    order_id: orderId,
                    security: bytenft_params.bytenft_nonce
                }, function(response) {
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
                if (popupWindow) popupWindow.close();
                displayError(response?.error || response?.notices || response?.messages || 'We couldn’t start your payment. If the problem persists, please contact support.', $form);
            }
        } catch (err) {
            if (popupWindow) popupWindow.close();
            displayError(err, $form);
        }
    }

    function handleError($form, err) {
        if (popupWindow) popupWindow.close();
        $form.prepend('<div class="wc_er">' + err + '</div>');
        $('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
        resetButton();
    }

    function displayError(err, $form) {
        if (popupWindow) popupWindow.close();
        $('.wc_er, .wc-block-components-notice-banner').remove();
        var errorMessage = (typeof err === 'string' ? err : err?.message || 'Payment failed').toString().trim();
        var canRenderHtml = /<[^>]+>/.test(errorMessage) && !/<\s*(script|style|iframe|object|embed)\b|on[a-z]+\s*=|javascript:/i.test(errorMessage);

        var $error = canRenderHtml
            ? $(errorMessage)
            : $('<div>', { class: 'wc_er wc-block-components-notice-banner is-error', text: errorMessage || 'Payment failed' });

        if (!$error.length) {
            $error = $('<div>', { class: 'wc_er wc-block-components-notice-banner is-error', text: errorMessage || 'Payment failed' });
        }

        // Remove all WooCommerce and custom error messages in the closest .woocommerce container, including any <ul> with role="alert"
        $(this).closest('.woocommerce').find(errorSelectors.join(',')).remove();

        $form.prepend($error.first());

        if ($error.first().length) {
            $('html, body').animate({ scrollTop: $error.first().offset().top - 300 }, 500);
        }
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

    // Helper: Validate phone number (US/EU/international)
    function isValidPhoneNumber(phone) {
        if (!phone || phone.trim() === '') return true; // Allow blank
        // Remove spaces, dashes, parentheses
        var cleaned = phone.replace(/[\s\-().]/g, '');
        // US: +1XXXXXXXXXX or 1XXXXXXXXXX or XXXXXXXXXX
        var usPattern = /^(\+1|1)?\d{10}$/;
        // Europe: +[country][number] (7-15 digits, starts with + or 00)
        var euPattern = /^(\+|00)[1-9]\d{6,14}$/;
        // General: 7-20 digits
        var generalPattern = /^\+?\d{7,20}$/;
        return usPattern.test(cleaned) || euPattern.test(cleaned) || generalPattern.test(cleaned);
    }

    // List of selectors for error messages. Update this array if you use a custom checkout template with different error markup.
    var errorSelectors = [
        '.woocommerce-error',
        '.wc_er',
        '.wc-block-components-notice-banner',
        'ul[role="alert"]'
        // Add custom error selectors here if needed
    ];
});