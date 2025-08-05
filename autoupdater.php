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
     * Package Slug (plugin_directory/package_file.php)
     * @var string
     */
    public $package_slug;
    /**
     * Package name (package_file)
     * @var string
     */
    public $slug;
    /**
     * Initialize a new instance of the WordPress Auto-Update class
     * @param string $package_file
     * @param string $update_path
     * @param string $package_slug
     */
    function __construct($package_file)
    {
        $this->package_file = $package_file;
        // Set the class public variables
        $this->package_slug = plugin_basename($package_file);
        list($t1, $t2) = explode('/', $this->package_slug);
        $this->slug = str_replace('.php', '', $t2);
        // define the alternative API for updating checking
        add_filter('pre_set_site_transient_update_plugins', array(&$this, 'check_update'));
        // Define the alternative response for information checking
        add_filter('plugins_api', array(&$this, 'check_info'), 10, 3);
    }

    function getPluginData()
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($this->package_file);
        return $plugin_data;
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
        $plugin_data = $this->getPluginData();
        if (!is_array($plugin_data) || empty($plugin_data)) {
            return $transient;
        }
        $current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '1.0';
        if (version_compare($current_version, $meta_object->version, '<')) {
            $meta_object->slug = $this->slug;
            $meta_object->new_version = $meta_object->version;
            $meta_object->url = $meta_object->update_uri;
            $meta_object->package = $this->package_slug;
            $meta_object->package = trailingslashit($meta_object->update_uri) . 'download';
            // Add the package to the response
            $transient->response[$this->package_slug] = $meta_object;
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
        $plugin_data = $this->getPluginData();
        if (!is_array($plugin_data) || empty($plugin_data) || empty($plugin_data['UpdateURI'])) {
            return false;
        }
        $request = wp_remote_get(trailingslashit($plugin_data['UpdateURI']) . 'manifest');

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
