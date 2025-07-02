jQuery(function ($) {
	var isSubmitting = false; // Flag to track form submission
	var popupInterval; // Interval ID for checking popup status
	var paymentStatusInterval; // Interval ID for checking payment status
	var orderId; // To store the order ID
	var $button; // To store reference to the submit button
	var originalButtonText; // To store original button text
	var isPollingActive = false; // Flag to ensure only one polling interval runs
	let isHandlerBound = false;

	// Sanitize loader URL and append loader image to the body
	var loaderUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
	$('body').append(
	  '<div class="bytenft-loader-background"></div>' +
	  '<div class="bytenft-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	$('body').append(`
		<div id="alchemy-popup">
			<div class="alchemy-popup-div">
				<p>🎉 Your order has been created!</p>
				<p>💳 We've sent a payment link to your email/SMS.Please complete your payment using that link.</p>
				<p>✅ Once payment is complete, this page will update automatically.</p>
				<p>🔒 Don’t close this tab if you want to track the payment.</p>
				<button id="alchemy-popup-close-btn">OK</button>
			</div>
		</div>
	`);

	// Disable default WooCommerce checkout for your custom payment method
    $('form.checkout').on('checkout_place_order', function () {
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Prevent WooCommerce default behavior for your custom method
        if (selectedPaymentMethod === bytenft_params.payment_method) {
            return false; // Stop WooCommerce default script
        }
    });
	
	// Function to bind the form submit handler
	function bindCheckoutHandler() {
		if (isHandlerBound) return;
		isHandlerBound = true;

	  // Unbind the previous handler before rebinding
	  $("form.checkout").off("submit.bytenft").on("submit.bytenft", function (e) {
		// Check if the custom payment method is selected
		if ($(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
            handleFormSubmit.call(this, e);
            return false; // Prevent other handlers
        }
	  });
	}
  
	// Rebind after checkout updates
	$(document.body).on("updated_checkout", function () {
		isHandlerBound = false; // Allow rebinding only once on the next update
	  bindCheckoutHandler();
	});
  
	// Initial binding of the form submit handler
	bindCheckoutHandler();
  
	// Function to handle form submission
	function handleFormSubmit(e) {

	e.preventDefault(); // Prevent the form from submitting if already in progress

	  var $form = $(this);
  
	  // If a submission is already in progress, prevent further submissions
	  if (isSubmitting) {
		return false;
	  }
  
	  // Set the flag to true to prevent further submissions
	  isSubmitting = true;
  
	  var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
  
	  if (selectedPaymentMethod !== bytenft_params.payment_method) {
		isSubmitting = false; // Reset the flag if not using the custom payment method
		return true; // Allow default WooCommerce behavior
	  }
  
	  // Disable the submit button immediately to prevent further clicks
	  $button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
	  originalButtonText = $button.text();
	  $button.prop('disabled', true).text('Processing...');
  
	  // Show loader
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
			isSubmitting = false; // Always reset isSubmitting to false in case of success or error
		  },
		});

	  e.preventDefault(); // Prevent default form submission
	  return false;
	}
  
	function openPaymentLink(paymentLink) {
	  var sanitizedPaymentLink = encodeURI(paymentLink);
	  var width = 700;
	  var height = 700;
	  var left = window.innerWidth / 2 - width / 2;
	  var top = window.innerHeight / 2 - height / 2;
	  var popupWindow = window.open(
		sanitizedPaymentLink,
		'paymentPopup',
		'width=' + width + ',height=' + height + ',scrollbars=yes,top=' + top + ',left=' + left
	  );
  
	  if (!popupWindow || popupWindow.closed || typeof popupWindow.closed === 'undefined') {
		// Redirect to the payment link if popup was blocked
		window.location.href = sanitizedPaymentLink;
		resetButton();
	  } else {
		popupInterval = setInterval(function () {
		  if (popupWindow.closed) {
			clearInterval(popupInterval);
			clearInterval(paymentStatusInterval);
			isPollingActive = false; // Reset polling active flag when popup closes
			
			// API call when popup closes
			$.ajax({
				type: 'POST',
				url: bytenft_params.ajax_url, // Ensure this is localized correctly
				data: {
					action: 'popup_closed_event',
					order_id: orderId,
					security: bytenft_params.bytenft_nonce, // Ensure this is valid
				},
				dataType: 'json',
				cache: false,
				processData: true,
				success: function (response) {
					if (response.success === true) {
						clearInterval(paymentStatusInterval);
						clearInterval(popupInterval);
						if (response.data && response.data.redirect_url) {
								window.location.href = response.data.redirect_url;
						}
					}
					  isPollingActive = false; // Reset polling active flag after completion
				},
				error: function (xhr, status, error) {
					console.error("AJAX Error: ", error);
				},
				complete:function(){
					resetButton();
				}
			});
		  }
		}, 500);
  
		// Start polling only if it's not already active
		if (!isPollingActive) {
		  isPollingActive = true;
		  paymentStatusInterval = setInterval(function () {
			$.ajax({
			  type: 'POST',
			  url: bytenft_params.ajax_url,
			  data: {
				action: 'check_payment_status',
				order_id: orderId,
				security: bytenft_params.bytenft_nonce,
			  },
			  dataType: 'json',
			  cache: false,
			  processData: true,
			  success: function (statusResponse) {
				if (statusResponse.data.status === 'success') {
				  clearInterval(paymentStatusInterval);
				  clearInterval(popupInterval);
				  if (statusResponse.data && statusResponse.data.redirect_url) {
						window.location.href = statusResponse.data.redirect_url;
				  }
				} else if (statusResponse.data.status === 'failed') {
				  clearInterval(paymentStatusInterval);
				  clearInterval(popupInterval);
				  if (statusResponse.data && statusResponse.data.redirect_url) {
						window.location.href = statusResponse.data.redirect_url;
				   }
				}
				isPollingActive = false; // Reset polling active flag after completion
			  },
			});
		  }, 5000);
		}
	  }
	}
  
	function handleResponse(response, $form) {
		$('.bytenft-loader-background, .bytenft-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				if (response.alchemyPopup) {
					orderId = response.order_id;

					// Show custom popup instead of alert
					$('#alchemy-popup').fadeIn();
					if (!isPollingActive) {
						isPollingActive = true;
						paymentStatusInterval = setInterval(function () {
							checkPaymentStatus(orderId).then(function(result) {
								console.log('Status:', result.status);
								console.log('Redirect URL:', result.redirect_url);
								
								if (result.status === 'pending') {
									$('.alchemy-popup-div').html(`
										<p>⌛ Your order is pending.</p>
										<p>💳 We’re waiting for your payment to be completed.</p>
										<p>🔁 This page will refresh automatically once it's done.</p>
										<p>🕒 Please do not close this window.</p>
										<button id="alchemy-popup-close-btn">OK</button>
									`);
								} else {
									window.location.href = response.data.redirect_url;
								}
								isPollingActive = false; // Reset polling active flag after completion
							}).catch(function(error) {
								console.error('Status check failed:', error);
							});
		  				}, 5000);
					}

					// Bind close button once
					// $('#alchemy-popup-close-btn').off('click').on('click', function () {
					// 	$('#alchemy-popup').fadeOut();

					// 	// Trigger bytenft_handle_api_request via AJAX
					// 	$.ajax({
					// 		type: 'POST',
					// 		url: bytenft_params.ajax_url,
					// 		data: {
					// 			action: 'popup_closed_event',
					// 			order_status: 'completed',
					// 			order_id: orderId,
					// 			security: bytenft_params.bytenft_nonce,
					// 		},
					// 		dataType: 'json',
					// 		success: function (response) {
					// 			if (response.success === true && response.data && response.data.redirect_url) {
					// 				window.location.href = response.data.redirect_url;
					// 			}
					// 		},
					// 		error: function (xhr, status, error) {
					// 			console.error("AJAX Error: ", error);
					// 		},
					// 		complete: function () {
					// 			resetButton();
					// 		}
					// 	});
					// });

					return; // Don't open payment link
				}

				orderId = response.order_id;
				var paymentLink = response.payment_link + (response.payment_link.includes('?') ? '&' : '?') + 'cb=' + Date.now();
				openPaymentLink(paymentLink);
				$form.removeAttr('data-result');
				$form.removeAttr('data-redirect-url');
			} else {
				throw response.messages || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
		}
	}
  
	function handleError($form) {
	  $('.wc_er').remove();
	  $form.prepend('<div class="wc_er">An error occurred during checkout. Please try again.</div>');
	  $('html, body').animate(
		{
		  scrollTop: $('.wc_er').offset().top - 300,
		},
		500
	  );
	  resetButton();
	}
  
	function displayError(err, $form) {
	  $('.wc_er').remove();
	  $form.prepend('<div class="wc_er">' + err + '</div>');
	  $('html, body').animate(
		{
		  scrollTop: $('.wc_er').offset().top - 300,
		},
		500
	  );
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
  
function checkPaymentStatus(orderId) {
	return new Promise(function (resolve, reject) {
		$.ajax({
			type: 'POST',
			url: bytenft_params.ajax_url,
			data: {
				action: 'check_payment_status',
				order_id: orderId,
				security: bytenft_params.bytenft_nonce,
			},
			dataType: 'json',
			cache: false,
			processData: true,
			success: function (response) {
				if (response.success && response.data && response.data.status) {
					resolve({
						status: response.data.status,
						redirect_url: response.data.redirect_url
					});
				} else {
					reject('Invalid response');
				}
			},
			error: function (xhr, status, error) {
				reject(error);
			}
		});
	});
}
