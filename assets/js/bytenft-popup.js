window.bytenftPopupManager = (function () {
    let popupWindow = null;
    let popupInterval = null;
    let paymentStatusInterval = null;
    let orderId = null;

    function openBlank(loaderUrl) {
        if (!popupWindow || popupWindow.closed) {
            popupWindow = window.open('', '_blank', 'width=700,height=700');
        }

        if (!popupWindow) {
            alert("Popup blocked. Please allow popups.");
            return null;
        }

        popupWindow.document.write(`
            <html>
            <head><title>Secure Payment</title></head>
            <body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;">
                ${loaderUrl ? `<img src="${loaderUrl}" style="max-width:120px;margin-bottom:20px;" />` : ''}
                <div>Connecting to secure payment...</div>
            </body>
            </html>
        `);

        return popupWindow;
    }

    function redirect(url) {
        if (!url || url.includes('undefined')) return;

        setTimeout(() => {
            if (popupWindow && !popupWindow.closed) {
                popupWindow.location.href = url;
            } else {
                window.location.href = url;
            }
        }, 200);

        startCloseWatcher();
    }

    function startCloseWatcher() {
        popupInterval = setInterval(function () {
            if (!popupWindow || popupWindow.closed) {
                clearInterval(popupInterval);
                clearInterval(paymentStatusInterval);
                popupWindow = null;

                jQuery.post(bytenft_params.ajax_url, {
                    action: 'bytenft_popup_closed_event',
                    order_id: orderId,
                    security: bytenft_params.bytenft_nonce
                }, function (response) {

                    if (response?.success && response?.data?.redirect_url) {
                        window.location.replace(response.data.redirect_url);
                    }

                }, 'json');
            }
        }, 700);
    }

    function setOrderId(id) {
        orderId = id;
    }

    function close() {
        try { popupWindow?.close(); } catch (e) {}
        popupWindow = null;
        clearInterval(popupInterval);
        clearInterval(paymentStatusInterval);
    }

    return {
        openBlank,
        redirect,
        setOrderId,
        close,
        getWindow: () => popupWindow
    };
})();