console.log('bytenft-blocks.js loaded at', new Date().toISOString());

/**
 * Registers the ByteNFT block payment method with WooCommerce Blocks.
 */
function registerByteNFTBlock() {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML } = window.wp?.element || {};

    if (typeof registerPaymentMethod !== 'function') {
        return;
    }

    // Use WooCommerce Blocks API to get the payment method settings
    const settings = window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

    if (!settings.title) return; // Exit if settings not yet ready

    const methodConfig = {
        name: settings.id || 'bytenft',
        label: settings.title,
        ariaLabel: settings.title,

        content: createElement(
            'div',
            { className: 'bytenft-description' },
            createElement(RawHTML, {}, settings.description || '')
        ),

        edit: createElement(
            'div',
            { className: 'bytenft-edit' },
            settings.title
        ),

        canMakePayment: async () => settings.can_pay === true,

        supports: {
            features: settings.supports || ['products'],
        },
    };

    registerPaymentMethod(methodConfig);
    console.log('ByteNFT block payment registered:', settings.title);
}

/**
 * Retry registration until WooCommerce Blocks registry is ready.
 */
function ensureByteNFTRegistration() {
    if (window.wc?.wcBlocksRegistry?.registerPaymentMethod) {
        registerByteNFTBlock();
    } else {
        setTimeout(ensureByteNFTRegistration, 100); // Retry every 100ms
    }
}

// Ensure registration after DOM content is loaded
document.addEventListener('DOMContentLoaded', ensureByteNFTRegistration);

/**
 * Refresh block checkout payment methods after relevant events.
 */
function refreshBlockPaymentMethods() {
    if (window.wc && window.wc.blocksCheckout) {
        // WC Blocks 8.x+ API
        document.body.dispatchEvent(new CustomEvent('wc-blocks_checkout_update_payment_methods'));
    } else {
        // Fallback for older versions
        $(document.body).trigger('update_checkout');
    }
}