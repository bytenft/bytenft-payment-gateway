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

	function openPopup(paymentLink) {
		// ✅ Show the modal popup only (no window.open)
		$('#bytenft-payment-popup').fadeIn();
		$('#bytenft-manual-link').attr('href', paymentLink);

		$('#bytenft-cancel-order').off('click').on('click', function () {
		  $('#bytenft-payment-popup').fadeOut();
		  // Optional: AJAX call to cancel order
		  resetButton();
		});


		let popupInterval;
		let paymentStatusInterval;
		let pollingStopped = false;

		// Start polling for payment status
		paymentStatusInterval = setInterval(function () {
			if (pollingStopped) return;

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
					if (
						statusResponse?.data?.status === 'success' ||
						statusResponse?.data?.status === 'failed'
					) {
						clearInterval(paymentStatusInterval);
						clearInterval(popupInterval);
						pollingStopped = true;

						// ✅ Hide the modal on payment completion
						$('#bytenft-payment-popup').fadeOut();

						if (statusResponse.data.redirect_url) {
							window.location.href = statusResponse.data.redirect_url;
						}
					}
				},
				error: function (xhr, status, error) {
					console.error("Polling error:", error);
				}
			});
		}, 5000);

		// Monitor if user manually closes the modal (optional safety net)
		popupInterval = setInterval(function () {
			if ($('#bytenft-payment-popup').is(':hidden')) {
				clearInterval(popupInterval);
				clearInterval(paymentStatusInterval);
				pollingStopped = true;

				// Optional: notify server that modal was closed manually
				$.ajax({
					type: 'POST',
					url: bytenft_params.ajax_url,
					data: {
						action: 'popup_closed_event',
						order_id: orderId,
						security: bytenft_params.bytenft_nonce,
					},
					dataType: 'json',
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
				var paymentLink = response.payment_link;
				openPopup(paymentLink);

				//$('#bytenft-payment-popup').fadeIn();

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

	const paymentLinkModal = `
		<div id="bytenft-payment-popup" style="display:none;">
			<div class="bytenft-modal-content">
				<h2>Complete Your Payment</h2>
				<p>
					We've sent you a secure payment link via email. Please check your new browser tab or window to complete the payment process.
				</p>
				<ul>
					<li>Do not close this window until payment is completed.</li>
					<li>We’re checking the status automatically in the background.</li>
				</ul>
			</div>
		</div>`;
	$('body').append(paymentLinkModal);

});