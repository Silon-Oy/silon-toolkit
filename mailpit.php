<?php
/**
 * Routes all outgoing mail through Mailpit SMTP in the development environment.
 * Mailpit catches emails locally at http://localhost:8025.
 */

namespace SilonToolkit;

if (!defined('ABSPATH')) {
    exit;
}

class Mailpit
{
    private const SMTP_HOST = '127.0.0.1';

    private const SMTP_PORT = 1025;

    public static function init(): void
    {
        if (wp_get_environment_type() !== 'development') {
            return;
        }

        add_action('phpmailer_init', [self::class, 'configure_phpmailer']);
    }

    public static function configure_phpmailer($phpmailer): void
    {
        $phpmailer->isSMTP();
        $phpmailer->Host = self::SMTP_HOST;
        $phpmailer->Port = self::SMTP_PORT;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
    }
}

Mailpit::init();
