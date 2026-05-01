console.log('bytenft-block loaded');

(function () {

    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML } = window.wp?.element || {};

    if (!registerPaymentMethod) return;

    const settings = window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

    const config = {
        name: settings.id || 'bytenft',
        label: settings.title || 'ByteNFT',
        ariaLabel: settings.title || 'ByteNFT',

        content: createElement(
            'div',
            null,
            createElement(RawHTML, null, settings.description || '')
        ),

        edit: createElement('div', null, settings.title || 'ByteNFT'),

        canMakePayment: async () => settings.can_pay !== false,

        supports: {
            features: settings.supports || ['products'],
        }
    };

    if (settings.title) {
        registerPaymentMethod(config);
    }

})();