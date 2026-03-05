<?php
/**
 * Controls search engine visibility based on environment type.
 *
 * - Staging: Forces "Discourage search engines" ON (noindex).
 * - Production: Forces "Discourage search engines" OFF (allow indexing).
 *
 * Uses pre_option filter to override blog_public without changing the database value.
 */

if (!defined('ABSPATH')) {
    exit;
}

$environment = wp_get_environment_type();

if ($environment === 'staging') {
    add_filter('pre_option_blog_public', fn () => '0');
} elseif ($environment === 'production') {
    add_filter('pre_option_blog_public', fn () => '1');
}
