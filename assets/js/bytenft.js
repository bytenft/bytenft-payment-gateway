jQuery(function ($) {
	let isSubmitting = false;
	let isHandlerBound = false;
	let orderId = null;
	let $button = null;
	let originalButtonText = '';
	let pollingInterval = null;
	let pollingActive = false;
	payment_link = null;

	// Append loader
	const loaderUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
	$('body').append(`
		<div class="bytenft-loader-background"></div>
		<div class="bytenft-loader"><img src="${loaderUrl}" alt="Loading..." /></div>
	`);

	// Disable default WC submit
	$('form.checkout').on('checkout_place_order', function () {
		const selected = $('input[name="payment_method"]:checked').val();
		return selected !== bytenft_params.payment_method;
	});

	// Rebind on updated checkout
	$(document.body).on("updated_checkout", () => {
		isHandlerBound = false;
		bindCheckoutHandler();
	});

	bindCheckoutHandler();

	function bindCheckoutHandler() {
		if (isHandlerBound) return;
		isHandlerBound = true;

		$('form.checkout').off('submit.bytenft').on('submit.bytenft', function (e) {
			const selected = $(this).find('input[name="payment_method"]:checked').val();
			if (selected === bytenft_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false;
			}
		});
	}

	function handleFormSubmit(e) {
		e.preventDefault();

		const $form = $(this);
		if (isSubmitting) return false;

		isSubmitting = true;

		const selected = $form.find('input[name="payment_method"]:checked').val();
		if (selected !== bytenft_params.payment_method) {
			isSubmitting = false;
			return true;
		}

		$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		originalButtonText = $button.text();
		$button.prop('disabled', true).text('Processing...');
		$('.bytenft-loader-background, .bytenft-loader').show();

		$.ajax({
			type: 'POST',
			url: wc_checkout_params.checkout_url,
			data: $form.serialize(),
			dataType: 'json',
			success: (response) => handleResponse(response, $form),
			error: () => handleError($form),
			complete: () => isSubmitting = false,
		});
	}

	function handleResponse(response, $form) {
		$('.bytenft-loader-background, .bytenft-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				$form.removeAttr('data-result data-redirect-url');

				if (response.reuse) {
					showPendingMessage();
				} else {
					openPopup(response.payment_link);
				}
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
		$form.prepend(`<div class="wc_er">${err}</div>`);
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

	function openPopup(paymentLink) {
		payment_link = paymentLink;
		$('#bytenft-manual-link').attr('href', paymentLink);
		$('#bytenft-qr-img').attr('src', "https://image-charts.com/chart?chs=120x120&cht=qr&chl="+paymentLink+"&choe=UTF-8");
		$('#bytenft-payment-popup').fadeIn();

		$('#bytenft-cancel-order').off('click').on('click', function () {
			$('#bytenft-payment-popup').fadeOut();
			stopPolling();
			resetButton();
		});

		startPolling(() => {
			$('#bytenft-payment-popup').fadeOut();
		});
	}

	function showPendingMessage() {
		$('#bytenft-pending-popup').fadeIn();
		startPolling(() => {
			$('#bytenft-pending-popup').fadeOut();
		});
	}

	function startPolling(onComplete) {
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
					if (status === 'success' || status === 'failed') {
						stopPolling();
						onComplete?.();
						if (statusResponse.data.redirect_url) {
							window.location.href = statusResponse.data.redirect_url;
						}
					}
				},
				error: (xhr, status, error) => {
					console.error('Polling error:', error);
				}
			});
		}, 5000);
	}

	function stopPolling() {
		clearInterval(pollingInterval);
		pollingActive = false;
	}

	$(document).on('click', '#bytenft-close-payment-popup', function () {
		$('#bytenft-payment-popup').fadeOut();
		stopPolling();
		resetButton(); // ✅ Reset place order button
	});

	$(document).on('click', '#bytenft-close-pending-popup', function () {
		$('#bytenft-pending-popup').fadeOut();
		stopPolling();
		resetButton(); // ✅ Reset place order button
	});

	$('#bytenft-send-link-btn').on('click', function () {
		const email = $('#bytenft-email-input').val().trim();
		const phone = $('#bytenft-phone-input').val().trim();

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
				if (res.success) {
					alert('Payment link sent successfully!');
				} else {
					alert(res.data.message || 'Failed to send payment link.');
				}
			},
			error: function () {
				alert('An error occurred.');
			},
			complete: function () {
				$('#bytenft-send-link-btn').text('Send');
			}
		});
	});

});
