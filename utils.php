<?php

// Defines
define('MOBBEX_WC_TEXT_DOMAIN', 'mobbex-for-woocommerce');
define('MOBBEX_CHECKOUT', 'https://api.mobbex.com/p/checkout');

define('MOBBEX_WC_GATEWAY', 'WC_Gateway_Mobbex');
define('MOBBEX_WC_GATEWAY_ID', 'mobbex');

define('MOBBEX_VERSION', '2.3.4');
define('MOBBEX_EMBED_VERSION', '1.0.6');

if (!function_exists('mobbex_debug')) {
    // https://github.com/bonny/WordPress-Simple-History/

    function mobbex_debug($message, $data = [])
    {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
            apply_filters(
                'simple_history_log',
                'Mobbex: ' . $message,
                $data,
                'debug'
            );
        }
    }

}