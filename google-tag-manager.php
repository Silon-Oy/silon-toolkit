<?php
/**
 * Outputs Google Tag Manager snippets when a GTM container ID is provided
 * via the GTM_ID environment variable.
 *
 * - The main script is injected as early as possible in <head> via wp_head.
 * - The noscript iframe is injected immediately after <body> via wp_body_open.
 * - For Fluent Forms Conversational Form pages (which render their own
 *   standalone template and never call wp_head/wp_body_open), the main
 *   script is also hooked into fluentform/conversational_frame_head.
 *   The noscript fallback is intentionally omitted there because the
 *   conversational form is a JS-only experience.
 *
 * The GTM_ID is validated against the expected GTM-XXXXXXX format to avoid
 * emitting broken markup or opening an injection vector.
 */

namespace SilonToolkit;

if (!defined('ABSPATH')) {
    exit;
}

class GoogleTagManager
{
    public static function init(): void
    {
        if ( ! function_exists('getenv') || self::get_gtm_id() === null) {
            return;
        }

        add_action('wp_head', [self::class, 'render_head_script'], 1);
        add_action('wp_body_open', [self::class, 'render_body_noscript'], 1);

        // Fluent Forms Conversational Form uses its own standalone template
        // that does not call wp_head(). Hook into its dedicated head action so
        // GTM still loads on those pages. The noscript fallback is skipped
        // because conversational forms require JavaScript to function.
        add_action('fluentform/conversational_frame_head', [self::class, 'render_head_script'], 1);
    }

    public static function render_head_script(): void
    {
        $gtm_id = self::get_gtm_id();

        if ($gtm_id === null) {
            return;
        }
        ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
<!-- End Google Tag Manager -->
        <?php
    }

    public static function render_body_noscript(): void
    {
        $gtm_id = self::get_gtm_id();

        if ($gtm_id === null) {
            return;
        }
        ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="<?php echo esc_url('https://www.googletagmanager.com/ns.html?id=' . $gtm_id); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
        <?php
    }

    private static function get_gtm_id(): ?string
    {
        $gtm_id = getenv('GTM_ID');

        if (!is_string($gtm_id) || $gtm_id === '') {
            return null;
        }

        $gtm_id = trim($gtm_id);

        if (!preg_match('/^GTM-[A-Z0-9]+$/', $gtm_id)) {
            return null;
        }

        return $gtm_id;
    }
}

GoogleTagManager::init();
