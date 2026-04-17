<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_OrderPaymentRepository
{
    private $wpdb;
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'order_payment_link';
    }

    public function save(int $order_id, array $data): void
    {
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE order_id = %d",
            $order_id
        ));

        $payload = [
            'order_id'       => $order_id,
            'uuid'           => sanitize_text_field($data['pay_id'] ?? ''),
            'payment_link'   => esc_url_raw($data['payment_link'] ?? ''),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'amount'         => (float) ($data['amount'] ?? 0),
            'created_at'     => current_time('mysql')
        ];

        if ($existing) {
            $this->wpdb->update($this->table, $payload, ['order_id' => $order_id]);
        } else {
            $this->wpdb->insert($this->table, $payload);
        }
    }
}