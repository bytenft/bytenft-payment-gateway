console.log('bytenft-blocks.js loaded');

(function () {

    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML } = window.wp?.element || {};

    if (!registerPaymentMethod) return;

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

    async function processPayment(args) {

        const response = await fetch(settings.ajax_url || bytenft_params.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'bytenft_block_gateway_process',
                nonce: bytenft_params.bytenft_nonce,
                data: args
            })
        });

        return await response.json();
    }

    const methodConfig = {
        name: settings.id || 'bytenft',
        label: settings.title || 'ByteNFT',

        content: createElement('div', null,
            createElement(RawHTML, {}, settings.description || '')
        ),

        canMakePayment: async () => true,

        paymentMethodInterface: {

            initialize: async () => {
                return true; // DO NOT open popup here
            },

            processPayment: async (args) => {

                const res = await processPayment(args);

                if (res.result === 'success' && res.redirect) {

                    // ✅ ONLY SUCCESS OPENS POPUP
                    if (window.ByteNFTPopup.openLoading()) {
                        window.ByteNFTPopup.redirect(res.redirect);
                    } else {
                        window.location.href = res.redirect;
                    }

                    return { type: 'success' };
                }

                // ❌ ERROR = NO POPUP
                window.ByteNFTPopup.close();

                return {
                    type: 'error',
                    message: res?.error || 'Payment failed'
                };
            },

            onPaymentError: () => {
                window.ByteNFTPopup.close();
            }
        }
    };

    registerPaymentMethod(methodConfig);

})();