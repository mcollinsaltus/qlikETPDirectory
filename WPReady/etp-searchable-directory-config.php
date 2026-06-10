<?php
/**
 * ETP Searchable Directory Config module.
 *
 * Loaded by the main Qlik Directory plugin.
 */

namespace ETP\SearchableDirectoryConfig;

if (!\defined('ABSPATH')) {
    exit;
}

const CAPABILITY = 'manage_etp_searchable_directory_config';
const ROLE_KEY = 'etp_searchable_directory_admin';
const ROLE_NAME = 'ETP Searchable Directory Admin';
const OPTION_KEY = 'etp_sdc_emergency_help_resources';
const EMBED_BASE_URL_OPTION = 'etp_sdc_embed_base_url';
const DEPLOYED_DIRECTORIES_OPTION = 'etp_sdc_deployed_directories';
const MENU_SLUG = 'etp-searchable-directory-config';
const EMERGENCY_HELP_SLUG = 'etp-searchable-directory-config';
const JSON_STATUS_SLUG = 'etp-searchable-directory-config-json-status';
const DEPLOYED_DIRECTORIES_SLUG = 'etp-searchable-directory-config-directories';
const IFRAME_LINKS_SLUG = 'etp-searchable-directory-config-iframes';
const ACCESS_SETTINGS_SLUG = 'etp-searchable-directory-config-access';
const UPLOAD_SUBDIR = 'directory-config';
const JSON_FILENAME = 'emergency-help-resources.json';

/**
 * Create the custom role and seed the initial option.
 */
function activate() {
    \add_role(
        ROLE_KEY,
        ROLE_NAME,
        array(
            'read' => true,
            CAPABILITY => true,
        )
    );

    $role = \get_role(ROLE_KEY);
    if ($role && !$role->has_cap(CAPABILITY)) {
        $role->add_cap(CAPABILITY);
    }

    $administrator = \get_role('administrator');
    if ($administrator && !$administrator->has_cap(CAPABILITY)) {
        $administrator->add_cap(CAPABILITY);
    }

    if (\get_option(OPTION_KEY, null) === null) {
        \update_option(OPTION_KEY, default_config(), false);
        publish_json(default_config());
    }
}

/**
 * Keep role capabilities available after plugin updates.
 */
function ensure_capabilities() {
    $role = \get_role(ROLE_KEY);
    if ($role && !$role->has_cap(CAPABILITY)) {
        $role->add_cap(CAPABILITY);
    }

    $administrator = \get_role('administrator');
    if ($administrator && !$administrator->has_cap(CAPABILITY)) {
        $administrator->add_cap(CAPABILITY);
    }
}
\add_action('admin_init', __NAMESPACE__ . '\\ensure_capabilities');

/**
 * Default configuration used when no imported JSON exists yet.
 *
 * @return array
 */
function default_config() {
    $default = array(
        'heading' => 'Emergency help resources',
        'intro' => 'If anyone is in immediate danger, always call 911 first.',
        'sections' => array(
            array(
                'title' => 'Non-state-specific 911 information',
                'description' => 'For immediate danger or an urgent medical, fire, or police emergency, call 911.',
                'items' => array(
                    array(
                        'label' => 'Phone',
                        'text' => '911',
                        'href' => 'tel:911',
                    ),
                ),
            ),
            array(
                'title' => 'National Human Trafficking Hotline',
                'description' => 'This provides nationwide support and can connect victims with local services.',
                'items' => array(
                    array(
                        'label' => 'Phone',
                        'text' => '1-888-373-7888',
                        'href' => 'tel:18883737888',
                    ),
                    array(
                        'label' => 'Text',
                        'text' => 'Text "HELP" or "INFO" to 233733 (BEFREE)',
                    ),
                    array(
                        'label' => 'Live Chat',
                        'text' => 'humantraffickinghotline.org',
                        'href' => 'https://humantraffickinghotline.org',
                    ),
                ),
            ),
        ),
    );

    $seed_path = \plugin_dir_path(__FILE__) . JSON_FILENAME;
    if (\is_readable($seed_path)) {
        $contents = \file_get_contents($seed_path);
        $decoded = \json_decode((string) $contents, true);
        if (\is_array($decoded)) {
            return config_from_public_json($decoded);
        }
    }

    return array(
        'default' => $default,
        'resources' => array(),
    );
}

/**
 * Normalize imported public JSON into the internal storage shape.
 *
 * @param array $json Public JSON.
 * @return array
 */
function config_from_public_json(array $json) {
    $config = array(
        'default' => sanitize_resource($json['default'] ?? array()),
        'resources' => array(),
    );

    if (empty($config['default']['heading'])) {
        $config['default'] = default_config()['default'];
    }

    foreach (($json['resources'] ?? array()) as $app_id => $resource) {
        $clean_app_id = sanitize_app_id((string) $app_id);
        if ($clean_app_id === '') {
            continue;
        }

        $clean_resource = sanitize_resource(\is_array($resource) ? $resource : array());
        $clean_resource['label'] = $clean_resource['label'] ?? derive_label($clean_resource, $clean_app_id);
        $config['resources'][$clean_app_id] = $clean_resource;
    }

    return $config;
}

/**
 * @return array
 */
function get_config() {
    $config = \get_option(OPTION_KEY, array());
    if (!\is_array($config) || empty($config['default'])) {
        $config = default_config();
    }
    if (!isset($config['resources']) || !\is_array($config['resources'])) {
        $config['resources'] = array();
    }

    return $config;
}

/**
 * @param array $resource Resource data.
 * @param string $app_id App ID.
 * @return string
 */
function derive_label(array $resource, $app_id) {
    if (!empty($resource['label'])) {
        return (string) $resource['label'];
    }

    foreach (($resource['sections'] ?? array()) as $section) {
        if (!empty($section['title'])) {
            return (string) $section['title'];
        }
    }

    return $app_id === 'default' ? 'Default fallback' : 'App ' . \substr($app_id, 0, 8);
}

/**
 * @param string $app_id App ID.
 * @return string
 */
function sanitize_app_id($app_id) {
    return \sanitize_text_field(\trim($app_id));
}

/**
 * @param array $resource Raw resource.
 * @return array
 */
function sanitize_resource(array $resource) {
    $clean = array(
        'heading' => \sanitize_text_field($resource['heading'] ?? ''),
        'intro' => \sanitize_textarea_field($resource['intro'] ?? ''),
        'sections' => array(),
    );

    if (isset($resource['label'])) {
        $clean['label'] = \sanitize_text_field($resource['label']);
    }

    foreach (($resource['sections'] ?? array()) as $section) {
        if (!\is_array($section)) {
            continue;
        }

        $clean_section = array(
            'title' => \sanitize_text_field($section['title'] ?? ''),
            'description' => \sanitize_textarea_field($section['description'] ?? ''),
            'items' => array(),
        );

        foreach (($section['items'] ?? array()) as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $href = sanitize_resource_url($item['href'] ?? '');
            $clean_item = array(
                'label' => \sanitize_text_field($item['label'] ?? ''),
                'text' => \sanitize_text_field($item['text'] ?? ''),
            );

            if ($href !== '') {
                $clean_item['href'] = $href;
            }

            if ($clean_item['label'] !== '' || $clean_item['text'] !== '') {
                $clean_section['items'][] = $clean_item;
            }
        }

        if ($clean_section['title'] !== '' || $clean_section['description'] !== '' || !empty($clean_section['items'])) {
            $clean['sections'][] = $clean_section;
        }
    }

    return $clean;
}

/**
 * Allow safe links for web, phone, email, and SMS resources.
 *
 * @param string $url Raw URL.
 * @return string
 */
function sanitize_resource_url($url) {
    $url = \trim((string) $url);
    if ($url === '') {
        return '';
    }

    $scheme = \wp_parse_url($url, PHP_URL_SCHEME);
    $allowed = array('http', 'https', 'tel', 'mailto', 'sms');
    if (!$scheme || !\in_array(\strtolower($scheme), $allowed, true)) {
        return '';
    }

    return \esc_url_raw($url, $allowed);
}

/**
 * @param array $config Internal config.
 * @return array Public JSON shape.
 */
function public_json_from_config(array $config) {
    $json = array(
        'default' => public_resource($config['default'] ?? array()),
        'resources' => array(),
    );

    foreach (($config['resources'] ?? array()) as $app_id => $resource) {
        $clean_app_id = sanitize_app_id((string) $app_id);
        if ($clean_app_id === '') {
            continue;
        }
        $json['resources'][$clean_app_id] = public_resource(\is_array($resource) ? $resource : array());
    }

    return $json;
}

/**
 * Remove admin-only keys from resource data.
 *
 * @param array $resource Internal resource.
 * @return array
 */
function public_resource(array $resource) {
    $resource = sanitize_resource($resource);
    unset($resource['label']);
    return $resource;
}

/**
 * Publish JSON to wp-content/uploads/directory-config/emergency-help-resources.json.
 *
 * @param array $config Internal config.
 * @return true|\WP_Error
 */
function publish_json(array $config) {
    $uploads = \wp_upload_dir();
    if (!empty($uploads['error'])) {
        return new \WP_Error('upload_dir_error', $uploads['error']);
    }

    $dir = \trailingslashit($uploads['basedir']) . UPLOAD_SUBDIR;
    if (!\wp_mkdir_p($dir)) {
        return new \WP_Error('mkdir_failed', 'Unable to create uploads directory: ' . $dir);
    }

    $json = \wp_json_encode(public_json_from_config($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return new \WP_Error('json_failed', 'Unable to encode emergency help resources as JSON.');
    }

    $written = \file_put_contents(\trailingslashit($dir) . JSON_FILENAME, $json . "\n");
    if ($written === false) {
        return new \WP_Error('write_failed', 'Unable to write emergency help resources JSON file.');
    }

    \update_option('etp_sdc_last_published_at', \current_time('mysql'), false);
    return true;
}

/**
 * @return string
 */
function json_file_url() {
    $uploads = \wp_upload_dir();
    return \trailingslashit($uploads['baseurl']) . UPLOAD_SUBDIR . '/' . JSON_FILENAME;
}

/**
 * @param string $url Raw URL.
 * @return string
 */
function sanitize_embed_base_url($url) {
    $url = \trim((string) $url);
    if ($url === '') {
        return '';
    }

    $url = \strtok($url, '?#');
    $scheme = \wp_parse_url($url, PHP_URL_SCHEME);
    $host = \wp_parse_url($url, PHP_URL_HOST);
    if (!$scheme || !$host || !\in_array(\strtolower($scheme), array('http', 'https'), true)) {
        return '';
    }

    return \esc_url_raw($url, array('http', 'https'));
}

/**
 * @return string
 */
function get_embed_base_url() {
    return sanitize_embed_base_url(\get_option(EMBED_BASE_URL_OPTION, ''));
}

/**
 * @param string $public_key Raw public key.
 * @return string
 */
function sanitize_public_key($public_key) {
    $public_key = \strtolower(\trim((string) $public_key));
    $public_key = \preg_replace('/[^a-z0-9-]+/', '-', $public_key);
    $public_key = \trim((string) $public_key, '-');
    return $public_key;
}

/**
 * @return array
 */
function get_deployed_directories() {
    $directories = \get_option(DEPLOYED_DIRECTORIES_OPTION, array());
    if (!\is_array($directories)) {
        return array();
    }

    $clean = array();
    foreach ($directories as $stored_key => $directory) {
        if (!\is_array($directory)) {
            continue;
        }

        $public_key = sanitize_public_key($directory['public_key'] ?? $stored_key);
        $name = \sanitize_text_field($directory['name'] ?? '');
        $app_id = sanitize_app_id($directory['app_id'] ?? '');
        $access_code = \sanitize_text_field($directory['access_code'] ?? '');
        if ($public_key === '' || $name === '' || $app_id === '') {
            continue;
        }

        $clean[$public_key] = array(
            'name' => $name,
            'public_key' => $public_key,
            'app_id' => $app_id,
            'access_code' => $access_code,
        );
    }

    \uasort($clean, function ($a, $b) {
        return \strnatcasecmp($a['name'], $b['name']);
    });

    return $clean;
}

/**
 * @param array $directories Directory records.
 */
function save_deployed_directories(array $directories) {
    \update_option(DEPLOYED_DIRECTORIES_OPTION, $directories, false);
}

/**
 * Register admin pages.
 */
function admin_menu() {
    \add_menu_page(
        'ETP Searchable Directory Config',
        'ETP Searchable Directory Config',
        CAPABILITY,
        MENU_SLUG,
        __NAMESPACE__ . '\\render_emergency_help_page',
        'dashicons-warning',
        58
    );

    \add_submenu_page(
        MENU_SLUG,
        'Emergency Help',
        'Emergency Help',
        CAPABILITY,
        EMERGENCY_HELP_SLUG,
        __NAMESPACE__ . '\\render_emergency_help_page'
    );

    \add_submenu_page(
        MENU_SLUG,
        'JSON Status',
        'JSON Status',
        CAPABILITY,
        JSON_STATUS_SLUG,
        __NAMESPACE__ . '\\render_json_status_page'
    );

    \add_submenu_page(
        MENU_SLUG,
        'Deployed Directories',
        'Deployed Directories',
        'manage_options',
        DEPLOYED_DIRECTORIES_SLUG,
        __NAMESPACE__ . '\\render_deployed_directories_page'
    );

    \add_submenu_page(
        MENU_SLUG,
        'Partner Iframe Links',
        'Partner Iframe Links',
        CAPABILITY,
        IFRAME_LINKS_SLUG,
        __NAMESPACE__ . '\\render_iframe_links_page'
    );

    \add_submenu_page(
        MENU_SLUG,
        'Access Settings',
        'Access Settings',
        CAPABILITY,
        ACCESS_SETTINGS_SLUG,
        __NAMESPACE__ . '\\render_access_settings_page'
    );
}
\add_action('admin_menu', __NAMESPACE__ . '\\admin_menu');

/**
 * Handle admin form submissions.
 */
function handle_admin_post() {
    if (!\current_user_can(CAPABILITY) && !\current_user_can('manage_options')) {
        \wp_die(\esc_html__('You do not have permission to manage ETP Searchable Directory Config.', 'etp-sdc'));
    }

    \check_admin_referer('etp_sdc_action');

    $action = \sanitize_key($_POST['etp_sdc_action'] ?? '');
    $config = get_config();

    if ($action === 'save_embed_settings') {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('You do not have permission to manage deployed directories.', 'etp-sdc'));
        }

        $embed_base_url = sanitize_embed_base_url($_POST['embed_base_url'] ?? '');
        if ($embed_base_url === '') {
            redirect_with_notice('invalid_embed_url', DEPLOYED_DIRECTORIES_SLUG);
        }

        \update_option(EMBED_BASE_URL_OPTION, $embed_base_url, false);
        redirect_with_notice('embed_url_saved', DEPLOYED_DIRECTORIES_SLUG);
    }

    if ($action === 'save_deployed_directory') {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('You do not have permission to manage deployed directories.', 'etp-sdc'));
        }

        $directories = get_deployed_directories();
        $original_key = sanitize_public_key($_POST['original_public_key'] ?? '');
        $public_key = sanitize_public_key($_POST['public_key'] ?? '');
        $name = \sanitize_text_field($_POST['name'] ?? '');
        $app_id = sanitize_app_id($_POST['app_id'] ?? '');
        $access_code = \sanitize_text_field($_POST['access_code'] ?? '');

        if ($name === '' || $public_key === '' || $app_id === '') {
            redirect_with_notice('missing_directory_fields', DEPLOYED_DIRECTORIES_SLUG);
        }

        foreach ($directories as $key => $directory) {
            if ($key !== $original_key && $key === $public_key) {
                redirect_with_notice('duplicate_public_key', DEPLOYED_DIRECTORIES_SLUG);
            }
            if ($key !== $original_key && $directory['app_id'] === $app_id) {
                redirect_with_notice('duplicate_directory_app_id', DEPLOYED_DIRECTORIES_SLUG);
            }
        }

        if ($original_key !== '' && $original_key !== $public_key) {
            unset($directories[$original_key]);
        }

        $directories[$public_key] = array(
            'name' => $name,
            'public_key' => $public_key,
            'app_id' => $app_id,
            'access_code' => $access_code,
        );

        save_deployed_directories($directories);
        redirect_with_notice('directory_saved', DEPLOYED_DIRECTORIES_SLUG);
    }

    if ($action === 'delete_deployed_directory') {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('You do not have permission to manage deployed directories.', 'etp-sdc'));
        }

        $public_key = sanitize_public_key($_POST['public_key'] ?? '');
        $directories = get_deployed_directories();
        if ($public_key !== '') {
            unset($directories[$public_key]);
            save_deployed_directories($directories);
        }

        redirect_with_notice('directory_deleted', DEPLOYED_DIRECTORIES_SLUG);
    }

    if ($action === 'save_resource') {
        $is_default = !empty($_POST['is_default']);
        $app_id = sanitize_app_id($_POST['app_id'] ?? '');
        $original_app_id = sanitize_app_id($_POST['original_app_id'] ?? '');
        $resource = sanitize_resource_from_post($_POST);

        if ($is_default) {
            $config['default'] = $resource;
        } elseif ($app_id !== '') {
            $resource['label'] = \sanitize_text_field($_POST['label'] ?? derive_label($resource, $app_id));
            if ($original_app_id !== '' && $original_app_id !== $app_id) {
                unset($config['resources'][$original_app_id]);
            }
            $config['resources'][$app_id] = $resource;
        } else {
            redirect_with_notice('missing_app_id');
        }

        \update_option(OPTION_KEY, $config, false);
        $published = publish_json($config);
        redirect_with_notice(\is_wp_error($published) ? 'publish_failed' : 'saved');
    }

    if ($action === 'delete_resource') {
        $app_id = sanitize_app_id($_POST['app_id'] ?? '');
        if ($app_id !== '') {
            unset($config['resources'][$app_id]);
            \update_option(OPTION_KEY, $config, false);
            publish_json($config);
        }
        redirect_with_notice('deleted');
    }

    if ($action === 'import_json') {
        $seed_path = \plugin_dir_path(__FILE__) . JSON_FILENAME;
        if (\is_readable($seed_path)) {
            $decoded = \json_decode((string) \file_get_contents($seed_path), true);
            if (\is_array($decoded)) {
                $config = config_from_public_json($decoded);
                \update_option(OPTION_KEY, $config, false);
                publish_json($config);
                redirect_with_notice('imported');
            }
        }
        redirect_with_notice('import_failed');
    }

    if ($action === 'regenerate_json') {
        $published = publish_json($config);
        redirect_with_notice(\is_wp_error($published) ? 'publish_failed' : 'regenerated', JSON_STATUS_SLUG);
    }

    redirect_with_notice('unknown_action');
}
\add_action('admin_post_etp_sdc_save', __NAMESPACE__ . '\\handle_admin_post');

/**
 * @param array $post Raw post data.
 * @return array
 */
function sanitize_resource_from_post(array $post) {
    $raw = array(
        'heading' => $post['heading'] ?? '',
        'intro' => $post['intro'] ?? '',
        'sections' => array(),
    );

    foreach (($post['sections'] ?? array()) as $section) {
        if (!\is_array($section)) {
            continue;
        }
        $raw['sections'][] = $section;
    }

    return sanitize_resource($raw);
}

/**
 * @param string $notice Notice key.
 * @param string $page Page slug.
 */
function redirect_with_notice($notice, $page = EMERGENCY_HELP_SLUG) {
    \wp_safe_redirect(\add_query_arg(array('page' => $page, 'etp_sdc_notice' => \sanitize_key($notice)), \admin_url('admin.php')));
    exit;
}

/**
 * Print admin CSS.
 */
function admin_styles() {
    $screen = \get_current_screen();
    if (!$screen || \strpos($screen->id, MENU_SLUG) === false) {
        return;
    }
    ?>
    <style>
        .etp-sdc-wrap .etp-sdc-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:18px 0; }
        .etp-sdc-wrap .etp-sdc-grid { display:grid; grid-template-columns:minmax(0, 1fr) 320px; gap:18px; align-items:start; }
        .etp-sdc-wrap .etp-sdc-panel { border:1px solid #c3c4c7; background:#fff; margin-bottom:16px; }
        .etp-sdc-wrap .etp-sdc-panel h2 { margin:0; padding:12px 14px; border-bottom:1px solid #c3c4c7; font-size:17px; }
        .etp-sdc-wrap .etp-sdc-panel-body { padding:14px; }
        .etp-sdc-wrap .etp-sdc-field { margin-bottom:14px; }
        .etp-sdc-wrap .etp-sdc-field label { display:block; margin-bottom:5px; font-weight:600; }
        .etp-sdc-wrap input[type="text"], .etp-sdc-wrap input[type="url"], .etp-sdc-wrap textarea { width:100%; max-width:100%; }
        .etp-sdc-wrap textarea { min-height:74px; }
        .etp-sdc-wrap .etp-sdc-two { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .etp-sdc-wrap .etp-sdc-section { border:1px solid #dcdcde; margin:14px 0; background:#fff; }
        .etp-sdc-wrap .etp-sdc-section-head { display:flex; justify-content:space-between; gap:12px; padding:12px; border-bottom:1px solid #dcdcde; background:#f6f7f7; }
        .etp-sdc-wrap .etp-sdc-item-row { display:grid; grid-template-columns:150px 1fr 1fr auto; gap:10px; padding:12px; border-top:1px solid #f0f0f1; align-items:end; }
        .etp-sdc-wrap .etp-sdc-section-actions { padding:12px; border-top:1px solid #f0f0f1; }
        .etp-sdc-wrap .etp-sdc-preview { border-left:5px solid #d95846; background:#fff8e8; padding:14px; }
        .etp-sdc-wrap .etp-sdc-url { display:flex; gap:8px; align-items:center; padding:10px; border:1px solid #8c8f94; background:#fff; }
        .etp-sdc-wrap .etp-sdc-url code { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; flex:1; }
        .etp-sdc-wrap .etp-sdc-status-list { display:grid; gap:10px; }
        .etp-sdc-wrap .etp-sdc-status-list span { color:#00a32a; font-weight:700; margin-right:8px; }
        .etp-sdc-wrap .etp-sdc-wizard { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:18px; align-items:start; }
        .etp-sdc-wrap .etp-sdc-theme-editor { display:grid; grid-template-columns:minmax(360px, 0.95fr) minmax(360px, 1.05fr); gap:18px; align-items:start; }
        .etp-sdc-wrap .etp-sdc-theme-group { margin:0 0 18px; }
        .etp-sdc-wrap .etp-sdc-theme-group h3 { margin:0 0 8px; font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:#50575e; }
        .etp-sdc-wrap .etp-sdc-color-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
        .etp-sdc-wrap .etp-sdc-color-control { margin:0; }
        .etp-sdc-wrap .etp-sdc-color-control label { display:block; margin-bottom:5px; font-weight:600; }
        .etp-sdc-wrap .etp-sdc-swatch-row { display:grid; grid-template-columns:42px minmax(0, 1fr); gap:7px; align-items:center; }
        .etp-sdc-wrap .etp-sdc-swatch-row input[type="color"] { width:42px; height:38px; padding:1px; border:1px solid #8c8f94; border-radius:4px; background:#fff; cursor:pointer; }
        .etp-sdc-wrap .etp-sdc-swatch-row input[type="text"] { font-family:monospace; min-height:38px; }
        .etp-sdc-wrap .etp-sdc-font-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .etp-sdc-wrap .etp-sdc-preview-shell { position:sticky; top:42px; border:1px solid #c3c4c7; background:#f6f7f7; padding:12px; }
        .etp-sdc-wrap .etp-sdc-preview-toolbar { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:10px; color:#50575e; font-size:12px; }
        .etp-sdc-wrap .etp-sdc-live-preview { --preview-text:#0b1828; --preview-muted:#58677a; --preview-line:#d7e2ea; --preview-paper:#f4f8fa; --preview-surface:#ffffff; --preview-primary:#087f83; --preview-primary-dark:#005f62; --preview-primary-soft:#d8f0ee; --preview-secondary:#276fbf; --preview-secondary-soft:#e5f0ff; --preview-accent:#f3ad2e; --preview-accent-soft:#fff3d5; --preview-success:#2f8a52; --preview-success-soft:#dcf3e4; --preview-danger:#d95846; --preview-font:Inter, Arial, sans-serif; --preview-heading:Georgia, serif; border:1px solid var(--preview-line); background:var(--preview-paper); color:var(--preview-text); font-family:var(--preview-font); overflow:hidden; }
        .etp-sdc-wrap .etp-sdc-live-hero { background:linear-gradient(135deg, var(--preview-primary), var(--preview-primary-dark)); color:#fff; padding:22px; }
        .etp-sdc-wrap .etp-sdc-live-eyebrow { display:inline-flex; border:1px solid rgba(255,255,255,.45); border-radius:999px; padding:3px 10px; font-size:11px; font-weight:700; text-transform:uppercase; }
        .etp-sdc-wrap .etp-sdc-live-hero h3 { margin:8px 0 6px; color:inherit; font-family:var(--preview-heading); font-size:24px; line-height:1.1; }
        .etp-sdc-wrap .etp-sdc-live-hero p { margin:0; max-width:520px; color:#fff; }
        .etp-sdc-wrap .etp-sdc-live-search { margin:-16px 18px 14px; padding:10px; border-radius:6px; background:var(--preview-surface); border:1px solid var(--preview-line); box-shadow:0 10px 22px rgba(0,0,0,.08); display:flex; gap:8px; }
        .etp-sdc-wrap .etp-sdc-live-input { flex:1; border:1px solid var(--preview-line); border-radius:4px; padding:10px 12px; color:var(--preview-muted); background:#fff; }
        .etp-sdc-wrap .etp-sdc-live-button { border:0; border-radius:4px; padding:10px 12px; background:var(--preview-primary); color:#fff; font-weight:700; }
        .etp-sdc-wrap .etp-sdc-live-body { display:grid; grid-template-columns:130px 1fr; gap:14px; padding:0 18px 18px; }
        .etp-sdc-wrap .etp-sdc-live-filter { background:var(--preview-surface); border:1px solid var(--preview-line); border-radius:5px; padding:10px; }
        .etp-sdc-wrap .etp-sdc-live-filter strong { display:block; margin-bottom:8px; }
        .etp-sdc-wrap .etp-sdc-live-pill { display:inline-flex; margin:0 4px 6px 0; padding:5px 8px; border-radius:5px; background:var(--preview-primary-soft); color:var(--preview-primary-dark); font-size:11px; font-weight:700; }
        .etp-sdc-wrap .etp-sdc-live-main h4 { margin:0 0 4px; color:var(--preview-text); font-size:17px; font-family:var(--preview-heading); }
        .etp-sdc-wrap .etp-sdc-live-main p { margin:0 0 10px; color:var(--preview-muted); }
        .etp-sdc-wrap .etp-sdc-live-cards { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .etp-sdc-wrap .etp-sdc-live-card { border:1px solid var(--preview-line); border-top:4px solid var(--preview-secondary); border-radius:5px; background:var(--preview-surface); padding:11px; min-height:138px; }
        .etp-sdc-wrap .etp-sdc-live-card:nth-child(2) { border-top-color:var(--preview-accent); }
        .etp-sdc-wrap .etp-sdc-live-card h5 { margin:0 0 7px; font-size:14px; line-height:1.2; color:var(--preview-text); }
        .etp-sdc-wrap .etp-sdc-live-card p { margin:0 0 8px; color:var(--preview-muted); font-size:12px; }
        .etp-sdc-wrap .etp-sdc-live-tag { display:inline-flex; border-radius:999px; padding:4px 7px; background:var(--preview-success-soft); color:var(--preview-success); font-size:10px; font-weight:700; }
        .etp-sdc-wrap .etp-sdc-live-danger { background:var(--preview-accent-soft); color:var(--preview-danger); }
        .etp-sdc-wrap .etp-sdc-live-actions { display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-top:12px; }
        .etp-sdc-wrap .etp-sdc-live-actions span { display:block; text-align:center; border-radius:4px; padding:8px; font-weight:700; background:var(--preview-primary); color:#fff; }
        .etp-sdc-wrap .etp-sdc-live-actions span + span { background:var(--preview-secondary-soft); color:var(--preview-text); }
        .etp-sdc-wrap .etp-sdc-generated { min-height:120px; font-family:monospace; }
        .etp-sdc-wrap .etp-sdc-copy-row { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
        .etp-sdc-wrap .etp-sdc-muted { color:#646970; }
        .etp-sdc-wrap .etp-sdc-hidden { display:none; }
        .etp-sdc-wrap pre { overflow:auto; max-height:520px; padding:14px; background:#101517; color:#d7e8f7; }
        @media (max-width: 1280px) {
            .etp-sdc-wrap .etp-sdc-wizard, .etp-sdc-wrap .etp-sdc-theme-editor { grid-template-columns:1fr; }
            .etp-sdc-wrap .etp-sdc-preview-shell { position:static; }
        }
        @media (max-width: 782px) {
            .etp-sdc-wrap .etp-sdc-color-grid, .etp-sdc-wrap .etp-sdc-font-grid, .etp-sdc-wrap .etp-sdc-live-body, .etp-sdc-wrap .etp-sdc-live-cards { grid-template-columns:1fr; }
            .etp-sdc-wrap .etp-sdc-live-search { display:block; }
            .etp-sdc-wrap .etp-sdc-live-button { margin-top:8px; width:100%; }
        }
    </style>
    <?php
}
\add_action('admin_head', __NAMESPACE__ . '\\admin_styles');

/**
 * Print small admin JavaScript for repeatable sections/items.
 */
function admin_scripts() {
    $screen = \get_current_screen();
    if (!$screen || \strpos($screen->id, MENU_SLUG) === false) {
        return;
    }
    ?>
    <script>
    document.addEventListener("click", function (event) {
        const addSection = event.target.closest("[data-etp-add-section]");
        if (addSection) {
            const container = document.querySelector("[data-etp-sections]");
            const index = container.querySelectorAll(".etp-sdc-section").length;
            container.insertAdjacentHTML("beforeend", `
                <div class="etp-sdc-section">
                    <div class="etp-sdc-section-head">
                        <strong>Section ${index + 1}</strong>
                        <button type="button" class="button" data-etp-remove-section>Remove section</button>
                    </div>
                    <div class="etp-sdc-panel-body">
                        <div class="etp-sdc-field">
                            <label>Section title</label>
                            <input type="text" name="sections[${index}][title]" value="">
                        </div>
                        <div class="etp-sdc-field">
                            <label>Description</label>
                            <textarea name="sections[${index}][description]"></textarea>
                        </div>
                    </div>
                    <div data-etp-items>
                        ${resourceItemTemplate(index, 0)}
                    </div>
                    <div class="etp-sdc-section-actions">
                        <button type="button" class="button" data-etp-add-item>Add resource item</button>
                    </div>
                </div>
            `);
            return;
        }

        const addItem = event.target.closest("[data-etp-add-item]");
        if (addItem) {
            const section = addItem.closest(".etp-sdc-section");
            const sectionIndex = Array.prototype.indexOf.call(document.querySelectorAll(".etp-sdc-section"), section);
            const items = section.querySelector("[data-etp-items]");
            const itemIndex = items.querySelectorAll(".etp-sdc-item-row").length;
            items.insertAdjacentHTML("beforeend", resourceItemTemplate(sectionIndex, itemIndex));
            return;
        }

        const removeSection = event.target.closest("[data-etp-remove-section]");
        if (removeSection && confirm("Remove this section?")) {
            removeSection.closest(".etp-sdc-section").remove();
            return;
        }

        const removeItem = event.target.closest("[data-etp-remove-item]");
        if (removeItem && confirm("Remove this resource item?")) {
            removeItem.closest(".etp-sdc-item-row").remove();
        }
    });

    function resourceItemTemplate(sectionIndex, itemIndex) {
        return `
            <div class="etp-sdc-item-row">
                <div>
                    <label>Label</label>
                    <input type="text" name="sections[${sectionIndex}][items][${itemIndex}][label]" value="">
                </div>
                <div>
                    <label>Display text</label>
                    <input type="text" name="sections[${sectionIndex}][items][${itemIndex}][text]" value="">
                </div>
                <div>
                    <label>Link URL</label>
                    <input type="text" name="sections[${sectionIndex}][items][${itemIndex}][href]" value="">
                </div>
                <button type="button" class="button" data-etp-remove-item>Remove</button>
            </div>
        `;
    }

    const iframeWizard = document.querySelector("[data-etp-iframe-wizard]");
    if (iframeWizard) {
        const directorySelect = iframeWizard.querySelector("[data-etp-iframe-directory]");
        const modeInputs = iframeWizard.querySelectorAll("[data-etp-iframe-mode]");
        const themeFields = iframeWizard.querySelector("[data-etp-theme-fields]");
        const themeInputs = iframeWizard.querySelectorAll("[data-etp-theme-param]");
        const colorPickers = iframeWizard.querySelectorAll("[data-etp-theme-color-picker]");
        const preview = iframeWizard.querySelector("[data-etp-theme-preview]");
        const srcOutput = iframeWizard.querySelector("[data-etp-iframe-src]");
        const htmlOutput = iframeWizard.querySelector("[data-etp-iframe-html]");
        const copyStatus = iframeWizard.querySelector("[data-etp-copy-status]");
        const embedBaseUrl = iframeWizard.dataset.embedBaseUrl || "";

        const isHexColor = (value) => /^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(value);
        const isFontStack = (value) => /^[A-Za-z0-9\s,"'\-_]+$/.test(value) && value.length <= 160;
        const themePreviewMap = {
            text: "--preview-text",
            muted: "--preview-muted",
            line: "--preview-line",
            paper: "--preview-paper",
            surface: "--preview-surface",
            primary: "--preview-primary",
            primary_dark: "--preview-primary-dark",
            primary_soft: "--preview-primary-soft",
            secondary: "--preview-secondary",
            secondary_soft: "--preview-secondary-soft",
            accent: "--preview-accent",
            accent_soft: "--preview-accent-soft",
            success: "--preview-success",
            success_soft: "--preview-success-soft",
            danger: "--preview-danger",
            font_family: "--preview-font",
            heading_font: "--preview-heading"
        };

        function getMode() {
            const checked = Array.prototype.find.call(modeInputs, (input) => input.checked);
            return checked ? checked.value : "default";
        }

        function normalizeHex(value) {
            const clean = value.trim().replace(/^#/, "");
            if (!isHexColor(clean)) return "";
            if (clean.length === 3) {
                return clean.split("").map((letter) => letter + letter).join("").toLowerCase();
            }
            return clean.toLowerCase();
        }

        function themeValue(input) {
            const value = input.value.trim();
            if (value) return value;
            return input.dataset.previewDefault || input.placeholder || "";
        }

        function syncColorPicker(input) {
            const picker = iframeWizard.querySelector(`[data-etp-theme-color-picker="${input.dataset.etpThemeParam}"]`);
            const hex = normalizeHex(themeValue(input));
            if (picker && hex) picker.value = `#${hex}`;
        }

        function updateThemePreview() {
            if (!preview) return;
            themeInputs.forEach((input) => {
                const param = input.dataset.etpThemeParam;
                const property = themePreviewMap[param];
                if (!property) return;
                if (param.indexOf("font") !== -1) {
                    const value = themeValue(input);
                    if (isFontStack(value)) preview.style.setProperty(property, value);
                    return;
                }
                const hex = normalizeHex(themeValue(input));
                if (hex) preview.style.setProperty(property, `#${hex}`);
                syncColorPicker(input);
            });
        }

        function buildIframeLink() {
            const publicKey = directorySelect ? directorySelect.value : "";
            const selected = directorySelect?.selectedOptions?.[0];
            const directoryName = selected?.dataset.name || "Searchable Directory";
            const customMode = getMode() === "custom";

            if (themeFields) {
                themeFields.classList.toggle("etp-sdc-hidden", !customMode);
            }
            updateThemePreview();

            if (!embedBaseUrl || !publicKey) {
                srcOutput.value = "";
                htmlOutput.value = "";
                return;
            }

            const url = new URL(embedBaseUrl);
            url.searchParams.set("directory", publicKey);

            if (customMode) {
                themeInputs.forEach((input) => {
                    const param = input.dataset.etpThemeParam;
                    const value = input.value.trim();
                    if (!value) return;
                    const valid = param.indexOf("font") !== -1 ? isFontStack(value) : isHexColor(value);
                    if (valid) {
                        url.searchParams.set(param, value.replace(/^#/, ""));
                    }
                });
            }

            const src = url.toString();
            srcOutput.value = src;
            htmlOutput.value = `<iframe
  src="${src.replace(/"/g, "&quot;")}"
  title="${directoryName.replace(/"/g, "&quot;")}"
  width="100%"
  height="900"
  style="border:0;"
  loading="lazy"
></iframe>`;
        }

        colorPickers.forEach((picker) => {
            picker.addEventListener("input", function () {
                const input = iframeWizard.querySelector(`[data-etp-theme-param="${picker.dataset.etpThemeColorPicker}"]`);
                if (input) input.value = picker.value.replace(/^#/, "");
                buildIframeLink();
            });
        });
        themeInputs.forEach(syncColorPicker);
        iframeWizard.addEventListener("input", buildIframeLink);
        iframeWizard.addEventListener("change", buildIframeLink);
        buildIframeLink();
    }

    document.addEventListener("click", function (event) {
        const copyButton = event.target.closest("[data-etp-copy-target]");
        if (!copyButton) return;

        const target = document.querySelector(copyButton.dataset.etpCopyTarget);
        const status = document.querySelector("[data-etp-copy-status]");
        if (!target || !target.value) return;

        navigator.clipboard.writeText(target.value).then(function () {
            if (status) status.textContent = "Copied.";
        }).catch(function () {
            target.focus();
            target.select();
            if (status) status.textContent = "Select and copy the highlighted text.";
        });
    });
    </script>
    <?php
}
\add_action('admin_footer', __NAMESPACE__ . '\\admin_scripts');

/**
 * @param string $notice Notice key.
 */
function render_notice($notice) {
    $messages = array(
        'saved' => array('success', 'Entry saved and JSON published.'),
        'deleted' => array('success', 'Entry deleted and JSON published.'),
        'imported' => array('success', 'Existing JSON imported and published.'),
        'regenerated' => array('success', 'JSON regenerated successfully.'),
        'missing_app_id' => array('error', 'App-specific entries require a Qlik app id.'),
        'embed_url_saved' => array('success', 'Global embed page URL saved.'),
        'directory_saved' => array('success', 'Deployed directory saved.'),
        'directory_deleted' => array('success', 'Deployed directory deleted.'),
        'invalid_embed_url' => array('error', 'Enter a full embed page URL that starts with http or https.'),
        'missing_directory_fields' => array('error', 'Deployed directories require a name, public key, and Qlik app id.'),
        'duplicate_public_key' => array('error', 'That public directory key is already in use.'),
        'duplicate_directory_app_id' => array('error', 'That Qlik app id is already assigned to a deployed directory.'),
        'publish_failed' => array('error', 'The entry saved, but the public JSON file could not be written. Check uploads folder permissions.'),
        'import_failed' => array('error', 'Unable to import the plugin-local emergency-help-resources.json file.'),
        'unknown_action' => array('error', 'Unknown action.'),
    );

    if (empty($messages[$notice])) {
        return;
    }

    list($type, $message) = $messages[$notice];
    printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', \esc_attr($type), \esc_html($message));
}

/**
 * Render resource list and editor.
 */
function render_emergency_help_page() {
    if (!\current_user_can(CAPABILITY)) {
        \wp_die(\esc_html__('You do not have permission to manage this configuration.', 'etp-sdc'));
    }

    $config = get_config();
    $edit = \sanitize_text_field($_GET['edit'] ?? '');
    $is_default = $edit === 'default';
    $resource = $is_default ? ($config['default'] ?? array()) : (($edit && isset($config['resources'][$edit])) ? $config['resources'][$edit] : null);
    $notice = \sanitize_key($_GET['etp_sdc_notice'] ?? '');
    ?>
    <div class="wrap etp-sdc-wrap">
        <?php render_notice($notice); ?>
        <div class="etp-sdc-header">
            <div>
                <h1>Emergency Help Resources</h1>
                <p>ETP Searchable Directory Config / Emergency Help. Manage the emergency help content shown for each Qlik app id.</p>
            </div>
            <a class="button button-primary" href="<?php echo \esc_url(\add_query_arg(array('page' => EMERGENCY_HELP_SLUG, 'edit' => 'new'), \admin_url('admin.php'))); ?>">Add app-specific entry</a>
        </div>

        <?php if ($resource !== null || $edit === 'new') : ?>
            <?php render_resource_editor($resource ?: array(), $edit, $is_default); ?>
        <?php else : ?>
            <?php render_resource_table($config); ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * @param array $config Config.
 */
function render_resource_table(array $config) {
    ?>
    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
        <?php \wp_nonce_field('etp_sdc_action'); ?>
        <input type="hidden" name="action" value="etp_sdc_save">
        <input type="hidden" name="etp_sdc_action" value="import_json">
        <button class="button">Import plugin-local JSON</button>
        <a class="button" href="<?php echo \esc_url(\add_query_arg(array('page' => JSON_STATUS_SLUG), \admin_url('admin.php'))); ?>">View JSON status</a>
    </form>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Entry</th>
                <th>Qlik app id</th>
                <th>Content summary</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php render_resource_row('default', $config['default'], true); ?>
            <?php foreach (($config['resources'] ?? array()) as $app_id => $resource) : ?>
                <?php render_resource_row($app_id, $resource, false); ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * @param string $app_id App ID.
 * @param array $resource Resource.
 * @param bool $is_default Whether row is default.
 */
function render_resource_row($app_id, array $resource, $is_default) {
    $edit_url = \add_query_arg(array('page' => EMERGENCY_HELP_SLUG, 'edit' => $is_default ? 'default' : $app_id), \admin_url('admin.php'));
    $summary = array();
    foreach (($resource['sections'] ?? array()) as $section) {
        if (!empty($section['title'])) {
            $summary[] = $section['title'];
        }
    }
    ?>
    <tr>
        <td>
            <strong><?php echo \esc_html($is_default ? 'Default fallback' : derive_label($resource, $app_id)); ?></strong>
            <div class="row-actions">
                <span class="edit"><a href="<?php echo \esc_url($edit_url); ?>">Edit</a></span>
                <?php if (!$is_default) : ?>
                    <span class="trash"><?php render_delete_button($app_id); ?></span>
                <?php endif; ?>
            </div>
        </td>
        <td><code><?php echo \esc_html($is_default ? 'Used when no matching data-app-id exists' : $app_id); ?></code></td>
        <td><?php echo \esc_html(\implode(', ', $summary)); ?></td>
        <td><?php echo $is_default ? '<span class="dashicons dashicons-lock"></span> Required' : '<span class="dashicons dashicons-yes-alt"></span> Published'; ?></td>
    </tr>
    <?php
}

/**
 * @param string $app_id App ID.
 */
function render_delete_button($app_id) {
    ?>
    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Delete this emergency help entry?');">
        <?php \wp_nonce_field('etp_sdc_action'); ?>
        <input type="hidden" name="action" value="etp_sdc_save">
        <input type="hidden" name="etp_sdc_action" value="delete_resource">
        <input type="hidden" name="app_id" value="<?php echo \esc_attr($app_id); ?>">
        <button type="submit" class="button-link-delete">Delete</button>
    </form>
    <?php
}

/**
 * @param array $resource Resource.
 * @param string $edit Edit key.
 * @param bool $is_default Default entry.
 */
function render_resource_editor(array $resource, $edit, $is_default) {
    $app_id = $is_default || $edit === 'new' ? '' : $edit;
    $resource = \wp_parse_args($resource, array('label' => '', 'heading' => '', 'intro' => '', 'sections' => array()));
    if (empty($resource['sections'])) {
        $resource['sections'] = array(array('title' => '', 'description' => '', 'items' => array(array('label' => '', 'text' => '', 'href' => ''))));
    }
    ?>
    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
        <?php \wp_nonce_field('etp_sdc_action'); ?>
        <input type="hidden" name="action" value="etp_sdc_save">
        <input type="hidden" name="etp_sdc_action" value="save_resource">
        <input type="hidden" name="original_app_id" value="<?php echo \esc_attr($app_id); ?>">
        <input type="hidden" name="is_default" value="<?php echo $is_default ? '1' : '0'; ?>">

        <div class="etp-sdc-grid">
            <div class="etp-sdc-panel">
                <h2><?php echo \esc_html($is_default ? 'Edit default fallback' : 'Entry details'); ?></h2>
                <div class="etp-sdc-panel-body">
                    <?php if (!$is_default) : ?>
                    <div class="etp-sdc-two">
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-label">Internal label</label>
                            <input id="etp-sdc-label" type="text" name="label" value="<?php echo \esc_attr($resource['label'] ?? ''); ?>">
                        </div>
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-app-id">Qlik app id</label>
                            <input id="etp-sdc-app-id" type="text" name="app_id" value="<?php echo \esc_attr($app_id); ?>" required>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="etp-sdc-field">
                        <label for="etp-sdc-heading">Public heading</label>
                        <input id="etp-sdc-heading" type="text" name="heading" value="<?php echo \esc_attr($resource['heading'] ?? ''); ?>" required>
                    </div>
                    <div class="etp-sdc-field">
                        <label for="etp-sdc-intro">Intro message</label>
                        <textarea id="etp-sdc-intro" name="intro" required><?php echo \esc_textarea($resource['intro'] ?? ''); ?></textarea>
                    </div>

                    <div data-etp-sections>
                        <?php foreach (($resource['sections'] ?? array()) as $section_index => $section) : ?>
                            <?php render_section_fields((int) $section_index, \is_array($section) ? $section : array()); ?>
                        <?php endforeach; ?>
                    </div>

                    <p><button type="button" class="button" data-etp-add-section>Add section</button></p>
                    <?php \submit_button('Save and publish JSON'); ?>
                </div>
            </div>
            <div>
                <div class="etp-sdc-panel">
                    <h2>Directory preview</h2>
                    <div class="etp-sdc-panel-body">
                        <div class="etp-sdc-preview">
                            <h3><?php echo \esc_html($resource['heading'] ?? 'Emergency help resources'); ?></h3>
                            <p><strong><?php echo \esc_html($resource['intro'] ?? ''); ?></strong></p>
                            <?php foreach (\array_slice(($resource['sections'] ?? array()), 0, 1) as $section) : ?>
                                <p><strong><?php echo \esc_html($section['title'] ?? ''); ?></strong></p>
                                <p><?php echo \esc_html($section['description'] ?? ''); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="etp-sdc-panel">
                    <h2>Role validation</h2>
                    <div class="etp-sdc-panel-body etp-sdc-status-list">
                        <div><span>✓</span>User has <?php echo \esc_html(ROLE_NAME); ?> role capability</div>
                        <div><span>✓</span>Default fallback remains protected</div>
                        <div><span>✓</span>URLs use approved schemes</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php
}

/**
 * @param int $index Section index.
 * @param array $section Section data.
 */
function render_section_fields($index, array $section) {
    $items = $section['items'] ?? array();
    if (empty($items)) {
        $items = array(array('label' => '', 'text' => '', 'href' => ''));
    }
    ?>
    <div class="etp-sdc-section">
        <div class="etp-sdc-section-head">
            <strong>Section <?php echo \esc_html((string) ($index + 1)); ?></strong>
            <button type="button" class="button" data-etp-remove-section>Remove section</button>
        </div>
        <div class="etp-sdc-panel-body">
            <div class="etp-sdc-field">
                <label>Section title</label>
                <input type="text" name="sections[<?php echo \esc_attr((string) $index); ?>][title]" value="<?php echo \esc_attr($section['title'] ?? ''); ?>">
            </div>
            <div class="etp-sdc-field">
                <label>Description</label>
                <textarea name="sections[<?php echo \esc_attr((string) $index); ?>][description]"><?php echo \esc_textarea($section['description'] ?? ''); ?></textarea>
            </div>
        </div>
        <div data-etp-items>
            <?php foreach ($items as $item_index => $item) : ?>
                <div class="etp-sdc-item-row">
                    <div>
                        <label>Label</label>
                        <input type="text" name="sections[<?php echo \esc_attr((string) $index); ?>][items][<?php echo \esc_attr((string) $item_index); ?>][label]" value="<?php echo \esc_attr($item['label'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Display text</label>
                        <input type="text" name="sections[<?php echo \esc_attr((string) $index); ?>][items][<?php echo \esc_attr((string) $item_index); ?>][text]" value="<?php echo \esc_attr($item['text'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Link URL</label>
                        <input type="text" name="sections[<?php echo \esc_attr((string) $index); ?>][items][<?php echo \esc_attr((string) $item_index); ?>][href]" value="<?php echo \esc_attr($item['href'] ?? ''); ?>">
                    </div>
                    <button type="button" class="button" data-etp-remove-item>Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="etp-sdc-section-actions">
            <button type="button" class="button" data-etp-add-item>Add resource item</button>
        </div>
    </div>
    <?php
}

/**
 * Render deployed directory registry page.
 */
function render_deployed_directories_page() {
    if (!\current_user_can('manage_options')) {
        \wp_die(\esc_html__('You do not have permission to manage deployed directories.', 'etp-sdc'));
    }

    $directories = get_deployed_directories();
    $edit = sanitize_public_key($_GET['edit_directory'] ?? '');
    $editing = $edit !== '' && isset($directories[$edit]) ? $directories[$edit] : array('name' => '', 'public_key' => '', 'app_id' => '', 'access_code' => '');
    $notice = \sanitize_key($_GET['etp_sdc_notice'] ?? '');
    ?>
    <div class="wrap etp-sdc-wrap">
        <?php render_notice($notice); ?>
        <div class="etp-sdc-header">
            <div>
                <h1>Deployed Directories</h1>
                <p>Register searchable directories that can be shared with partners using public directory keys.</p>
            </div>
        </div>

        <div class="etp-sdc-grid">
            <div>
                <div class="etp-sdc-panel">
                    <h2>Global embed page URL</h2>
                    <div class="etp-sdc-panel-body">
                        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                            <?php \wp_nonce_field('etp_sdc_action'); ?>
                            <input type="hidden" name="action" value="etp_sdc_save">
                            <input type="hidden" name="etp_sdc_action" value="save_embed_settings">
                            <div class="etp-sdc-field">
                                <label for="etp-sdc-embed-base-url">Public embed page URL</label>
                                <input id="etp-sdc-embed-base-url" type="url" name="embed_base_url" value="<?php echo \esc_attr(get_embed_base_url()); ?>" placeholder="https://example.org/searchable-directory/embed/" required>
                                <p class="description">This page should contain the searchable directory shortcode. Partner iframe links are generated from this URL.</p>
                            </div>
                            <?php \submit_button('Save embed URL'); ?>
                        </form>
                    </div>
                </div>

                <div class="etp-sdc-panel">
                    <h2>Registered directories</h2>
                    <div class="etp-sdc-panel-body">
                        <?php if (empty($directories)) : ?>
                            <p>No deployed directories have been added yet.</p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Public key</th>
                                        <th>Qlik app id</th>
                                        <th>Access code</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($directories as $directory) : ?>
                                        <tr>
                                            <td><strong><?php echo \esc_html($directory['name']); ?></strong></td>
                                            <td><code><?php echo \esc_html($directory['public_key']); ?></code></td>
                                            <td><code><?php echo \esc_html($directory['app_id']); ?></code></td>
                                            <td><?php echo !empty($directory['access_code']) ? '<span class="dashicons dashicons-yes-alt"></span> Set' : '<span class="etp-sdc-muted">Not set</span>'; ?></td>
                                            <td>
                                                <a href="<?php echo \esc_url(\add_query_arg(array('page' => DEPLOYED_DIRECTORIES_SLUG, 'edit_directory' => $directory['public_key']), \admin_url('admin.php'))); ?>">Edit</a>
                                                <span class="trash"><?php render_deployed_directory_delete_button($directory['public_key']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="etp-sdc-panel">
                <h2><?php echo $edit !== '' ? 'Edit deployed directory' : 'Add deployed directory'; ?></h2>
                <div class="etp-sdc-panel-body">
                    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                        <?php \wp_nonce_field('etp_sdc_action'); ?>
                        <input type="hidden" name="action" value="etp_sdc_save">
                        <input type="hidden" name="etp_sdc_action" value="save_deployed_directory">
                        <input type="hidden" name="original_public_key" value="<?php echo \esc_attr($edit); ?>">
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-directory-name">Directory name</label>
                            <input id="etp-sdc-directory-name" type="text" name="name" value="<?php echo \esc_attr($editing['name']); ?>" required>
                        </div>
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-public-key">Public directory key</label>
                            <input id="etp-sdc-public-key" type="text" name="public_key" value="<?php echo \esc_attr($editing['public_key']); ?>" placeholder="partner-friendly-key" required>
                            <p class="description">Use lowercase letters, numbers, and hyphens.</p>
                        </div>
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-directory-app-id">Qlik app id</label>
                            <input id="etp-sdc-directory-app-id" type="text" name="app_id" value="<?php echo \esc_attr($editing['app_id']); ?>" required>
                        </div>
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-directory-access-code">Qlik access code</label>
                            <input id="etp-sdc-directory-access-code" type="text" name="access_code" value="<?php echo \esc_attr($editing['access_code']); ?>">
                            <p class="description">Stored server-side and applied when partner links use this public directory key.</p>
                        </div>
                        <?php \submit_button($edit !== '' ? 'Save directory' : 'Add directory'); ?>
                        <?php if ($edit !== '') : ?>
                            <a class="button" href="<?php echo \esc_url(\add_query_arg(array('page' => DEPLOYED_DIRECTORIES_SLUG), \admin_url('admin.php'))); ?>">Cancel edit</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * @param string $public_key Public key.
 */
function render_deployed_directory_delete_button($public_key) {
    ?>
    <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Delete this deployed directory?');">
        <?php \wp_nonce_field('etp_sdc_action'); ?>
        <input type="hidden" name="action" value="etp_sdc_save">
        <input type="hidden" name="etp_sdc_action" value="delete_deployed_directory">
        <input type="hidden" name="public_key" value="<?php echo \esc_attr($public_key); ?>">
        <button type="submit" class="button-link-delete">Delete</button>
    </form>
    <?php
}

/**
 * Render partner iframe link generator.
 */
function render_iframe_links_page() {
    if (!\current_user_can(CAPABILITY)) {
        \wp_die(\esc_html__('You do not have permission to generate partner iframe links.', 'etp-sdc'));
    }

    $embed_base_url = get_embed_base_url();
    $directories = \array_values(get_deployed_directories());
    ?>
    <div class="wrap etp-sdc-wrap">
        <div class="etp-sdc-header">
            <div>
                <h1>Partner Iframe Links</h1>
                <p>Create partner-ready iframe code using public directory keys and optional theme customization.</p>
            </div>
        </div>

        <?php if ($embed_base_url === '' || empty($directories)) : ?>
            <div class="notice notice-warning">
                <p>A WordPress admin must configure the global embed page URL and at least one deployed directory before iframe links can be generated.</p>
            </div>
        <?php endif; ?>

        <div class="etp-sdc-wizard" data-etp-iframe-wizard data-embed-base-url="<?php echo \esc_attr($embed_base_url); ?>">
            <div>
                <div class="etp-sdc-panel">
                    <h2>1. Select directory</h2>
                    <div class="etp-sdc-panel-body">
                        <div class="etp-sdc-field">
                            <label for="etp-sdc-iframe-directory">Deployed searchable directory</label>
                            <select id="etp-sdc-iframe-directory" data-etp-iframe-directory <?php echo empty($directories) ? 'disabled' : ''; ?>>
                                <option value="">Select a directory</option>
                                <?php foreach ($directories as $directory) : ?>
                                    <option value="<?php echo \esc_attr($directory['public_key']); ?>" data-name="<?php echo \esc_attr($directory['name']); ?>">
                                        <?php echo \esc_html($directory['name'] . ' (' . $directory['public_key'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Partner URLs use the public key, not the Qlik app id.</p>
                        </div>
                    </div>
                </div>

                <div class="etp-sdc-panel">
                    <h2>2. Choose configuration</h2>
                    <div class="etp-sdc-panel-body">
                        <label><input type="radio" name="etp_iframe_mode" value="default" data-etp-iframe-mode checked> Use default configuration</label><br>
                        <label><input type="radio" name="etp_iframe_mode" value="custom" data-etp-iframe-mode> Customize theme</label>
                    </div>
                </div>

                <div class="etp-sdc-panel etp-sdc-hidden" data-etp-theme-fields>
                    <h2>3. Customize theme with live preview</h2>
                    <div class="etp-sdc-panel-body">
                        <?php
                        $theme_color_groups = array(
                            'Brand colors' => array(
                                'primary' => array('Primary', '087f83'),
                                'primary_dark' => array('Primary dark', '005f62'),
                                'primary_soft' => array('Primary soft', 'd8f0ee'),
                                'secondary' => array('Secondary', '276fbf'),
                                'secondary_soft' => array('Secondary soft', 'e5f0ff'),
                            ),
                            'Page colors' => array(
                                'text' => array('Text', '0b1828'),
                                'muted' => array('Muted', '58677a'),
                                'line' => array('Lines', 'd7e2ea'),
                                'paper' => array('Page background', 'f4f8fa'),
                                'surface' => array('Cards and panels', 'ffffff'),
                            ),
                            'Highlights' => array(
                                'accent' => array('Accent', 'f3ad2e'),
                                'accent_soft' => array('Accent soft', 'fff3d5'),
                                'success' => array('Success', '2f8a52'),
                                'success_soft' => array('Success soft', 'dcf3e4'),
                                'danger' => array('Alert', 'd95846'),
                            ),
                        );
                        ?>
                        <div class="etp-sdc-theme-editor">
                            <div>
                                <?php foreach ($theme_color_groups as $group_label => $color_fields) : ?>
                                    <div class="etp-sdc-theme-group">
                                        <h3><?php echo \esc_html($group_label); ?></h3>
                                        <div class="etp-sdc-color-grid">
                                            <?php foreach ($color_fields as $param => $field) : ?>
                                                <div class="etp-sdc-color-control">
                                                    <label for="etp-sdc-theme-<?php echo \esc_attr($param); ?>"><?php echo \esc_html($field[0]); ?></label>
                                                    <div class="etp-sdc-swatch-row">
                                                        <input type="color" data-etp-theme-color-picker="<?php echo \esc_attr($param); ?>" value="#<?php echo \esc_attr($field[1]); ?>" aria-label="<?php echo \esc_attr($field[0]); ?> color picker">
                                                        <input id="etp-sdc-theme-<?php echo \esc_attr($param); ?>" type="text" data-etp-theme-param="<?php echo \esc_attr($param); ?>" data-preview-default="<?php echo \esc_attr($field[1]); ?>" placeholder="<?php echo \esc_attr($field[1]); ?>">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="etp-sdc-theme-group">
                                    <h3>Typography</h3>
                                    <div class="etp-sdc-font-grid">
                                        <div class="etp-sdc-field">
                                            <label for="etp-sdc-font-family">Body font</label>
                                            <input id="etp-sdc-font-family" type="text" data-etp-theme-param="font_family" data-preview-default="Inter, Arial, sans-serif" placeholder="Inter, Arial, sans-serif">
                                        </div>
                                        <div class="etp-sdc-field">
                                            <label for="etp-sdc-heading-font">Heading font</label>
                                            <input id="etp-sdc-heading-font" type="text" data-etp-theme-param="heading_font" data-preview-default="Georgia, serif" placeholder="Georgia, serif">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="etp-sdc-preview-shell">
                                <div class="etp-sdc-preview-toolbar">
                                    <strong>Live preview</strong>
                                    <span>Updates as you edit</span>
                                </div>
                                <div class="etp-sdc-live-preview" data-etp-theme-preview>
                                    <div class="etp-sdc-live-hero">
                                        <span class="etp-sdc-live-eyebrow">Participating Organization Directory</span>
                                        <h3>Find the right organization faster.</h3>
                                        <p>Live Qlik data with a friendlier search, filter, and contact experience layered on top.</p>
                                    </div>
                                    <div class="etp-sdc-live-search">
                                        <div class="etp-sdc-live-input">Search by organization, county, service, or keyword</div>
                                        <div class="etp-sdc-live-button">Search Directory</div>
                                    </div>
                                    <div class="etp-sdc-live-body">
                                        <div class="etp-sdc-live-filter">
                                            <strong>Filters</strong>
                                            <span class="etp-sdc-live-pill">Emergency help</span>
                                            <span class="etp-sdc-live-pill">Legal support</span>
                                            <span class="etp-sdc-live-pill">Shelter</span>
                                        </div>
                                        <div class="etp-sdc-live-main">
                                            <h4>5 matching organizations</h4>
                                            <p>Showing results for Chester, Awareness, Children, and more.</p>
                                            <div class="etp-sdc-live-cards">
                                                <div class="etp-sdc-live-card">
                                                    <h5>American Job Center Northwest Tennessee</h5>
                                                    <p><strong>Serves:</strong> Chester<br><strong>Type:</strong> Business</p>
                                                    <span class="etp-sdc-live-tag">Prevention</span>
                                                    <div class="etp-sdc-live-actions"><span>Call</span><span>Email</span></div>
                                                </div>
                                                <div class="etp-sdc-live-card">
                                                    <h5>Greystone Foster Care</h5>
                                                    <p>Therapeutic services and support for youth and families.</p>
                                                    <span class="etp-sdc-live-tag etp-sdc-live-danger">Vulnerability</span>
                                                    <div class="etp-sdc-live-actions"><span>Call</span><span>Email</span></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="etp-sdc-panel">
                <h2>4. Generated iframe</h2>
                <div class="etp-sdc-panel-body">
                    <p class="etp-sdc-muted">Copy the iframe HTML for the partner, or copy only the iframe source URL.</p>
                    <div class="etp-sdc-field">
                        <label for="etp-sdc-iframe-src">Iframe source URL</label>
                        <input id="etp-sdc-iframe-src" type="text" data-etp-iframe-src readonly>
                    </div>
                    <div class="etp-sdc-field">
                        <label for="etp-sdc-iframe-html">Iframe HTML</label>
                        <textarea id="etp-sdc-iframe-html" class="etp-sdc-generated" data-etp-iframe-html readonly></textarea>
                    </div>
                    <div class="etp-sdc-copy-row">
                        <button type="button" class="button button-primary" data-etp-copy-target="[data-etp-iframe-html]">Copy iframe HTML</button>
                        <button type="button" class="button" data-etp-copy-target="[data-etp-iframe-src]">Copy source URL</button>
                    </div>
                    <p class="description" data-etp-copy-status></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render JSON status page.
 */
function render_json_status_page() {
    if (!\current_user_can(CAPABILITY)) {
        \wp_die(\esc_html__('You do not have permission to manage this configuration.', 'etp-sdc'));
    }

    $config = get_config();
    $json = \wp_json_encode(public_json_from_config($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="wrap etp-sdc-wrap">
        <?php render_notice(\sanitize_key($_GET['etp_sdc_notice'] ?? '')); ?>
        <div class="etp-sdc-header">
            <div>
                <h1>Emergency Help JSON Status</h1>
                <p>ETP Searchable Directory Config / Emergency Help. Confirm what the directory will load.</p>
            </div>
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                <?php \wp_nonce_field('etp_sdc_action'); ?>
                <input type="hidden" name="action" value="etp_sdc_save">
                <input type="hidden" name="etp_sdc_action" value="regenerate_json">
                <button class="button button-primary">Regenerate JSON</button>
            </form>
        </div>
        <div class="etp-sdc-grid">
            <div class="etp-sdc-panel">
                <h2>Public JSON file</h2>
                <div class="etp-sdc-panel-body">
                    <p><strong>Use this URL in data-emergency-help-url</strong></p>
                    <div class="etp-sdc-url"><code><?php echo \esc_html(json_file_url()); ?></code></div>
                    <p><strong>Last published:</strong> <?php echo \esc_html(\get_option('etp_sdc_last_published_at', 'Not published yet')); ?></p>
                    <div class="etp-sdc-status-list">
                        <div><span>✓</span>Only <?php echo \esc_html(ROLE_NAME); ?> users can access this subsection</div>
                        <div><span>✓</span>Default fallback includes 911 and National Human Trafficking Hotline content</div>
                        <div><span>✓</span>The current JSON format matches the directory frontend</div>
                    </div>
                </div>
            </div>
            <div class="etp-sdc-panel">
                <h2>JSON preview</h2>
                <div class="etp-sdc-panel-body">
                    <pre><?php echo \esc_html((string) $json); ?></pre>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render access settings page.
 */
function render_access_settings_page() {
    ?>
    <div class="wrap etp-sdc-wrap">
        <h1>Access Settings</h1>
        <div class="etp-sdc-panel">
            <h2><?php echo \esc_html(ROLE_NAME); ?></h2>
            <div class="etp-sdc-panel-body">
                <p>This plugin is visible only to users who have the <code><?php echo \esc_html(CAPABILITY); ?></code> capability.</p>
                <p>Assign the <strong><?php echo \esc_html(ROLE_NAME); ?></strong> role from the standard WordPress user profile screen.</p>
            </div>
        </div>
    </div>
    <?php
}
