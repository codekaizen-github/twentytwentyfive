<?php

class Autoupdater
{
    public $package_file;
    /**
     * The package current version
     * @var string
     */
    public $current_version;
    /**
     * The package remote update path
     * @var string
     */
    public $update_path;
    /**
     * Whether to delete transients for testing
     * @var bool
     */
    public $delete_transients = false;
    /**
     * Package Slug (plugin_directory/package_file.php or theme_directory)
     * @var string
     */
    public $package_slug;
    /**
     * Package name (package_file)
     * @var string
     */
    public $slug;
    /**
     * Package type (plugin or theme)
     * @var string
     */
    public $package_type;
    /**
     * Initialize a new instance of the WordPress Auto-Update class
     * @param string $package_file
     */
    function __construct($package_file)
    {
        if ($this->delete_transients) {
            $this->delete_update_transients();
        }
        $this->package_file = $package_file;
        // Determine package type
        $this->package_type = $this->get_package_type();
        // Set the class public variables based on package type
        if ($this->package_type === 'plugin') {
            $this->package_slug = plugin_basename($package_file);
            list($t1, $t2) = explode('/', $this->package_slug);
            $this->slug = str_replace('.php', '', $t2);
            // define the alternative API for plugin updating checking
            add_filter('pre_set_site_transient_update_plugins', array(&$this, 'check_update'));
            // Define the alternative response for plugin information checking
            add_filter('plugins_api', array(&$this, 'check_info'), 10, 3);
        } elseif ($this->package_type === 'theme') {
            $theme_dir = basename(dirname($package_file));
            $this->package_slug = $theme_dir;
            $this->slug = $theme_dir;
            // define the alternative API for theme updating checking
            add_filter('pre_set_site_transient_update_themes', array(&$this, 'check_update'));
            // Define the alternative response for theme information checking
            add_filter('themes_api', array(&$this, 'check_info'), 10, 3);
        }
    }

    /**
     * Detect if the package_file is a plugin or a theme.
     * @return string|null 'plugin', 'theme', or null if not recognized.
     */
    public function get_package_type()
    {
        if (strpos($this->package_file, WP_PLUGIN_DIR) === 0) {
            return 'plugin';
        }
        if (strpos($this->package_file, get_theme_root()) === 0) {
            return 'theme';
        }
        return null;
    }

    /**
     * Get plugin data from file headers
     * @return array Plugin data
     */
    function get_plugin_data()
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($this->package_file);
        return $plugin_data;
    }

    /**
     * Get theme data from file headers
     * @return array Theme data
     */
    function get_theme_data()
    {
        if (! function_exists('wp_get_theme')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }
        $theme_data = wp_get_theme($this->slug);

        // Define all possible theme headers
        $headers = array(
            'Name' => 'Theme Name',
            'ThemeURI' => 'Theme URI',
            'Author' => 'Author',
            'AuthorURI' => 'Author URI',
            'Description' => 'Description',
            'Version' => 'Version',
            'RequiresWP' => 'Requires at least',
            'TestedWP' => 'Tested up to',
            'RequiresPHP' => 'Requires PHP',
            'License' => 'License',
            'LicenseURI' => 'License URI',
            'TextDomain' => 'Text Domain',
            'Tags' => 'Tags',
            'DomainPath' => 'Domain Path',
            'UpdateURI' => 'Update URI',
            'Template' => 'Template',
            'Status' => 'Status'
        );

        // Convert WP_Theme object to array for consistency
        $result = array();
        foreach ($headers as $key => $header) {
            $value = $theme_data->get($key);
            if ($value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get package data based on whether it's a plugin or theme
     * @return array Package data
     */
    function get_package_data()
    {
        if ($this->package_type === 'plugin') {
            return $this->get_plugin_data();
        } elseif ($this->package_type === 'theme') {
            return $this->get_theme_data();
        }
        return array();
    }

    /**
     * Delete update transients for testing purposes
     */
    public function delete_update_transients()
    {
        // Delete plugin-related transients
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
    }

    /**
     * Add our self-hosted autoupdate package to the filter transient
     *
     * @param $transient
     * @return object $ transient
     */
    public function check_update($transient)
    {

        if (empty($transient->checked)) {
            return $transient;
        }
        $meta = $this->get_remote_metadata();
        if (!is_array($meta)) {
            return $transient;
        }
        $meta_object = (object) $meta;
        if (!isset($meta_object->version)) {
            return $transient;
        }
        $package_data = $this->get_package_data();
        if (!is_array($package_data) || empty($package_data)) {
            return $transient;
        }
        $current_version = isset($package_data['Version']) ? $package_data['Version'] : '1.0';
        if (version_compare($current_version, $meta_object->version, '<')) {
            $meta_object->slug = $this->slug;
            $meta_object->new_version = $meta_object->version;
            $meta_object->url = $meta_object->update_uri;
            $meta_object->package = trailingslashit($meta_object->update_uri) . 'download';

            // Format response appropriately for plugins vs themes
            if ($this->package_type === 'theme') {
                // Themes require a specific format
                $theme_response = array(
                    'theme' => $this->slug,
                    'new_version' => $meta_object->version,
                    'url' => $meta_object->url,
                    'package' => $meta_object->package,
                );
                $transient->response[$this->package_slug] = $theme_response;
            } else {
                // Plugins can use the object as is
                $transient->response[$this->package_slug] = $meta_object;
            }
        }
        error_log('Transient: ' . print_r($transient, true));
        return $transient;
    }
    /**
     * Add our self-hosted description to the filter
     *
     * @param boolean $false
     * @param array $action
     * @param object $arg
     * @return bool|object
     */
    public function check_info($false, $action, $arg)
    {
        if ($arg->slug && $arg->slug === $this->slug) {
            $meta = $this->get_remote_metadata();
            return $meta ? (object) $meta : false;
        }
        return false;
    }

    /**
     * Fetch and cache remote package metadata from the update_path endpoint.
     * @return array|false
     */
    protected function get_remote_metadata()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $package_data = $this->get_package_data();
        if (!is_array($package_data) || empty($package_data) || empty($package_data['UpdateURI'])) {
            return false;
        }
        $request = wp_remote_get(trailingslashit($package_data['UpdateURI']) . 'manifest');

        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return false;
        }
        $body = wp_remote_retrieve_body($request);
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['annotations']['org.codekaizen-github.wp-package-deploy-oras.wp-package-metadata'])) {
            return false;
        }
        $meta_json = $json['annotations']['org.codekaizen-github.wp-package-deploy-oras.wp-package-metadata'];
        $meta = json_decode($meta_json, true);
        if (!is_array($meta)) {
            return false;
        }
        $cached = $meta;
        return $meta;
    }
}
