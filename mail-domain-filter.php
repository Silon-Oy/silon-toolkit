<?php
/**
 * Replaces development email domains (.test, .local, .localhost, .example, .invalid, .ddev)
 * with the production domain in outgoing mail. Active only in production environment.
 */

namespace SilonToolkit;

if (!defined('ABSPATH')) {
    exit;
}

class MailDomainFilter
{
    private const DEV_TLDS = ['test', 'local', 'localhost', 'example', 'invalid', 'ddev'];

    public static function init(): void
    {
        if (wp_get_environment_type() !== 'production') {
            return;
        }

        add_filter('wp_mail', [self::class, 'filter_wp_mail']);
    }

    /**
     * @param array $args wp_mail arguments (to, subject, message, headers, attachments).
     * @return array Modified arguments.
     */
    public static function filter_wp_mail(array $args): array
    {
        $args['to'] = self::replace_in_recipients($args['to']);

        if (!empty($args['headers'])) {
            $args['headers'] = self::replace_in_headers($args['headers']);
        }

        return $args;
    }

    /**
     * @param string|string[] $to
     * @return string|string[]
     */
    private static function replace_in_recipients($to)
    {
        if (is_array($to)) {
            return array_map([self::class, 'replace_dev_domain'], $to);
        }

        return self::replace_dev_domain($to);
    }

    /**
     * @param string|string[] $headers
     * @return string|string[]
     */
    private static function replace_in_headers($headers)
    {
        if (is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        return array_map(function (string $header): string {
            if (preg_match('/^(To|Cc|Bcc)\s*:/i', $header)) {
                return self::replace_dev_domain($header);
            }
            return $header;
        }, $headers);
    }

    private static function replace_dev_domain(string $value): string
    {
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $tlds = implode('|', array_map('preg_quote', self::DEV_TLDS));

        return preg_replace(
            '/([a-zA-Z0-9._%+\-]+)@[\w.\-]+\.(?:' . $tlds . ')\b/',
            '$1@' . $domain,
            $value
        );
    }
}

MailDomainFilter::init();
