<?php
/**
 * Routes all outgoing mail through Mailpit SMTP in the development environment.
 * Mailpit catches emails locally at http://localhost:8025.
 *
 * FluentSMTP sets its own relay on PHPMailer after phpmailer_init has run, so a
 * site whose database carries production FluentSMTP settings would send real
 * mail from a local copy. Its connections are therefore rewritten to Mailpit
 * through an option filter; the stored settings are never touched.
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
        add_filter('option_fluentmail-settings', [self::class, 'route_fluent_smtp']);
    }

    public static function configure_phpmailer($phpmailer): void
    {
        $phpmailer->isSMTP();
        $phpmailer->Host = self::SMTP_HOST;
        $phpmailer->Port = self::SMTP_PORT;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
    }

    /**
     * Points every FluentSMTP connection at Mailpit, whatever its provider.
     *
     * An API provider (SendGrid, SES, …) would bypass SMTP altogether, so each
     * connection becomes a plain SMTP one. The sender address is kept so mail
     * still shows the From the site would use.
     *
     * @param mixed $settings The stored fluentmail-settings value.
     * @return mixed
     */
    public static function route_fluent_smtp($settings)
    {
        if (!is_array($settings) || empty($settings['connections']) || !is_array($settings['connections'])) {
            return $settings;
        }

        foreach ($settings['connections'] as $key => $connection) {
            $provider = isset($connection['provider_settings']) && is_array($connection['provider_settings'])
                ? $connection['provider_settings']
                : [];

            $settings['connections'][$key]['provider_settings'] = [
                'provider'           => 'smtp',
                'sender_name'        => $provider['sender_name'] ?? '',
                'sender_email'       => $provider['sender_email'] ?? '',
                'force_from_name'    => $provider['force_from_name'] ?? 'no',
                'force_from_email'   => $provider['force_from_email'] ?? 'yes',
                'return_path'        => $provider['return_path'] ?? 'yes',
                'host'               => self::SMTP_HOST,
                'port'               => self::SMTP_PORT,
                'auth'               => 'no',
                'username'           => '',
                'password'           => '',
                'encryption'         => 'none',
                'auto_tls'           => 'no',
                'key_store'          => 'db',
                'disable_encryption' => 'yes',
            ];
        }

        return $settings;
    }
}

Mailpit::init();
