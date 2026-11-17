<?php
/**
 * Person-level website tracking with PostHog, gated on marketing consent, plus the
 * identification that ties a visitor's history to a CRM contact.
 *
 * - PostHog loads only when the WP Consent API category `marketing` is allowed
 *   (wp_has_consent() or the wp_consent_marketing cookie) and stops capturing when
 *   consent is withdrawn. Marketing, not statistics: the history is tied to a named person.
 * - A newsletter link carries `?mb=<token>`. The parameter is removed from the address
 *   bar at once; with consent, the visitor is identified with it.
 * - On a Fluent Forms or Contact Form 7 submission the browser's PostHog id travels in two
 *   hidden fields. The server computes the token from the submitted email and aliases the
 *   anonymous id to it, so the earlier anonymous visits join the person. The hidden fields
 *   are never stored: Fluent Forms strips them before the entry is saved, and Contact Form 7
 *   leaves underscore-prefixed fields out of the posted data, its mail and Flamingo.
 *
 * The token is the first 32 hex chars of HMAC-SHA256(MB_TOKEN_SECRET, normalised email).
 * Silon's marketing bridge (Silon-Oy/silon-marketing-bridge) computes the same value;
 * both sides test the vector in token() below.
 *
 * Environment: POSTHOG_PROJECT_KEY (enables tracking), POSTHOG_HOST (ingestion host,
 * default EU Cloud), MB_TOKEN_SECRET (enables form identification). This repo is
 * public, so none of these may be committed.
 */

namespace SilonToolkit;

if (!defined('ABSPATH')) {
    exit;
}

class PostHogTracking
{
    private const DEFAULT_HOST = 'https://eu.i.posthog.com';
    private const FIELD_ID = '_mb_ph_id';
    private const FIELD_STATE = '_mb_ph_state';
    private const CAPTURE_TIMEOUT = 3;

    /** Forms whose submissions carry the hidden fields: Fluent Forms and Contact Form 7. */
    private const FORM_SELECTOR = '.frm-fluent-form, .wpcf7-form';

    /** @var array{id?: string, state?: string} Hidden field values of the submission in progress. */
    private static array $pending = [];

    public static function init(): void
    {
        if (self::get_project_key() === null) {
            return;
        }

        add_action('wp_head', [self::class, 'render_script'], 2);
        add_action('fluentform/conversational_frame_head', [self::class, 'render_script'], 2);

        if (self::get_secret() !== null) {
            add_filter('fluentform/white_listed_fields', [self::class, 'allow_hidden_fields']);
            add_filter('fluentform/insert_response_data', [self::class, 'take_hidden_fields'], 5);
            add_action('fluentform/submission_inserted', [self::class, 'identify_submitter'], 10, 2);
            add_action('wpcf7_mail_sent', [self::class, 'identify_cf7_submitter']);
        }
    }

    /**
     * Same rule as the bridge: token for an email, or null if the input is not an email.
     * Vector: token('matti@example.fi', 's3cret') === '60c9f972039f3f8ffba737c6eef2b7d5'.
     */
    public static function token(string $email, string $secret): ?string
    {
        $normalised = strtolower(trim($email));

        if ($secret === '' || !is_email($normalised)) {
            return null;
        }

        return substr(hash_hmac('sha256', $normalised, $secret), 0, 32);
    }

    public static function render_script(): void
    {
        $config = [
            'key' => self::get_project_key(),
            'host' => self::get_host(),
            'uiHost' => str_replace('.i.posthog.com', '.posthog.com', self::get_host()),
            'fieldId' => self::FIELD_ID,
            'fieldState' => self::FIELD_STATE,
            'forms' => self::FORM_SELECTOR,
        ];

        wp_print_inline_script_tag(
            'window.silonPostHog=' . wp_json_encode($config) . ';' . self::script(),
            ['id' => 'silon-posthog']
        );
    }

    /**
     * @param string[] $fields
     * @return string[]
     */
    public static function allow_hidden_fields($fields): array
    {
        return array_merge((array) $fields, [self::FIELD_ID, self::FIELD_STATE]);
    }

    /**
     * Keeps the hidden values for identify_submitter() and removes them from the stored entry.
     *
     * @param array<string,mixed> $form_data
     * @return array<string,mixed>
     */
    public static function take_hidden_fields($form_data): array
    {
        $form_data = (array) $form_data;
        self::$pending = [
            'id' => is_string($form_data[self::FIELD_ID] ?? null) ? $form_data[self::FIELD_ID] : '',
            'state' => is_string($form_data[self::FIELD_STATE] ?? null) ? $form_data[self::FIELD_STATE] : '',
        ];
        unset($form_data[self::FIELD_ID], $form_data[self::FIELD_STATE]);

        return $form_data;
    }

    /**
     * Fluent Forms: identifies the submitter of a stored entry.
     *
     * @param int|string          $insert_id
     * @param array<string,mixed> $form_data
     */
    public static function identify_submitter($insert_id, $form_data): void
    {
        $pending = self::$pending;
        self::$pending = [];

        self::identify(
            $pending['id'] ?? '',
            $pending['state'] ?? '',
            static fn (): ?string => self::find_email((array) $form_data),
            'Fluent Forms entry ' . $insert_id
        );
    }

    /**
     * Contact Form 7: identifies the submitter once the form's mail has been sent. The hidden
     * fields are read from the request because Contact Form 7 leaves underscore-prefixed
     * fields out of its posted data.
     *
     * @param \WPCF7_ContactForm $contact_form
     */
    public static function identify_cf7_submitter($contact_form): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Contact Form 7 has validated the submission before wpcf7_mail_sent.
        $anonymous_id = is_string($_POST[self::FIELD_ID] ?? null) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_ID])) : '';
        $state = is_string($_POST[self::FIELD_STATE] ?? null) ? sanitize_text_field(wp_unslash($_POST[self::FIELD_STATE])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        self::identify(
            $anonymous_id,
            $state,
            static fn (): ?string => self::find_cf7_email($contact_form),
            'Contact Form 7 form ' . (is_object($contact_form) && method_exists($contact_form, 'id') ? $contact_form->id() : '?')
        );
    }

    /**
     * Aliases the browser's anonymous PostHog id to the submitter's token. Without the hidden
     * id there was no consent (or PostHog never loaded), and nothing is sent. Shared by every
     * form plugin; never breaks the submission.
     *
     * @param callable(): ?string $find_email Resolves the submitter's email, called only for an anonymous browser.
     */
    private static function identify(string $anonymous_id, string $state, callable $find_email, string $source): void
    {
        try {
            // Only an anonymous browser is merged: PostHog cannot merge two identified persons.
            if ($state !== 'anonymous' || !preg_match('/^[A-Za-z0-9._:\-]{1,200}$/', $anonymous_id)) {
                return;
            }

            $email = $find_email();
            $token = $email !== null ? self::token($email, (string) self::get_secret()) : null;

            if ($token === null || $token === $anonymous_id) {
                return;
            }

            $response = wp_remote_post(self::get_host() . '/i/v0/e/', [
                'timeout' => self::CAPTURE_TIMEOUT,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'api_key' => self::get_project_key(),
                    'event' => '$identify',
                    'distinct_id' => $token,
                    'properties' => [
                        '$anon_distinct_id' => $anonymous_id,
                        '$set' => ['email' => strtolower(trim($email))],
                    ],
                ]),
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
                error_log('SilonToolkit PostHogTracking: identify failed for ' . $source);
            }
        } catch (\Throwable $e) {
            error_log('SilonToolkit PostHogTracking: ' . $e->getMessage());
        }
    }

    /**
     * The submitter's email: a field named `email` first, otherwise the first email-shaped value.
     *
     * @param array<string,mixed> $form_data
     */
    private static function find_email(array $form_data): ?string
    {
        if (is_string($form_data['email'] ?? null) && is_email(trim($form_data['email']))) {
            return trim($form_data['email']);
        }

        foreach ($form_data as $value) {
            foreach ((array) $value as $candidate) {
                if (is_string($candidate) && is_email(trim($candidate))) {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    /**
     * The submitter's email in a Contact Form 7 submission: the first email-type field that holds
     * an email, otherwise the first email-shaped value. Field names differ from form to form.
     *
     * @param \WPCF7_ContactForm $contact_form
     */
    private static function find_cf7_email($contact_form): ?string
    {
        if (!class_exists('WPCF7_Submission')) {
            return null;
        }

        $submission = \WPCF7_Submission::get_instance();

        if ($submission === null) {
            return null;
        }

        $tags = is_object($contact_form) && method_exists($contact_form, 'scan_form_tags')
            ? $contact_form->scan_form_tags(['basetype' => ['email']])
            : [];

        foreach ($tags as $tag) {
            $value = $tag->name !== '' ? $submission->get_posted_data($tag->name) : null;

            if (is_string($value) && is_email(trim($value))) {
                return trim($value);
            }
        }

        return self::find_email((array) $submission->get_posted_data());
    }

    private static function script(): string
    {
        // PostHog's official loader stub (posthog.com/docs/libraries/js, 2026-10).
        $stub = '!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],Object.defineProperty(u,"toString",{configurable:!0,enumerable:!0,writable:!0,value:function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e}}),Object.defineProperty(u.people,"toString",{configurable:!0,enumerable:!0,writable:!0,value:function(){return u.toString(1)+".people (stub)"}}),o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagResult isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);';

        return <<<JS
(function () {
  var cfg = window.silonPostHog, loaded = false, linkToken = null;

  // Remove the newsletter token from the address bar before anything records the URL.
  try {
    var url = new URL(window.location.href);
    if (url.searchParams.has('mb')) {
      var t = url.searchParams.get('mb');
      linkToken = /^[0-9a-f]{32}$/.test(t) ? t : null;
      url.searchParams.delete('mb');
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    }
  } catch (e) {}

  function hasConsent() {
    if (typeof window.wp_has_consent === 'function') {
      return window.wp_has_consent('marketing');
    }
    return /(?:^|; )wp_consent_marketing=allow(?:;|$)/.test(document.cookie);
  }

  function setField(form, name, value) {
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    input.value = value || '';
  }

  function load() {
    if (loaded) {
      if (window.posthog.has_opted_out_capturing()) { window.posthog.opt_in_capturing(); }
      return;
    }
    loaded = true;
    {$stub}
    window.posthog.init(cfg.key, {
      api_host: cfg.host,
      ui_host: cfg.uiHost,
      defaults: '2026-08-30',
      person_profiles: 'always',
      disable_session_recording: true,
      disable_surveys: true,
      loaded: function (ph) { if (linkToken) { ph.identify(linkToken); } }
    });
    // Capture phase: runs before the form plugin serialises the form for its AJAX submit.
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || typeof form.matches !== 'function' || !form.matches(cfg.forms)) { return; }
      var id = window.posthog.get_distinct_id && window.posthog.get_distinct_id();
      if (typeof id !== 'string' || window.posthog.has_opted_out_capturing()) { return; }
      setField(form, cfg.fieldId, id);
      setField(form, cfg.fieldState, window.posthog.get_property('\$user_state'));
    }, true);
  }

  document.addEventListener('wp_listen_for_consent_change', function (event) {
    var value = event.detail && event.detail.marketing;
    if (value === 'allow') { load(); }
    if (value === 'deny' && loaded) { window.posthog.opt_out_capturing(); }
  });

  if (hasConsent()) { load(); }
})();
JS;
    }

    private static function get_project_key(): ?string
    {
        $key = self::env('POSTHOG_PROJECT_KEY');

        return $key !== null && preg_match('/^phc_[A-Za-z0-9]+$/', $key) ? $key : null;
    }

    private static function get_host(): string
    {
        $host = esc_url_raw((string) self::env('POSTHOG_HOST'), ['https']);

        return rtrim($host !== '' ? $host : self::DEFAULT_HOST, '/');
    }

    private static function get_secret(): ?string
    {
        return self::env('MB_TOKEN_SECRET');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

PostHogTracking::init();
