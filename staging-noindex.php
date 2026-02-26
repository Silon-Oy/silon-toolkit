<?php
/**
 * Forces "Discourage search engines from indexing this site" on in staging environments.
 *
 * Uses pre_option filter to override blog_public without changing the database value.
 * Active when WP_ENVIRONMENT_TYPE is 'staging'.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (wp_get_environment_type() === 'staging') {
    add_filter('pre_option_blog_public', fn () => '0');
}
