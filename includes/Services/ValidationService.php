<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_ValidationService
{
    public function validate($order): void
    {
        if (!filter_var($order->get_billing_email(), FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }

        if ($this->isPOBox($order->get_billing_address_1())) {
            throw new Exception('PO Box addresses are not allowed');
        }
    }

    private function isPOBox($address): bool
    {
        if (!$address) return false;

        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));
        return str_contains($clean, 'pobox') || str_contains($clean, 'postoffice');
    }
}