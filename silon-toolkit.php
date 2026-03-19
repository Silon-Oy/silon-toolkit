<?php
/**
 * Plugin Name: Silon Toolkit
 * Description: Shared utility toolkit for Silon Oy WordPress sites.
 * Author: Silon Oy
 * License: Proprietary
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach (glob(__DIR__ . '/*.php') as $file) {
    if ($file === __FILE__) {
        continue;
    }
    require_once $file;
}
