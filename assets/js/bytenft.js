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

	function isOurGatewaySelected() {
		return $('input[name="payment_method"]:checked').val() === bytenft_params.payment_method;
	}

	function byteNFTmarkCheckoutFormIfNeeded() {
		const $form = $("form.checkout");
		const selectedMethod = $form.find('input[name="payment_method"]:checked').val();
		const expectedId = bytenft_params.payment_method + '-checkout-form';

		if (selectedMethod === bytenft_params.payment_method) {
			$form.attr('id', expectedId);
		} else {
			$form.removeAttr('id');
		}
	}

	function byteNFTbindCheckoutHandler() {
		const formId = '#' + bytenft_params.payment_method + '-checkout-form';
		const $form = $(formId);

		// Prevent double-binding
		if ($form.data('bytenft-bound')) return;

		// Remove ALL submit handlers (from WooCommerce and others)
		$form.off("submit");

		// Bind ONLY our handler
		$form.on("submit.bytenft", function (e) {
			const isOurMethod = $(this).find('input[name="payment_method"]:checked').val() === bytenft_params.payment_method;
			if (!isOurMethod) return true; // let default Woo behavior run for other methods

			e.preventDefault();
			e.stopImmediatePropagation();

			handleFormSubmit.call(this, e);
			return false;
		});

		// Mark this form so we don't bind twice
		$form.data('bytenft-bound', true);
	}

	function showLoader() {
		$(".bytenft-loader, .bytenft-loader-background").show();
	}

	function hideLoader() {
		$(".bytenft-loader, .bytenft-loader-background").hide();
	}

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

	function handleResponse(response, $form) {
		hideLoader();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				$form.removeAttr('data-result data-redirect-url');
				openPopup(response.payment_link, response.customer_email);
				startPolling(response.payment_link);
			} else {
				throw response.messages || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
		}
	}

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
		hideLoader();
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

	function stopPolling() {
		clearInterval(pollingInterval);
		pollingActive = false;
	}

	$(document.body).on("updated_checkout", function () {
		byteNFTmarkCheckoutFormIfNeeded();
		byteNFTbindCheckoutHandler(); // this will safely auto-skip if not selected
	});

	$(document.body).on("change", 'input[name="payment_method"]', function () {
		byteNFTmarkCheckoutFormIfNeeded();
		byteNFTbindCheckoutHandler(); // this will safely auto-skip if not selected
	});

});
