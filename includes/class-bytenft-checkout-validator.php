<?php

if (!defined('ABSPATH')) {
    exit;
}

class ByteNFT_Checkout_Validator extends WC_Checkout
{
    public function validate(array &$data, WP_Error &$errors)
    {
        // Native WooCommerce validation
        $this->validate_posted_data($data, $errors);
        $this->check_cart_items();
    }
}