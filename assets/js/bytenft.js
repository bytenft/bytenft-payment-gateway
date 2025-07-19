jQuery(function ($) {
	var isSubmitting = false;
	var popupInterval;
	var paymentStatusInterval;
	var orderId;
	var $button;
	var originalButtonText;
	var isPollingActive = false;
	let isHandlerBound = false;

	var loaderUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
	$('body').append(
		'<div class="bytenft-onramp-loader-background"></div>' +
		'<div class="bytenft-onramp-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	$('form.checkout').on('checkout_place_order', function () {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod === bytenft_params.payment_method) {
			return false;
		}
	});

	function markCheckoutFormIfNeeded() {
		var $form = $("form.checkout");
		var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
		var expectedId = bytenft_params.payment_method + '-checkout-form';

		if (selectedMethod === bytenft_params.payment_method) {
			$form.attr('id', expectedId);
		} else if ($form.attr('id') === expectedId) {
			$form.removeAttr('id'); // Only remove if we added it
		}
	}

	function bindCheckoutHandler() {
		var formId = '#' + bytenft_params.payment_method + '-checkout-form';
		if (!$(formId).length) return; // Form not available yet

		$(formId).off("submit.bytenft-onramp").on("submit.bytenft-onramp", function (e) {
			if ($(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false;
			}
		});
	}

	$(document.body).on("updated_checkout", function () {
		isHandlerBound = false;
		markCheckoutFormIfNeeded();
		bindCheckoutHandler();
	});

	// NEW: Also listen to payment method change
	$(document.body).on("change", 'input[name="payment_method"]', function () {
		markCheckoutFormIfNeeded();
		bindCheckoutHandler();
	});

	markCheckoutFormIfNeeded();
	bindCheckoutHandler();

	function handleFormSubmit(e) {
		e.preventDefault();
		var $form = $(this);

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

		$('.bytenft-onramp-loader-background, .bytenft-onramp-loader').show();

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
			window.location.href = sanitizedPaymentLink;
			resetButton();
		} else {
			popupInterval = setInterval(function () {
				if (popupWindow.closed) {
					clearInterval(popupInterval);
					clearInterval(paymentStatusInterval);
					isPollingActive = false;

					$.ajax({
						type: 'POST',
						url: bytenft_params.ajax_url,
						data: {
							action: 'popup_closed_event',
							order_id: orderId,
							security: bytenft_params.bytenft__nonce,
						},
						dataType: 'json',
						success: function (response) {
							if (response.success && response.data.redirect_url) {
								window.location.href = response.data.redirect_url;
							}
							isPollingActive = false;
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

			if (!isPollingActive) {
				isPollingActive = true;
				paymentStatusInterval = setInterval(function () {
					$.ajax({
						type: 'POST',
						url: bytenft_params.ajax_url,
						data: {
							action: 'check_payment_status',
							order_id: orderId,
							security: bytenft_params.bytenft__nonce,
						},
						dataType: 'json',
						success: function (statusResponse) {
							if (statusResponse.data.status === 'success' || statusResponse.data.status === 'failed') {
								clearInterval(paymentStatusInterval);
								clearInterval(popupInterval);
								if (statusResponse.data.redirect_url) {
									window.location.href = statusResponse.data.redirect_url;
								}
								isPollingActive = false;
							}
						}
					});
				}, 5000);
			}
		}
	}

	function handleResponse(response, $form) {
		$('.bytenft-onramp-loader-background, .bytenft-onramp-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				var paymentLink = response.payment_link;
				openPaymentLink(paymentLink);
				$form.removeAttr('data-result').removeAttr('data-redirect-url');
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
		$('.bytenft-onramp-loader-background, .bytenft-onramp-loader').hide();
	}
<<<<<<< Updated upstream

	function openPopup(paymentLink, customerEmail) {
		payment_link = paymentLink;

		$('#bytenft-manual-link').attr('href', paymentLink);
		$('#bytenft-qr-img').attr('src', "https://image-charts.com/chart?chs=95x95&cht=qr&chl=" + paymentLink + "&choe=UTF-8");

		if (customerEmail) {
			$('#bytenft-payment-popup .email-info .bytenft-customer-email').text(customerEmail);
		}

		// Reset UI
		$('#bytenft-payment-popup .payment-link-div').show();
		$('#bytenft-payment-popup .payment-timeline, .tabs-wrapper, .thank-you-msg, .failed-msg, .payment-failed').hide();
		$('#bytenft-payment-popup').fadeIn();

		startPolling(() => {
			$('#bytenft-payment-popup').fadeOut();
		});

		$('#bytenft-cancel-order').off('click').on('click', function () {
			$('#bytenft-payment-popup').fadeOut();
			stopPolling();
			resetButton();
		});
	}
	function startPolling(onComplete) {
		let hasShownProcessingStep = false;

		if (pollingActive || !orderId) return;
		pollingActive = true;

		pollingInterval = setInterval(() => {
			$.ajax({
				type: 'POST',
				url: bytenft_params.ajax_url,
				data: {
					action: 'check_payment_status',
					order_id: orderId,
					security: bytenft_params.bytenft_nonce,
				},
				dataType: 'json',
				success: (statusResponse) => {
					const status = statusResponse?.data?.status;
					const isTxn = statusResponse?.data?.is_transaction;
					const redirectUrl = statusResponse?.data?.redirect_url;
					const popup = $("#bytenft-payment-popup");
					const approveFail = popup.find('.payment-approve-or-fail');

					// Show timeline once
					if (isTxn && status === 'pending' && !popup.data('timeline-shown')) {
						popup.data('timeline-shown', true);

							// Smooth transition from payment link view to timeline
						popup.find('.payment-link-div').slideUp(200, () => {
							popup.find('.tabs-wrapper, .payment-timeline')
								.css({ display: 'block', opacity: 0 })
								//.delay(200)
								.animate({ opacity: 1 }, 300);
						});


						// popup.find('.payment-link-div').fadeOut(200);
						// popup.find('.tabs-wrapper, .payment-timeline').fadeIn(300);
						
						popup.find('.step.processing .loader-sec').fadeIn(300); // show processing loader

						approveFail.removeClass('failed').addClass('pending');
						approveFail.find('.waiting-sec, .approve-text').fadeIn(300);
						approveFail.find('.success-text, .failed-icon, .fail-text, .loader-sec, .success-sec').hide();
						popup.find('.payment-started .success-sec').fadeIn(300);
					}

					if (status === 'success') {
						if (!popup.data('handled-success')) {
							popup.data('handled-success', true);
							stopPolling();

							// Step 2: Mark "processing" step as completed
							popup.find('.step.processing .loader-sec').stop(true, true).fadeOut(200, () => {
								popup.find('.step.processing .success-sec').fadeIn(300);
							});

							// Step 3 - Approval
							approveFail.removeClass('failed').addClass('pending');

							// Hide everything first for clean setup
							approveFail.find('.waiting-sec, .success-sec, .success-text, .failed-icon, .fail-text').hide();

							// ✅ Show approval text and loader together
							approveFail.find('.approve-text').show().css('opacity', 0).animate({ opacity: 1 }, 300);
							approveFail.find('.loader-sec').fadeIn(300);

							setTimeout(() => {
								approveFail.find('.loader-sec').fadeOut(200);
								approveFail.find('.approve-text').fadeOut(200, () => {
									approveFail.find('.success-sec, .success-text').fadeIn(300);
									approveFail.removeClass('pending');
								});

								popup.find('.thank-you-msg').slideDown(300);
								popup.find('.redirect-section').slideDown(300);
								handleRedirect(redirectUrl);
							}, 3000);
						}
					}

					if (status === 'failed') {
						if (!popup.data('handled-failed')) {
							popup.data('handled-failed', true); // ✅ Prevent multiple triggers
							stopPolling(); // ✅ Stop further requests

							// Step 2: Hide loader, show success icon (optional)
							popup.find('.step.processing .loader-sec').fadeOut(200, () => {
								popup.find('.step.processing .success-sec').fadeIn(300);
							});

							// Step 3: Show failure after slight delay
							setTimeout(() => {
								approveFail.removeClass('pending').addClass('failed');

								// Fade out all previous elements
								approveFail.find('.waiting-sec, .approve-text, .approve-icon, .success-sec, .success-text, .loader-sec').fadeOut(200);

								// Fade in failure icon and text
								approveFail.find('.failed-icon, .fail-text').fadeIn(300);

								// Slide in failure message and redirect options
								popup.find('.failed-msg').slideDown(300);
								popup.find('.redirect-section').slideDown(300);

								handleRedirect(redirectUrl);
							}, 3000);
						}
					}

				},
				error: (xhr, status, error) => {
					console.error('Polling error:', error);
				}
			});
		}, 5000);
	}

	// ⏳ Redirect with countdown + button
	function handleRedirect(redirectUrl) {
		if (!redirectUrl) return;

		const popup = $("#bytenft-payment-popup");
		let secondsLeft = 3;
		const timerEl = popup.find('#redirect-timer');

		timerEl.text(secondsLeft); // Init text

		const interval = setInterval(() => {
			secondsLeft--;
			timerEl.text(secondsLeft);
			if (secondsLeft <= 0) {
				clearInterval(interval);
				// Uncomment when ready for live
				window.location.href = redirectUrl;
			}
		}, 1000);

		popup.find('#redirect-now-btn').on('click', () => {
			clearInterval(interval);
			// Uncomment when ready for live
			window.location.href = redirectUrl;
		});
	}


	function stopPolling() {
		clearInterval(pollingInterval);
		pollingActive = false;
	}

	$(document).on('click', '#bytenft-close-payment-popup', function () {
		$('#bytenft-payment-popup').fadeOut();
		stopPolling();
		resetButton();
	});

	$('#bytenft-send-link-btn').on('click', function () {
		const email = $('#bytenft-payment-popup input[name=email]').val().trim();
		const phone = $('#bytenft-payment-popup input[name=phone]').val().trim();

		if (!email && !phone) {
			alert('Please enter an email or phone number.');
			return;
		}

		$.ajax({
			url: bytenft_params.ajax_url,
			method: 'POST',
			data: {
				action: 'send_payment_link',
				email: email,
				phone: phone,
				payment_link: payment_link,
				order_id: orderId,
			},
			beforeSend: function () {
				$('#bytenft-send-link-btn').text('Sending...');
			},
			success: function (res) {
				const $msg = $('#bytenft-send-link-msg');
				$msg.remove();

				const message = res.success
					? 'Payment link sent successfully!'
					: `${res.data.message || 'Failed to send payment link.'}`;

				const color = res.success ? 'green' : 'red';

				$('#bytenft-send-link-btn')
					.after(`<div id="bytenft-send-link-msg" style="margin-top:8px;color:${color};font-size:14px;">${message}</div>`);
			},
			error: function () {
				alert('An error occurred.');
			},
			complete: function () {
				$('#bytenft-send-link-btn').text('Send');
			}
		});
	});

	$(document).on('click', '#bytenft-payment-popup .switch_tab', function () {
		const tab = $(this).data('tab');

		$('.tab').removeClass('active');
		$(this).addClass('active');

		 if (tab === 'email') {
			$('#phone-input').hide();
			$('#email-input').show();
		} else if (tab === 'phone') {
			$('#phone-input').show();
			$('#email-input').hide();
		}
	});
=======
>>>>>>> Stashed changes
});
