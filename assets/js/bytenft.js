jQuery(function ($) {
    var isSubmitting = false;
    var popupInterval;
    var paymentStatusInterval;
    var orderId;
    var $button;
    var originalButtonText;
    var isPollingActive = false;
    let popupWindow = null;

    var loaderUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
    $('body').append(
        '<div class="bytenft-loader-background"></div>' +
        '<div class="bytenft-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
    );

    // Prevent default WooCommerce form submission for our method
    $('form.checkout').on('checkout_place_order', function () {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod === bytenft_params.payment_method) {
            return false;
        }
    });

    // Assign or remove custom form ID based on selected method
    function markCheckoutFormIfNeeded() {
        var $form = $("form.checkout");
        var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
        var expectedId = bytenft_params.payment_method + '-checkout-form';

        if (selectedMethod === bytenft_params.payment_method) {
            $form.attr('id', expectedId);
        } else {
            // Only remove the ID if it matches ours
            if ($form.attr('id') === expectedId) {
                $form.removeAttr('id');
            }
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

    // Handle WooCommerce hooks
    $(document.body).on("updated_checkout", function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    $(document.body).on("change", 'input[name="payment_method"]', function () {
        markCheckoutFormIfNeeded();
        bindCheckoutHandler();
    });

    // Initial binding
    markCheckoutFormIfNeeded();
    bindCheckoutHandler();

    function handleFormSubmit(e) {
        e.preventDefault();
        var $form = $(this);

        // ⚡️ OPEN POP-UP HERE - THIS IS THE CRITICAL CHANGE FOR SAFARI
        if (isIOS()) {
            popupWindow = window.open('about:blank', '_blank');
            if (!popupWindow) {
                // Handle pop-up blocker case
                alert('Pop-up blocker detected! Please disable it for this site and try again.');
                return false;
            }
        }

        if (isSubmitting) {
            console.warn("Checkout already submitting...");
            return false;
        }

        isSubmitting = true;

        var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod !== bytenft_params.payment_method) {
            isSubmitting = false;
            return true;
        }

        $button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
        originalButtonText = $button.text();
        $button.prop('disabled', true).text('Processing...');

        $('.bytenft-loader-background, .bytenft-loader').show();

        var data = $form.serialize();

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url,
            data: data,
            dataType: 'json',
            success: function (response) {
                handleResponse(response, $form);
            },
            error: function () {
                handleError($form);
            },
            complete: function () {
                isSubmitting = false;
            },
        });

        return false;
    }

    function isIOS() {
        return /iP(ad|hone|od)/.test(navigator.userAgent);
    }

    // The preparePopup function is no longer needed in its current form
    // as we now open the pop-up directly in handleFormSubmit.

    function openPaymentLink(paymentLink) {
        var sanitizedPaymentLink = paymentLink;
        var width = 700, height = 700;
        var left = window.innerWidth / 2 - width / 2;
        var top = window.innerHeight / 2 - height / 2;

         if (isIOS()) {
            // ⚡️ RE-USE THE PREVIOUSLY OPENED POP-UP
            if (popupWindow && !popupWindow.closed) {
                popupWindow.location.href = sanitizedPaymentLink;
            } else {
                // Fallback for unexpected cases
                popupWindow = window.open(sanitizedPaymentLink, '_blank');
            }
        } else {
            popupWindow = window.open(
                sanitizedPaymentLink,
                'paymentPopup',
                'width=' + width + ',height=' + height +
                ',scrollbars=yes,resizable=yes,top=' + top + ',left=' + left
            );
        }

        if (!popupWindow || popupWindow.closed || typeof popupWindow.closed === 'undefined') {
            // Fallback if blocked
            window.location.href = sanitizedPaymentLink;
            resetButton();
            return;
        }else{

		//Polling for payment status (common for all)
		popupInterval = setInterval(function () {
			if (popupWindow.closed) {
				clearInterval(popupInterval);
				clearInterval(paymentStatusInterval);
				// isPollingActive = false; // Reset polling active flag when popup closes

				// API call when popup closes
				$.ajax({
					type: 'POST',
				        url: bytenft_params.ajax_url,
				        data: {
				            action: 'bytenft_popup_closed_event',
				            order_id: orderId,
				            security: bytenft_params.bytenft_nonce,
				        },
				        dataType: 'json',
					cache: false,
					processData: true,
					success: function (response) {
					    if (response.success === true) {
					        clearInterval(paymentStatusInterval);
					        clearInterval(popupInterval);

					        // Log for debugging
					        console.log('Popup closed response:', response);

							$(document.body).trigger('update_checkout');
					        $(".wc-block-components-notice-banner").remove();

					        // Redirect if redirect_url exists (for any status)
					        if (response.data && response.data.redirect_url) {
					            // Use replace() to ensure redirect works even in popup-close timing
					            window.location.replace(response.data.redirect_url);
					        }else{
								if(response.data.notices){
									$(".wc-block-checkout__form").prepend('<div class="wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg>'+response.data.notices+'<div>');
								}
								 window.scrollTo(0, 0);
							}
					    }else{
					    	$(document.body).trigger('update_checkout');
					        $(".wc-block-components-notice-banner").remove();
					    }

					    // isPollingActive = false;
					},
					error: function (xhr, status, error) {
						console.error("AJAX Error: ", error);
					},
					complete: function () {
						resetButton();
					}
				});
			}
		}, 500);
        }

        // Polling for payment status (common for all)
        if (!isPollingActive) {
            isPollingActive = true;
            paymentStatusInterval = setInterval(function () {
                $.ajax({
                    type: 'POST',
                    url: bytenft_params.ajax_url,
                    data: {
                        action: 'bytenft_check_payment_status',
                        order_id: orderId,
                        security: bytenft_params.bytenft_nonce,
                    },
                    dataType: 'json',
                    success: function (statusResponse) {
                        if (['success', 'failed', 'cancelled'].includes(statusResponse.data.status)) {
                            clearInterval(paymentStatusInterval);
                            clearInterval(popupInterval);
                            isPollingActive = false;

                            try {
                                if (popupWindow && !popupWindow.closed) {
                                    popupWindow.close(); // won’t close iOS tab, but safe
                                }
                            } catch (e) {
                                console.warn('Unable to close popup window:', e);
                            }

                            if (statusResponse.data.redirect_url) {
                                window.location.href = statusResponse.data.redirect_url;
                            }
                        }
                    }
                });
            }, 5000);
        }

        // Popup/tab close event (try for all, works on desktop, partial on iOS)
        popupInterval = setInterval(function () {
            try {
                if (popupWindow.closed) {
                    clearInterval(popupInterval);
                    clearInterval(paymentStatusInterval);
                    isPollingActive = false;

                    $.ajax({
                        type: 'POST',
                        url: bytenft_params.ajax_url,
                        data: {
                            action: 'bytenft_popup_closed_event',
                            order_id: orderId,
                            security: bytenft_params.bytenft_nonce,
                        },
                        dataType: 'json',
                        success: function (response) {
                            if (response.success && response.data.redirect_url) {
                                window.location.href = response.data.redirect_url;
                            }
                        },
                        complete: function () {
                            resetButton();
                        }
                    });
                }
            } catch (e) {
                console.warn('Popup check failed:', e);
            }
        }, 500);
    }

    function handleResponse(response, $form) {
        $('.bytenft-loader-background, .bytenft-loader').hide();
        $('.wc_er').remove();

        try {
            if (response.result === 'success') {
                orderId = response.order_id;
                var paymentLink = response.payment_link;
                // openPaymentLink now handles loading the URL into the existing pop-up
                openPaymentLink(paymentLink); 
                $form.removeAttr('data-result').removeAttr('data-redirect-url');
            } else {
                // If there's an error, close the pre-opened pop-up
                if (isIOS() && popupWindow && !popupWindow.closed) {
                    popupWindow.close();
                }
                throw response.messages || 'An error occurred during checkout.';
            }
        } catch (err) {
            displayError(err, $form);
        }
    }

    function handleError($form) {
        $('.wc_er').remove();
        $form.prepend('<div class="wc_er">An error occurred during checkout. Please try again.</div>');
        $('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
        resetButton();
    }

    function displayError(err, $form) {
        $('.wc_er').remove();
        $form.prepend('<div class="wc_er">' + err + '</div>');
        $('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
        resetButton();
    }

    function resetButton() {
        isSubmitting = false;
        if ($button) {
            $button.prop('disabled', false).text(originalButtonText);
        }
        $('.bytenft-loader-background, .bytenft-loader').hide();
    }
});