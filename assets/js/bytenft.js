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

    $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').off('click');

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
                handleFormSubmit.call(this, e);
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
    $('#billing_first_name, #billing_last_name, #billing_city').off('input');
    $('#billing_address_1').off('input');

    function handleFormSubmit(e) {
        e.preventDefault();
        var $form = $(this);
        $('.wc_er').remove();

        var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();

        if (selectedPaymentMethod !== bytenft_params.payment_method) {
            isSubmitting = false;
            return true;
        }

        // Prevent multiple submissions (strong lock)
        if (isSubmitting || $form.data('bytenft-processing')) {
            return false;
        }

        isSubmitting = true;
        $form.data('bytenft-processing', true);

        // Pre-open popup with loader
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

        // Disable button
        $button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
        originalButtonText = $button.text();
        $button.prop('disabled', true).text('Processing...');

        // Execute AJAX
        var ajaxUrl = wc_checkout_params.checkout_url;
        var ajaxData = $form.serialize();

        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: ajaxData,
            dataType: 'json',
            success: function (response) { handleResponse(response, $form); },
            error: function () { handleError($form, "Server connection error."); },
            complete: function () { isSubmitting = false; }
        });

        return false;
    }

    function openPaymentLink(paymentLink) {
        if (popupWindow && !popupWindow.closed) {
            popupWindow.location.href = paymentLink;
        } else {
            // Fallback to same window if popup blocked
            window.location.href = paymentLink;
        }

        // Polling for popup close
        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                clearInterval(paymentStatusInterval);

                $.post(bytenft_params.ajax_url, {
                    action: 'bytenft_popup_closed_event',
                    order_id: orderId,
                    security: bytenft_params.bytenft_nonce
                }, function(response) {
                    if (response.success && response.data?.redirect_url) {
                        window.location.replace(response.data.redirect_url);
                    } else if (response.data?.notices) {
                        var $targetForm = $('form.checkout');
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
});