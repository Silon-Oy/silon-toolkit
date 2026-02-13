<?php
/**
 * Disables the WordPress theme and plugin file editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}
