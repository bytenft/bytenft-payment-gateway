<?php

namespace BYTENFT\Http\Ajax;

if (!defined('ABSPATH')) exit;

class BYTENFT_PaymentAjaxController
{
    public function register()
    {
        add_action('wp_ajax_bytenft_block_gateway_process', [$this, 'handle_block_payment_request']);
        add_action('wp_ajax_nopriv_bytenft_block_gateway_process', [$this, 'handle_block_payment_request']);

        add_action('wp_ajax_bytenft_popup_closed_event', [$this, 'handle_popup_close']);
        add_action('wp_ajax_nopriv_bytenft_popup_closed_event', [$this, 'handle_popup_close']);
    }

    public function handle_block_payment_request()
    {
        $nonce = isset($_POST['nonce'])
            ? sanitize_text_field(wp_unslash($_POST['nonce']))
            : '';

        if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {
            wp_send_json([
                'result' => 'fail',
                'error'  => 'Security check failed.'
            ], 403);
        }

        $gateway = $this->get_gateway();

        if (!$gateway) {
            wp_send_json([
                'result' => 'fail',
                'error'  => 'Gateway not found.'
            ], 500);
        }

        $order_id = WC()->session ? WC()->session->get('store_api_draft_order') : null;

        if (!$order_id) {
            wp_send_json([
                'result' => 'fail',
                'error'  => 'Invalid order.'
            ], 400);
        }

        $result = $gateway->process_payment($order_id);

        wp_send_json($result);
    }

    public function handle_popup_close()
    {
        $security = isset($_POST['security'])
            ? sanitize_text_field(wp_unslash($_POST['security']))
            : '';

        if (empty($security) || !wp_verify_nonce($security, 'bytenft_payment')) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }

        $order_id = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => 'Order ID missing.'], 400);
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => 'Order not found.'], 404);
        }

        $status = $order->get_status();

        if (in_array($status, ['completed', 'cancelled', 'refunded'], true)) {
            wp_send_json_success([
                'status' => $status,
                'redirect_url' => $order->get_checkout_order_received_url()
            ]);
        }

        wp_send_json_success([
            'status' => $status,
            'redirect_url' => $order->get_checkout_order_received_url()
        ]);
    }

    private function get_gateway()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return $gateways['bytenft'] ?? null;
    }
}