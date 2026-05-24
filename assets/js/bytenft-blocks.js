console.log('bytenft-blocks.js loaded at', new Date().toISOString());
(function () {
     const wc = window.wc || {};
    const wp = window.wp || {};

    const registerPaymentMethod =
        wc.wcBlocksRegistry && wc.wcBlocksRegistry.registerPaymentMethod;

    const element = wp.element || {};
    const createElement = element.createElement;
    const RawHTML = element.RawHTML;
    const Fragment = element.Fragment;

    if (typeof registerPaymentMethod !== 'function') {
        return;
    }

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('bytenft') || {};

    const label = settings.title || 'ByteNFT';
    const description = settings.description || '';

    console.log('ALL SETTINGS:', window.wc?.wcSettings);

    console.log(
        'BYTENFT SETTINGS:',
        window.wc?.wcSettings?.getPaymentMethodData?.('bytenft')
    );

    /**
     * BLOCK CONTENT
     */
    const Content = () => {

        return createElement(
            Fragment,
            {},

            // Description
            createElement(
                'div',
                {
                    className: 'bytenft-description'
                },
                createElement(
                    RawHTML,
                    {},
                    description
                )
            ),

            // Consent Checkbox
            createElement(
                'p',
                {
                    className:
                        'form-row form-row-wide bytenft-consent-wrapper',
                    style: {
                        marginTop: '15px'
                    }
                },

                createElement(
                    'label',
                    {
                        htmlFor: 'bytenft_consent',
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px'
                        }
                    },

                    createElement('input', {
                        type: 'checkbox',
                        id: 'bytenft_consent',
                        name: 'bytenft_consent',
                        value: '1',
                        required: true
                    }),

                    'I consent to the collection of my data to process this payment'
                )
            )
        );
    };

    const methodConfig = {
        name: settings.id || 'bytenft',
        label,
        ariaLabel: label,

        content: createElement(Content),
        edit: createElement(Content),

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