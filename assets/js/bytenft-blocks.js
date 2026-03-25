console.log('bytenft-blocks.js loaded at', new Date().toISOString());
(function () {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML } = window.wp?.element || {};

    if (typeof registerPaymentMethod !== 'function') {
        return;
    }

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

    const label = settings.title || 'ByteNFT';
    const description = settings.description || '';

    const methodConfig = {
        name: settings.id || 'bytenft',
        label,
        ariaLabel: label,

        content: createElement(
            'div',
            { className: 'bytenft-description' },
            createElement(RawHTML, {}, description)
        ),

        edit: createElement(
            'div',
            { className: 'bytenft-edit' },
            label
        ),

        canMakePayment: async () => {
            return settings.can_pay !== false;
        },

        supports: {
            features: settings.supports || ['products'],
        },
    };
    if(settings.title){
        console.log(settings.title);
        registerPaymentMethod(methodConfig);
    }
    
})();


// Call this function whenever you want to refresh payment methods in the block checkout
function refreshBlockPaymentMethods() {
    if (window.wc && window.wc.blocksCheckout) {
        // For WC Blocks 8.x+ (newer API)
        document.body.dispatchEvent(new CustomEvent('wc-blocks_checkout_update_payment_methods'));
    } else {
        // Fallback for older versions
        $(document.body).trigger('update_checkout');
    }
}

// Example: Refresh after a custom event, or after a failed payment, or after a cart update
// Call refreshBlockPaymentMethods() only in response to relevant events, not on every load.