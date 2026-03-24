<?php
namespace RoxyWC;

if (!defined('ABSPATH')) exit;

class Updater {

  private static $config;

  public static function init($config) {
    self::$config = $config;
    add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
  }

  public static function check($transient) {

    $repo = self::$config['github_repo'];
    $slug = self::$config['slug'];
    $version = self::$config['version'];

    $response = wp_remote_get("https://api.github.com/repos/$repo/releases/latest", [
      'headers' => ['User-Agent' => 'WordPress']
    ]);

    if (is_wp_error($response)) return $transient;

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!$data || empty($data->tag_name)) return $transient;

    $latest = ltrim($data->tag_name, 'v');

    if (version_compare($latest, $version, '>')) {

      $package = '';

      foreach ($data->assets as $asset) {
        if (strpos($asset->name, $slug) === 0) {
          $package = $asset->browser_download_url;
          break;
        }
      }

      if ($package) {
        $transient->response[self::$config['plugin_file']] = (object)[
          'slug' => $slug,
          'new_version' => $latest,
          'package' => $package,
          'url' => $data->html_url
        ];
      }
    }

    return $transient;
  }
}
