<?php
/**
 * Keeps redirects out of the nginx FastCGI page cache.
 *
 * SpinupWP's page cache ignores Cache-Control, so WordPress' nocache headers on
 * a redirect have no effect, and its cache key leaves out gclid and utm_*
 * parameters. A cached redirect would therefore replay the first visitor's
 * campaign parameters in the Location header to everyone after them.
 * X-Accel-Expires is still honoured, so a zero value excludes the response.
 *
 * Runs at priority 0 so the header is sent before Redirection's own
 * wp_redirect filter (priority 1), which may exit on some server setups.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('wp_redirect', function ($location) {
    if ($location && !headers_sent()) {
        header('X-Accel-Expires: 0');
    }

    return $location;
}, 0);
