jQuery(function ($) {
	let isSubmitting = false;
	let isHandlerBound = false;
	let orderId = null;
	let $button = null;
	let originalButtonText = '';
	let pollingInterval = null;
	let pollingActive = false;
	let payment_link = null;

	const loaderUrl = bytenft_params.bytenft_loader ? encodeURI(bytenft_params.bytenft_loader) : '';
	$('body').append(`
		<div class="bytenft-loader-background"></div>
		<div class="bytenft-loader"><img src="${loaderUrl}" alt="Loading..." /></div>
	`);

	$('form.checkout').on('checkout_place_order', function () {
		const selected = $('input[name="payment_method"]:checked').val();
		return selected !== bytenft_params.payment_method;
	});

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

					openPopup(response.payment_link, response.customer_email);
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

	function openPopup(paymentLink, customerEmail) {
		payment_link = paymentLink;

		$('#bytenft-manual-link').attr('href', paymentLink);
		$('#bytenft-qr-img').attr('src', "https://image-charts.com/chart?chs=120x120&cht=qr&chl=" + paymentLink + "&choe=UTF-8");

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
					const popup = $("#bytenft-payment-popup");

					if (isTxn && status === 'pending' && !hasShownProcessingStep) {
						hasShownProcessingStep = true; // ✅ Prevent repeat
						popup.find('.payment-link-div').hide();
						popup.find('.tabs-wrapper').show();
						popup.find('.payment-timeline').show();
						popup.find('.loader-sec').show(); 
						
						// Always reset approval/failure block to neutral
						const approveFail = popup.find('.payment-approve-or-fail');
						approveFail.removeClass('failed').addClass('pending');						
						approveFail.find('.waiting-sec, .approve-text').show();						
						approveFail.find('.success-text, .failed-icon, .fail-text').hide();
						approveFail.find('.payment-started .success-sec').show();	

						// Show check icon after 3s
						setTimeout(() => {
							popup.find('.loader-sec').hide();       // hide spinner
							popup.find('.processing .success-sec').show();      // show check icon
						}, 3000);
						
					}

					if (status === 'success') {
						popup.find('.payment-timeline .icon').hide();
						popup.find('.thank-you-msg, .success-sec').show();
						popup.find('.payment-timeline').removeClass('processing');

						const approveFail = popup.find('.payment-approve-or-fail');
						approveFail.removeClass('pending');
						approveFail.find('.approve-text, .waiting-sec').hide();
						approveFail.find('.success-sec, .success-text').show();						
					} else if (status === 'failed') {
						popup.find('.payment-timeline .icon').hide();
						popup.find('.payment-timeline .success-sec').show();
						popup.find('.payment-timeline').removeClass('processing');
						

						const approveFail = popup.find('.payment-approve-or-fail');
						approveFail.removeClass('pending').addClass('failed');
						approveFail.find('.waiting-sec').hide();
						approveFail.find('.approve-icon, .approve-text').hide();
						approveFail.find('.failed-icon, .fail-text').show();

						popup.find('.failed-msg').show();

					}

					if (status === 'success' || status === 'failed') {
						setTimeout(() => {
							stopPolling();
							onComplete?.();
							if (statusResponse.data.redirect_url) {
								window.location.href = statusResponse.data.redirect_url;
							}
						}, 4000);
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
});
