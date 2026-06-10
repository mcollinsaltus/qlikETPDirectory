<?php
/**
 * Plugin Name: Qlik Directory
 * Description: Renders editable Qlik directory dashboard assets via shortcode.
 * Author: Qlik, Ken N., Altus
 * Version: 2.0.0
 */

namespace Altus\QlikDirectory;

if (!\defined('ABSPATH')) {
    exit;
}

if (!\defined('ALTUS_QLIK_DIRECTORY_DEFAULT_APP_ID')) {
    \define('ALTUS_QLIK_DIRECTORY_DEFAULT_APP_ID', 'edfe9bc5-be35-4bde-b515-e72fe46b5240');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_DEFAULT_SHEET_ID')) {
    \define('ALTUS_QLIK_DIRECTORY_DEFAULT_SHEET_ID', 'mGLGNCp');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_DEFAULT_HOST')) {
    \define('ALTUS_QLIK_DIRECTORY_DEFAULT_HOST', 'https://3u0ob6mw8hfz2n1.se.qlikcloud.com');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_DEFAULT_CLIENT_ID')) {
    \define('ALTUS_QLIK_DIRECTORY_DEFAULT_CLIENT_ID', '6363572c7d5793f287ee2b9c6183eefa');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_DEFAULT_AUTH_TYPE')) {
    \define('ALTUS_QLIK_DIRECTORY_DEFAULT_AUTH_TYPE', 'anonymous');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_EMERGENCY_HELP_FILE')) {
    \define('ALTUS_QLIK_DIRECTORY_EMERGENCY_HELP_FILE', 'emergency-help-resources.json');
}
if (!\defined('ALTUS_QLIK_DIRECTORY_DEPLOYED_DIRECTORIES_OPTION')) {
    \define('ALTUS_QLIK_DIRECTORY_DEPLOYED_DIRECTORIES_OPTION', 'etp_sdc_deployed_directories');
}

/**
 * Resolve first non-empty attr from key list.
 *
 * @param array $atts Shortcode attributes.
 * @param array $keys Candidate keys.
 * @param string $default Default value.
 * @return string
 */
function qlik_directory_attr(array $atts, array $keys, $default = '') {
    foreach ($keys as $key) {
        if (isset($atts[$key]) && $atts[$key] !== '') {
            return (string) $atts[$key];
        }
    }

    return (string) $default;
}

/**
 * Resolve first non-empty public URL query parameter from key list.
 *
 * @param array $keys Candidate keys.
 * @return string
 */
function qlik_directory_query_attr(array $keys) {
    foreach ($keys as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public display option.
            return (string) wp_unslash($_GET[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public display option.
        }
    }

    return '';
}

/**
 * @param string $key Public directory key.
 * @return string
 */
function qlik_directory_sanitize_public_key($key) {
    return sanitize_key((string) $key);
}

/**
 * Resolve a public directory key to a Qlik app id.
 *
 * @param string $public_key Public directory key.
 * @return string
 */
function qlik_directory_app_id_for_public_key($public_key) {
    $public_key = qlik_directory_sanitize_public_key($public_key);
    if ($public_key === '') {
        return '';
    }

    $directories = get_option(\ALTUS_QLIK_DIRECTORY_DEPLOYED_DIRECTORIES_OPTION, array());
    if (!is_array($directories)) {
        return '';
    }

    foreach ($directories as $stored_key => $directory) {
        if (!is_array($directory)) {
            continue;
        }

        $candidate_key = qlik_directory_sanitize_public_key($directory['public_key'] ?? $stored_key);
        $app_id = sanitize_text_field($directory['app_id'] ?? '');
        if ($candidate_key === $public_key && $app_id !== '') {
            return $app_id;
        }
    }

    return '';
}

/**
 * Resolve the requested app id and detect unknown public keys.
 *
 * @param array $atts Shortcode attributes.
 * @return array
 */
function qlik_directory_resolve_request(array $atts) {
    $directory_key = qlik_directory_sanitize_public_key(qlik_directory_query_attr(array('directory')));
    if ($directory_key !== '') {
        $app_id = qlik_directory_app_id_for_public_key($directory_key);
        if ($app_id === '') {
            return array(
                'app_id' => '',
                'directory_key' => $directory_key,
                'error' => 'unknown_directory',
            );
        }

        return array(
            'app_id' => $app_id,
            'directory_key' => $directory_key,
            'error' => '',
        );
    }

    $url_app_id = sanitize_text_field(qlik_directory_query_attr(array('appId', 'app_id')));
    if ($url_app_id !== '') {
        return array(
            'app_id' => $url_app_id,
            'directory_key' => '',
            'error' => '',
        );
    }

    return array(
        'app_id' => sanitize_text_field(qlik_directory_attr($atts, array('app_id', 'qlik_app_id', 'appId'), \ALTUS_QLIK_DIRECTORY_DEFAULT_APP_ID)),
        'directory_key' => '',
        'error' => '',
    );
}

/**
 * Resolve a theme color from shortcode attrs first, then URL query params.
 *
 * @param array $atts Shortcode attributes.
 * @param array $keys Candidate keys.
 * @return string
 */
function qlik_directory_theme_color(array $atts, array $keys) {
    $value = qlik_directory_attr($atts, $keys, '');
    if ($value === '') {
        $value = qlik_directory_query_attr($keys);
    }

    $value = ltrim(trim((string) $value), '#');
    if (!preg_match('/\A[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\z/', $value)) {
        return '';
    }

    return '#' . strtolower($value);
}

/**
 * Resolve a safe font-family stack from shortcode attrs first, then URL query params.
 *
 * @param array $atts Shortcode attributes.
 * @param array $keys Candidate keys.
 * @return string
 */
function qlik_directory_theme_font(array $atts, array $keys) {
    $value = qlik_directory_attr($atts, $keys, '');
    if ($value === '') {
        $value = qlik_directory_query_attr($keys);
    }

    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 160) {
        return '';
    }

    if (!preg_match('/\A[A-Za-z0-9\s,"\'\-_]+\z/', $value)) {
        return '';
    }

    return $value;
}

/**
 * Build scoped CSS variables for custom directory themes.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function qlik_directory_theme_style(array $atts) {
    $theme_keys = array(
        array('--ink', array('text', 'ink', 'theme_text', 'theme_ink')),
        array('--muted', array('muted', 'theme_muted')),
        array('--line', array('line', 'theme_line')),
        array('--paper', array('paper', 'theme_paper')),
        array('--surface', array('surface', 'theme_surface')),
        array('--teal', array('primary', 'teal', 'theme_primary', 'theme_teal')),
        array('--teal-dark', array('primary_dark', 'teal_dark', 'theme_primary_dark', 'theme_teal_dark')),
        array('--teal-soft', array('primary_soft', 'teal_soft', 'theme_primary_soft', 'theme_teal_soft')),
        array('--blue', array('secondary', 'blue', 'theme_secondary', 'theme_blue')),
        array('--blue-soft', array('secondary_soft', 'blue_soft', 'theme_secondary_soft', 'theme_blue_soft')),
        array('--gold', array('accent', 'gold', 'theme_accent', 'theme_gold')),
        array('--gold-soft', array('accent_soft', 'gold_soft', 'theme_accent_soft', 'theme_gold_soft')),
        array('--green', array('success', 'green', 'theme_success', 'theme_green')),
        array('--green-soft', array('success_soft', 'green_soft', 'theme_success_soft', 'theme_green_soft')),
        array('--coral', array('danger', 'coral', 'theme_danger', 'theme_coral')),
    );

    $style = array();
    foreach ($theme_keys as $config) {
        list($css_var, $keys) = $config;
        $color = qlik_directory_theme_color($atts, $keys);
        if ($color !== '') {
            $style[] = $css_var . ':' . $color;
        }
    }

    $font = qlik_directory_theme_font($atts, array('font_family', 'fontFamily', 'theme_font_family'));
    if ($font !== '') {
        $style[] = '--directory-font:' . $font;
    }

    $heading_font = qlik_directory_theme_font($atts, array('heading_font', 'headingFont', 'theme_heading_font'));
    if ($heading_font !== '') {
        $style[] = '--directory-heading-font:' . $heading_font;
    }

    return implode(';', $style);
}

/**
 * @param string $directory_key Public directory key.
 * @return string
 */
function qlik_directory_unknown_directory_markup($directory_key) {
    return sprintf(
        '<div class="qlik-directory-dashboard"><div class="qlik-directory-error" role="status" style="max-width:760px;margin:24px auto;padding:16px 18px;border:1px solid #f1beb6;border-radius:8px;color:#7c2e22;background:#fff6f4;"><strong>Directory unavailable.</strong><br>The requested directory key <code>%s</code> is not configured.</div></div>',
        esc_html($directory_key)
    );
}

/**
 * Default emergency help URL.
 * Primary: plugin-local JSON (best for SFTP maintainability).
 * Fallback: site root JSON.
 *
 * @return string
 */
function qlik_directory_default_emergency_help_url() {
    $plugin_file_path = plugin_dir_path(__FILE__) . \ALTUS_QLIK_DIRECTORY_EMERGENCY_HELP_FILE;
    if (is_readable($plugin_file_path)) {
        return plugins_url(\ALTUS_QLIK_DIRECTORY_EMERGENCY_HELP_FILE, __FILE__);
    }

    return home_url('/' . \ALTUS_QLIK_DIRECTORY_EMERGENCY_HELP_FILE);
}

/**
 * Load editable dashboard markup from file.
 *
 * @param string $app_id App ID.
 * @param string $sheet_id Sheet ID.
 * @return string
 */
function qlik_directory_dashboard_markup($app_id, $sheet_id) {
    $markup_path = plugin_dir_path(__FILE__) . 'qlik-dashboard-markup.html';
    if (!is_readable($markup_path)) {
        return '<div class="qlik-directory-error">Dashboard markup file missing.</div>';
    }

    $markup = file_get_contents($markup_path);
    if ($markup === false) {
        return '<div class="qlik-directory-error">Unable to load dashboard markup.</div>';
    }

    $replacement = sprintf(
        'id="qlikEmbedContainer" data-app-id="%s" data-sheet-id="%s" style="display:none"',
        esc_attr($app_id),
        esc_attr($sheet_id)
    );

    $markup = preg_replace('/id="qlikEmbedContainer"\s+style="display:none"/', $replacement, $markup, 1);
    if ($markup === null) {
        $markup = '';
    }

    return (string) apply_filters('qlik_directory_dashboard_markup', $markup, $app_id, $sheet_id);
}

/**
 * Shortcode: [qlik_directory_dashboard]
 */
function qlik_directory_dashboard_shortcode($atts = array()) {
    $defaults = array(
        'app_id' => getenv('QLIK_APP_ID') ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_APP_ID,
        'sheet_id' => getenv('QLIK_SHEET_ID') ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_SHEET_ID,
        'host' => getenv('QLIK_EMBED_HOST') ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_HOST,
        'client_id' => getenv('QLIK_EMBED_CLIENT_ID') ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_CLIENT_ID,
        'access_code' => getenv('QLIK_EMBED_ACCESS_CODE') ?: '',
        'auth_type' => getenv('QLIK_EMBED_AUTH_TYPE') ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_AUTH_TYPE,
        'emergency_help_url' => qlik_directory_default_emergency_help_url(),
        // Aliases kept for backward compatibility.
        'qlik_app_id' => '',
        'qlik_sheet_id' => '',
        'qlik_host' => '',
        'qlik_client_id' => '',
        'qlik_access_code' => '',
        'qlik_auth_type' => '',
        'appId' => '',
        'sheetId' => '',
        'clientId' => '',
        'accessCode' => '',
        'authType' => '',
        'emergencyHelpUrl' => '',
        'text' => '',
        'ink' => '',
        'muted' => '',
        'line' => '',
        'paper' => '',
        'surface' => '',
        'primary' => '',
        'primary_dark' => '',
        'primary_soft' => '',
        'secondary' => '',
        'secondary_soft' => '',
        'accent' => '',
        'accent_soft' => '',
        'success' => '',
        'success_soft' => '',
        'danger' => '',
        'font_family' => '',
        'heading_font' => '',
    );

    $atts = shortcode_atts($defaults, $atts, 'qlik_directory_dashboard');

    $request = qlik_directory_resolve_request($atts);
    if ($request['error'] === 'unknown_directory') {
        return qlik_directory_unknown_directory_markup($request['directory_key']);
    }

    $app_id = $request['app_id'];
    $sheet_id = sanitize_text_field(qlik_directory_attr($atts, array('sheet_id', 'qlik_sheet_id', 'sheetId'), \ALTUS_QLIK_DIRECTORY_DEFAULT_SHEET_ID));
    $host = esc_url_raw(qlik_directory_attr($atts, array('host', 'qlik_host'), \ALTUS_QLIK_DIRECTORY_DEFAULT_HOST));
    $client_id = sanitize_text_field(qlik_directory_attr($atts, array('client_id', 'qlik_client_id', 'clientId'), \ALTUS_QLIK_DIRECTORY_DEFAULT_CLIENT_ID));
    $access_code = sanitize_text_field(qlik_directory_attr($atts, array('access_code', 'qlik_access_code', 'accessCode'), ''));
    $auth_type = sanitize_key(qlik_directory_attr($atts, array('auth_type', 'qlik_auth_type', 'authType'), \ALTUS_QLIK_DIRECTORY_DEFAULT_AUTH_TYPE));
    $emergency_help_url = esc_url_raw(qlik_directory_attr($atts, array('emergency_help_url', 'emergencyHelpUrl'), qlik_directory_default_emergency_help_url()));
    $theme_style = qlik_directory_theme_style($atts);

    wp_enqueue_style(
        'qlik-directory-leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        array(),
        '1.9.4'
    );

    wp_enqueue_style(
        'qlik-directory-dashboard-style',
        plugins_url('qlik-dashboard.css', __FILE__),
        array('qlik-directory-leaflet'),
        filemtime(plugin_dir_path(__FILE__) . 'qlik-dashboard.css')
    );

    wp_enqueue_script(
        'qlik-directory-leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        array(),
        '1.9.4',
        true
    );

    wp_enqueue_script(
        'qlik-directory-module',
        plugins_url('qlik-module.js', __FILE__),
        array('qlik-directory-leaflet'),
        filemtime(plugin_dir_path(__FILE__) . 'qlik-module.js'),
        true
    );

    $qlik_attrs = array(
        'crossorigin="anonymous"',
        'type="application/javascript"',
        'src="https://cdn.jsdelivr.net/npm/@qlik/embed-web-components@1/dist/index.min.js"',
        'data-host="' . esc_attr($host) . '"',
        'data-client-id="' . esc_attr($client_id) . '"',
        'data-auth-type="' . esc_attr($auth_type ?: \ALTUS_QLIK_DIRECTORY_DEFAULT_AUTH_TYPE) . '"',
    );

    if ($access_code !== '') {
        $qlik_attrs[] = 'data-access-code="' . esc_attr($access_code) . '"';
    }

    $html = qlik_directory_dashboard_markup($app_id, $sheet_id);

    static $qlik_embed_bootstrap_printed = false;

    ob_start();
    if (!$qlik_embed_bootstrap_printed) {
        ?>
        <script <?php echo implode(' ', $qlik_attrs); ?>></script>
        <?php
        $qlik_embed_bootstrap_printed = true;
    }
    ?>
    <div class="qlik-directory-dashboard" data-app-id="<?php echo esc_attr($app_id); ?>" data-sheet-id="<?php echo esc_attr($sheet_id); ?>" data-emergency-help-url="<?php echo esc_attr($emergency_help_url); ?>"<?php echo $theme_style !== '' ? ' style="' . esc_attr($theme_style) . '"' : ''; ?>>
        <?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static local template. ?>
    </div>
    <?php

    return (string) ob_get_clean();
}
\add_shortcode('qlik_directory_dashboard', __NAMESPACE__ . '\\qlik_directory_dashboard_shortcode');
