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
    
    $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on('click', function () {
	var selectedPaymentMethod = $('input[name="radio-control-wc-payment-method-options"]:checked').val();
	// Prevent WooCommerce default behavior for your custom method
	if (selectedPaymentMethod === bytenft_params.payment_method) {
		return false; // Stop WooCommerce default script
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
        $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button').on("click", function (e) {
		// Check if the custom payment method is selected
		if ($('input[name="radio-control-wc-payment-method-options"]:checked').val() === bytenft_params.payment_method) {
			$error_list = '';
			$error_flag = false;
			$('.wc_er, .wc-block-components-notice-banner').remove();
			$('form.wc-block-checkout__form input').each(function(input){
				
				if (this.hasAttribute('required')){
					const inputVal = $(this).val();
					if(inputVal == ""){
						$error_flag = true;
						const inputLabel = $(this).attr("aria-label");
						$error_list +='<li>'+inputLabel+' field required</li>';
						$(this).focus();
						$(this).blur();
					}
					
					console.log($error_list)
				}
			});
			if($error_flag == true){
				$('form.wc-block-checkout__form').prepend('<div class="wc_er wc-block-components-notice-banner is-error"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path></svg><ul style="margin:0">' + $error_list + '</ul></div>');
				window.scrollTo(0, 0);
				return false;
			}
    			
			handleFormSubmit.call($('form.wc-block-checkout__form'), e);
			return false; // Prevent other handlers
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

	$('#billing_first_name, #billing_last_name, #billing_city').on('input', function () {
		this.value = this.value.replace(/[^A-Za-z\s]/g, ''); 
	});

	document.addEventListener('input', function (e) {
        const target = e.target;
        // Change this selector to your field name
        if (target.id === 'billing-first_name' || target.id === 'billing-last_name' || target.id === 'billing-city') {
        target.value = target.value.replace(/[^a-zA-Z\s]/g, '');
        }
    });

    $('#billing_address_1').on('input', function () {
        this.value = this.value.replace(/[^A-Za-z0-9\s,.\-#]/g, '');
    });
    var isBlock = false;
    function handleFormSubmit(e) {
		e.preventDefault();
		var $form = $(this);
		
		// 1. Clear previous errors
		$('.wc_er, .wc-block-components-notice-banner').remove();

		// 2. Determine if we are using the Block Checkout or Classic Checkout
		var isBlockCheckout = !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
		var selectedPaymentMethod = isBlockCheckout ? 
			$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val() : 
			$form.find('input[name="payment_method"]:checked').val();

		// 3. Exit if our payment method isn't the one selected
		if (selectedPaymentMethod !== bytenft_params.payment_method) {
			isSubmitting = false;
			return true;
		}

		// 4. PRE-OPEN THE POPUP (Crucial for Safari)
		// We open this immediately on the click event to avoid the "about:blank" UX.
		var logoUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
		popupWindow = window.open('', '_blank', 'width=700,height=700');
		
		if (popupWindow) {
			popupWindow.document.write(`
				<html>
				<head><title>Secure Payment</title></head>
				<body style="margin:0; display:flex; flex-direction:column; justify-content:center; align-items:center; height:100vh; font-family:sans-serif; background:#ffffff; text-align:center;">
					<div style="padding:20px;">
						${logoUrl ? `<img src="${logoUrl}" style="max-width:150px; height:auto; margin-bottom:25px;" />` : ''}
						<div style="border:3px solid #f3f3f3; border-top:3px solid #3498db; border-radius:50%; width:40px; height:40px; animation:spin 1s linear infinite; margin:0 auto 20px;"></div>
						<h2 style="font-size:18px; color:#333; margin:0;">Securing Connection...</h2>
						<p style="font-size:14px; color:#777; margin-top:10px;">Please do not refresh or close this window.</p>
					</div>
					<style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
				</body>
				</html>
			`);
		} else {
			alert('Pop-up blocker detected! Please enable pop-ups to complete your payment.');
			return false;
		}

		// 5. Check submission state
		if (isSubmitting) {
			console.warn("Checkout already submitting...");
			return false;
		}
		isSubmitting = true;

		// 6. UI Feedback (Disable Buttons)
		if (isBlockCheckout) {
			$button = $('form.wc-block-checkout__form button.wc-block-components-checkout-place-order-button');
		} else {
			$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		}
		
		originalButtonText = $button.text();
		$button.prop('disabled', true).text('Processing...');
		$('.bytenft-loader-background, .bytenft-loader').show();

		// 7. Execute AJAX based on checkout type
		if (isBlockCheckout) {
			isBlock = true;
			$.ajax({
				method: 'POST',
				url: bytenft_params.ajax_url,
				data: {
					action: 'bytenft_block_gateway_process',
					nonce: bytenft_params.bytenft_nonce
				},
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function (err) {
					handleError($form, "Server connection error.");
				},
				complete: function () {
					isSubmitting = false;
					if (window.wp?.data) {
						wp.data.dispatch('wc/store/cart').recalculateTotals();
					}
				}
			});
		} else {
			isBlock = false;
			$.ajax({
				type: 'POST',
				url: wc_checkout_params.checkout_url,
				data: $form.serialize(),
				dataType: 'json',
				success: function (response) {
					handleResponse(response, $form);
				},
				error: function () {
					handleError($form, "Error processing checkout.");
				},
				complete: function () {
					isSubmitting = false;
				}
			});
		}

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

		// Loading HTML
		var loadingHTML = `
			<!DOCTYPE html>
			<html>
			<head>
				<meta charset="UTF-8">
				<style>
					body {
						margin: 0;
						display: flex;
						justify-content: center;
						align-items: center;
						height: 100vh;
						background: #f5f5f5;
						font-family: Arial, sans-serif;
					}
					.loader-container {
						text-align: center;
					}
					.spinner {
						border: 4px solid #f3f3f3;
						border-top: 4px solid #3498db;
						border-radius: 50%;
						width: 50px;
						height: 50px;
						animation: spin 1s linear infinite;
						margin: 0 auto 20px;
					}
					@keyframes spin {
						0% { transform: rotate(0deg); }
						100% { transform: rotate(360deg); }
					}
					.message {
						color: #333;
						font-size: 16px;
						margin-bottom: 10px;
					}
					.submessage {
						color: #666;
						font-size: 14px;
					}
				</style>
			</head>
			<body>
				<div class="loader-container">
					<div class="spinner"></div>
					<div class="message">Connecting to secure payment...</div>
					<div class="submessage">Please wait...</div>
				</div>
				<script>
					setTimeout(function() {
						window.location.href = '${sanitizedPaymentLink}';
					}, 800);
				</script>
			</body>
			</html>
		`;

		// 🔥 ALWAYS reuse popup opened in handleFormSubmit
		if (popupWindow && !popupWindow.closed) {

			try {
				popupWindow.document.open();
				popupWindow.document.write(loadingHTML);
				popupWindow.document.close();
			} catch (e) {
				popupWindow.location.href = sanitizedPaymentLink;
			}

		} else {

			// Fallback if popup somehow not available
			popupWindow = window.open('', '_blank',
				'width=' + width + ',height=' + height +
				',scrollbars=yes,resizable=yes,top=' + top + ',left=' + left
			);

			if (popupWindow) {
				try {
					popupWindow.document.write(loadingHTML);
					popupWindow.document.close();
				} catch (e) {
					popupWindow.location.href = sanitizedPaymentLink;
				}
			} else {
				// Popup blocked
				window.location.href = sanitizedPaymentLink;
				resetButton();
				return;
			}
		}

		// 🚀 Start polling for popup close
		popupInterval = setInterval(function () {

			if (!popupWindow || popupWindow.closed) {

				clearInterval(popupInterval);
				clearInterval(paymentStatusInterval);

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

						$(document.body).trigger('update_checkout');
						$(".wc-block-components-notice-banner").remove();

						if (response.success && response.data?.redirect_url) {
							window.location.replace(response.data.redirect_url);
						} else if (response.data?.notices) {
							$(".wc-block-checkout__form").prepend(
								'<div class="wc-block-components-notice-banner is-error">' +
								response.data.notices +
								'</div>'
							);
							window.scrollTo(0, 0);
						}
					},
					error: function (xhr, status, error) {
						console.error("AJAX Error:", error);
					},
					complete: function () {
						resetButton();
					}
				});
			}

		}, 500);
	}

    function handleResponse(response, $form) {
		$('.bytenft-loader-background, .bytenft-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				// openPaymentLink will now inject the real URL into our existing popupWindow
				openPaymentLink(response.redirect); 
			} else {
				// CRITICAL: Close the empty popup because there's a validation error
				if (popupWindow) popupWindow.close();

				var errorMessage = response?.error || response?.messages || 'Payment initialization failed.';
				displayError(errorMessage, $form);
			}
		} catch (err) {
			if (popupWindow) popupWindow.close();
			displayError(err, $form);
		}
	}

    function handleError($form, err) {
		if (popupWindow) popupWindow.close(); // Close on AJAX failure
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">' + err + '</div>');
		$('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
		resetButton();
	}

    function displayError(err, $form) {
		if (popupWindow) popupWindow.close(); // Close on Logic failure
		
		var isBlock = !!$form.find('input[name="radio-control-wc-payment-method-options"]:checked').val();
		$('.wc_er, .wc-block-components-notice-banner').remove();

		var errorHtml = isBlock ? 
			'<div class="wc_er wc-block-components-notice-banner is-error">... ' + err + '</div>' : 
			'<div class="wc_er">' + err + '</div>';

		$form.prepend(errorHtml);
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