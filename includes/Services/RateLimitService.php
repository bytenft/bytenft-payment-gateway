<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_RateLimitService
{
    private int $window = 10;
    private int $maxRequests = 5;

    public function check(string $ip): void
    {
        $key = "bytenft_rate_limit_{$ip}";

        $timestamps = get_transient($key) ?: [];
        $now = time();

        $timestamps = array_filter($timestamps, fn($t) => ($now - $t) < $this->window);

        if (count($timestamps) >= $this->maxRequests) {
            throw new Exception('Too many requests. Please try again later.');
        }

        $timestamps[] = $now;
        set_transient($key, $timestamps, $this->window);
    }
}