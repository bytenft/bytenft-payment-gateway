( function() {
	const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
	const { createElement,RawHTML } = window.wp?.element || {};

	// Bail if registry isn't ready
	if ( typeof registerPaymentMethod !== 'function' ) {
		console.error( '[ByteNFT] wcBlocksRegistry not available yet' );
		return;
	}

	// Load settings from WC or fallback to localized params
	const settings =
		window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') ||
		window.bytenft_params?.settings ||
		{};

	console.log( '[ByteNFT] settings:', settings );

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
		canMakePayment: async () => {
			console.log( '[ByteNFT] canMakePayment called' );
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	};

	console.log( '[ByteNFT] Registering payment method:', methodConfig );
	registerPaymentMethod( methodConfig );
} )();