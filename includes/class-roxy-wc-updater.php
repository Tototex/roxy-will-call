<?php
namespace RoxyWC;

if (!defined('ABSPATH')) exit;

class Updater {

    private static array $config = [];
    private static ?array $release = null;

    public static function init(array $config): void {
        self::$config = wp_parse_args($config, [
            'plugin_file' => '',
            'version'     => '',
            'github_repo' => '',
            'slug'        => '',
            'name'        => 'Plugin',
        ]);

        if (
            self::$config['plugin_file'] === '' ||
            self::$config['version'] === '' ||
            self::$config['github_repo'] === '' ||
            self::$config['slug'] === ''
        ) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'filter_update_plugins']);
        add_filter('plugins_api', [__CLASS__, 'filter_plugins_api'], 20, 3);
        add_filter('upgrader_post_install', [__CLASS__, 'filter_upgrader_post_install'], 20, 3);
        add_action('upgrader_process_complete', [__CLASS__, 'handle_upgrader_process_complete'], 20, 2);
    }

    private static function get_cache_key(): string {
        return 'roxy_updater_' . md5(self::$config['github_repo'] . '|' . self::$config['slug']);
    }

    public static function filter_update_plugins($transient) {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $transient;
        }

        $plugin_file = self::$config['plugin_file'];
        $current_version = (string) self::$config['version'];
        $new_version = (string) $release['version'];

        if (version_compare($new_version, $current_version, '>')) {
            unset($transient->no_update[$plugin_file]);
            $transient->response[$plugin_file] = (object) [
                'slug'        => self::$config['slug'],
                'plugin'      => $plugin_file,
                'new_version' => $new_version,
                'package'     => $release['download_url'],
                'url'         => $release['html_url'],
            ];
            return $transient;
        }

        unset($transient->response[$plugin_file]);
        $transient->no_update[$plugin_file] = (object) [
            'slug'        => self::$config['slug'],
            'plugin'      => $plugin_file,
            'new_version' => $current_version,
            'package'     => '',
            'url'         => $release['html_url'],
        ];

        return $transient;
    }

    public static function filter_plugins_api($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$config['slug']) {
            return $result;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => self::$config['name'],
            'slug'          => self::$config['slug'],
            'version'       => $release['version'],
            'download_link' => $release['download_url'],
            'sections'      => [
                'description' => 'Auto updates from GitHub.',
            ],
        ];
    }

    public static function filter_upgrader_post_install($response, $hook_extra, $result) {
        if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] === self::$config['plugin_file']) {
            activate_plugin(self::$config['plugin_file']);
        }

        return $response;
    }

    public static function handle_upgrader_process_complete($upgrader, $hook_extra): void {
        if (empty(self::$config['plugin_file'])) {
            return;
        }

        $action = isset($hook_extra['action']) ? (string) $hook_extra['action'] : '';
        $type = isset($hook_extra['type']) ? (string) $hook_extra['type'] : '';
        $plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) ? $hook_extra['plugins'] : [];

        if ($action !== 'update' || $type !== 'plugin') {
            return;
        }

        if (!in_array(self::$config['plugin_file'], $plugins, true)) {
            return;
        }

        self::clear_update_cache();
    }

    private static function clear_update_cache(): void {
        self::$release = null;
        delete_site_transient(self::get_cache_key());
        delete_site_transient('update_plugins');

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }

    private static function get_latest_release(): ?array {
        if (self::$release !== null) {
            return self::$release;
        }

        $cache_key = self::get_cache_key();
        $cached = get_site_transient($cache_key);
        if (is_array($cached) && !empty($cached['version']) && !empty($cached['download_url'])) {
            self::$release = $cached;
            return self::$release;
        }

        $url = 'https://api.github.com/repos/' . self::$config['github_repo'] . '/releases/latest';
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name']) || empty($data['assets']) || !is_array($data['assets'])) {
            return null;
        }

        $download_url = '';
        foreach ($data['assets'] as $asset) {
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $browser_download_url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

            if (
                $name !== '' &&
                strpos($name, self::$config['slug']) === 0 &&
                substr($name, -4) === '.zip' &&
                $browser_download_url !== ''
            ) {
                $download_url = $browser_download_url;
                break;
            }
        }

        if ($download_url === '') {
            return null;
        }

        self::$release = [
            'version'      => ltrim((string) $data['tag_name'], 'v'),
            'download_url' => $download_url,
            'html_url'     => (string) ($data['html_url'] ?? ''),
        ];

        set_site_transient($cache_key, self::$release, 10 * MINUTE_IN_SECONDS);

        return self::$release;
    }
}
