(function () {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement, RawHTML } = window.wp?.element || {};

	if (typeof registerPaymentMethod !== 'function') {
		console.error('[ByteNFT] wcBlocksRegistry not available yet');
		return;
	}

	const settings =
	window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

	console.log('[ByteNFT] settings:', settings);

	// ✅ GLOBAL CACHE (VERY IMPORTANT)
	let canPayCache = null;
	let lastCheckTime = 0;

	const methodConfig = {
		name: settings.id || 'bytenft',
		label: settings.title || 'ByteNFT',
		ariaLabel: settings.title || 'ByteNFT',

		content: createElement(
			'div',
			{ className: 'bytenft-description' },
			createElement(RawHTML, {}, settings.description || '')
		),

		edit: createElement(
			'div',
			{ className: 'bytenft-edit' },
			settings.title || 'ByteNFT'
		),

		// ✅ FIXED canMakePayment
		canMakePayment: async () => {
			console.log('[ByteNFT] canMakePayment evaluated', settings);
			return settings.can_pay === true;
		},

		supports: {
			features: settings.supports || ['products'],
		},
	};

	console.log('[ByteNFT] Registering payment method:', methodConfig);

	registerPaymentMethod(methodConfig);
})();