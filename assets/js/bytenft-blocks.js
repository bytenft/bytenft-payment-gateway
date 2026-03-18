(function () {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement, RawHTML } = window.wp?.element || {};

	if (typeof registerPaymentMethod !== 'function') {
		return;
	}

	const settings =
	window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

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
			return true;  // return settings.can_pay === true;
		},

		supports: {
			features: settings.supports || ['products'],
		},
	};

	registerPaymentMethod(methodConfig);
})();