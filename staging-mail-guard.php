<?php
/**
 * Keeps a staging site from mailing real people.
 *
 * A staging site usually runs on a copy of the production database, so it carries
 * real customer addresses and the production mail relay settings. In the staging
 * environment every To, Cc and Bcc recipient outside the allowed domains is dropped,
 * and a message left with no To recipient is not sent at all. The caller is told the
 * message went out, so a plugin that retries failed mail does not start a retry loop.
 *
 * Allowed domains come from the STAGING_MAIL_ALLOWED_DOMAINS environment variable as a
 * comma-separated list (default: silon.fi). Subdomains of an allowed domain are allowed.
 * Every dropped recipient is logged as a JSON line with its domain only.
 */

namespace SilonToolkit;

if (!defined('ABSPATH')) {
    exit;
}

class StagingMailGuard
{
    private const DEFAULT_ALLOWED_DOMAINS = 'silon.fi';

    private const RECIPIENT_HEADERS = ['cc', 'bcc'];

    /**
     * Set when the last filtered message lost every To recipient.
     */
    private static bool $blocked = false;

    public static function init(): void
    {
        if (wp_get_environment_type() !== 'staging') {
            return;
        }

        // Last in line, so recipients added by other filters are checked too.
        add_filter('wp_mail', [self::class, 'filter_recipients'], PHP_INT_MAX);
        add_filter('pre_wp_mail', [self::class, 'stop_blocked_message'], PHP_INT_MAX);
    }

    /**
     * Drops every recipient outside the allowed domains.
     *
     * @param array $args wp_mail arguments (to, subject, message, headers, attachments).
     * @return array
     */
    public static function filter_recipients(array $args): array
    {
        $dropped = [];

        $to = self::split_allowed(self::addresses($args['to'] ?? ''), $dropped);
        $args['to'] = $to;

        // A message that had no To recipient to begin with fails as it would anywhere.
        self::$blocked = $to === [] && $dropped !== [];

        if (!empty($args['headers'])) {
            $args['headers'] = self::filter_headers($args['headers'], $dropped);
        }

        if ($dropped !== []) {
            self::log($dropped, self::$blocked);
        }

        return $args;
    }

    /**
     * Reports a message without recipients as sent instead of sending it.
     *
     * @param null|bool $result Short-circuit value from earlier filters.
     * @return null|bool
     */
    public static function stop_blocked_message($result)
    {
        $blocked = self::$blocked;
        self::$blocked = false;

        if ($result !== null) {
            return $result;
        }

        return $blocked ? true : null;
    }

    /**
     * Whether an address belongs to an allowed domain or its subdomain.
     */
    public static function is_allowed(string $address): bool
    {
        $domain = self::domain($address);

        if ($domain === '') {
            return false;
        }

        foreach (self::allowed_domains() as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function allowed_domains(): array
    {
        $value = getenv('STAGING_MAIL_ALLOWED_DOMAINS');

        if (!is_string($value) || trim($value) === '') {
            $value = self::DEFAULT_ALLOWED_DOMAINS;
        }

        $domains = array_map(
            fn (string $domain): string => strtolower(trim($domain, " \t.@")),
            explode(',', $value)
        );

        return array_values(array_filter($domains));
    }

    /**
     * Every email address in a recipient list, display names left out.
     *
     * Extracting addresses instead of splitting on commas keeps a quoted display
     * name holding a comma from turning into a bogus recipient.
     *
     * @param string|string[] $list
     * @return string[]
     */
    private static function addresses($list): array
    {
        if (is_array($list)) {
            $list = implode(',', array_map('strval', $list));
        }

        preg_match_all('/[^\s<>,;"\']+@[^\s<>,;"\']+/', (string) $list, $matches);

        return $matches[0];
    }

    /**
     * @param string[] $addresses
     * @param string[] $dropped Collects the dropped addresses.
     * @return string[] The allowed addresses.
     */
    private static function split_allowed(array $addresses, array &$dropped): array
    {
        $allowed = [];

        foreach ($addresses as $address) {
            if (self::is_allowed($address)) {
                $allowed[] = $address;
            } else {
                $dropped[] = $address;
            }
        }

        return $allowed;
    }

    /**
     * Filters the addresses on Cc and Bcc header lines and drops emptied lines.
     *
     * @param string|string[] $headers
     * @param string[] $dropped Collects the dropped addresses.
     * @return string|string[]
     */
    private static function filter_headers($headers, array &$dropped)
    {
        $is_string = !is_array($headers);
        $lines = $is_string ? explode("\n", str_replace("\r\n", "\n", (string) $headers)) : $headers;
        $kept = [];

        foreach ($lines as $line) {
            $parts = explode(':', (string) $line, 2);
            $name = strtolower(trim($parts[0]));

            if (count($parts) < 2 || !in_array($name, self::RECIPIENT_HEADERS, true)) {
                $kept[] = $line;
                continue;
            }

            $allowed = self::split_allowed(self::addresses($parts[1]), $dropped);

            if ($allowed !== []) {
                $kept[] = trim($parts[0]) . ': ' . implode(', ', $allowed);
            }
        }

        return $is_string ? implode("\n", $kept) : $kept;
    }

    private static function domain(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    /**
     * @param string[] $dropped
     */
    private static function log(array $dropped, bool $blocked): void
    {
        $domains = array_values(array_unique(array_map([self::class, 'domain'], $dropped)));

        error_log((string) wp_json_encode([
            'ts'       => gmdate('c'),
            'level'    => 'info',
            'source'   => 'silon-toolkit/staging-mail-guard',
            'message'  => $blocked ? 'staging mail blocked' : 'staging mail recipients dropped',
            'dropped'  => count($dropped),
            'domains'  => $domains,
        ], JSON_UNESCAPED_SLASHES));
    }
}

StagingMailGuard::init();
