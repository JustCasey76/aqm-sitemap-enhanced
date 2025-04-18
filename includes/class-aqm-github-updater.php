<?php
/**
 * AQM Manual Update Notifier Class
 * 
 * This class provides manual update notifications for the AQM Sitemap plugin.
 * It does NOT handle automatic updates, only notifications.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class AQM_Sitemap_GitHub_Updater {
    private $plugin_file;
    private $plugin_data;
    private $github_username;
    private $github_repository;
    private $current_version;
    private $latest_version;
    private $download_url;

    /**
     * Class constructor
     * 
     * @param string $plugin_file Path to the plugin file
     * @param string $github_username GitHub username
     * @param string $github_repository GitHub repository name
     */
    public function __construct($plugin_file, $github_username, $github_repository) {
        $this->plugin_file = $plugin_file;
        $this->github_username = $github_username;
        $this->github_repository = $github_repository;
        
        // Get plugin data
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data($this->plugin_file);
        $this->current_version = $this->plugin_data['Version'];
        
        // Add admin notice for updates
        add_action('admin_init', array($this, 'check_for_updates'));
        add_action('admin_notices', array($this, 'show_update_notification'));

        // Set plugin slug for update API
        $this->slug = plugin_basename($this->plugin_file);
        $this->plugin_slug = dirname($this->slug); // Just the directory name

        // Enable one-click updates from the Plugins page
        add_filter('pre_set_site_transient_update_plugins', array($this, 'set_transient'));
        add_filter('plugins_api', array($this, 'set_plugin_info'), 10, 3);

        // Add "Check for Updates" link to plugin actions
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), array($this, 'add_plugin_action_links'));

        // Add AJAX handler for manual check
        add_action('wp_ajax_aqm_check_for_updates', array($this, 'ajax_check_for_updates'));
        
        // Add hook to check for manual update checks from the plugins page
        add_action('admin_init', array($this, 'maybe_check_for_updates'));
        
        // Add hooks for handling GitHub releases during updates
        add_filter('upgrader_source_selection', array($this, 'rename_github_folder'), 10, 4);
        add_filter('upgrader_package_options', array($this, 'modify_package_options'));
        add_filter('upgrader_pre_download', array($this, 'modify_download_package'), 10, 4);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
        
        // Store the plugin directory name for later use
        update_option('aqm_sitemap_plugin_slug', $this->plugin_slug);
    }


    /**
     * Check for updates from GitHub
     */
    public function check_for_updates() {
        // Check if we've already checked recently (transient)
        $update_data = get_transient('aqm_sitemap_update_data');
        
        if (false === $update_data || isset($_GET['force-check']) || (defined('DOING_AJAX') && DOING_AJAX)) {
            // Get latest release from GitHub API
            $url = 'https://api.github.com/repos/' . $this->github_username . '/' . $this->github_repository . '/releases/latest';
            
            // Get API response
            $response = wp_remote_get($url, array(
                'sslverify' => true,
                'user-agent' => 'WordPress/' . get_bloginfo('version')
            ));
            
            // Check for errors
            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
                error_log('AQM Sitemap: Error checking for updates - ' . wp_remote_retrieve_response_message($response));
                return;
            }
            
            // Parse response
            $response_body = wp_remote_retrieve_body($response);
            $release_data = json_decode($response_body);
            
            // Check if we have valid data
            if (empty($release_data) || !is_object($release_data)) {
                error_log('AQM Sitemap: Invalid GitHub API response');
                return;
            }
            
            // Get version number (remove 'v' prefix if present)
            $this->latest_version = ltrim($release_data->tag_name, 'v');
            
            // Set download URL
            $this->download_url = 'https://github.com/' . $this->github_username . '/' . $this->github_repository . '/archive/refs/tags/' . $release_data->tag_name . '.zip';
            
            // Store update data in transient (cache for 12 hours)
            $update_data = array(
                'version' => $this->latest_version,
                'download_url' => $this->download_url,
                'last_checked' => time(),
                'changelog' => isset($release_data->body) ? $release_data->body : ''
            );
            
            set_transient('aqm_sitemap_update_data', $update_data, 12 * HOUR_IN_SECONDS);
        } else {
            // Use cached data
            $this->latest_version = $update_data['version'];
            $this->download_url = $update_data['download_url'];
        }
    }

    /**
     * Show update notification in admin
     */
    public function show_update_notification() {
        // Only show to users who can update plugins
        if (!current_user_can('update_plugins')) {
            return;
        }
        
        // Make sure we have version data
        if (empty($this->latest_version)) {
            return;
        }
        
        // Check if there's a new version available
        if (version_compare($this->latest_version, $this->current_version, '>')) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>AQM Enhanced Sitemap Update Available!</strong></p>';
            echo '<p>Version ' . esc_html($this->latest_version) . ' is available. You are currently using version ' . esc_html($this->current_version) . '.</p>';
            echo '</div>';
        }
    }
    
    /**
     * AJAX handler for checking updates
     */
    public function ajax_check_for_updates() {
        // Security check
        if (!current_user_can('update_plugins')) {
            wp_die('Unauthorized access');
        }
        
        // Force update check
        delete_transient('aqm_sitemap_update_data');
        $this->check_for_updates();
        
        $has_update = version_compare($this->latest_version, $this->current_version, '>');
        
        wp_send_json(array(
            'success' => true,
            'has_update' => $has_update,
            'current_version' => $this->current_version,
            'latest_version' => $this->latest_version,
            'download_url' => $this->download_url
        ));
    }
    
    /**
     * Add plugin action links
     * 
     * @param array $links Existing action links
     * @return array Modified action links
     */
    public function add_plugin_action_links($links) {
        // Add a "Check for Updates" link
        $check_update_link = '<a href="' . wp_nonce_url(admin_url('plugins.php?aqm_check_for_updates=1&plugin=' . $this->slug), 'aqm-check-update') . '">Check for Updates</a>';
        array_unshift($links, $check_update_link);
        return $links;
    }

    /**
     * Get repository API info from GitHub
     * 
     * @return array|false GitHub API data or false on failure
     */
    private function get_repository_info() {
        if (!empty($this->github_response)) {
            return $this->github_response;
        }

        // Set a reasonable timeout
        $timeout = 10;
        
        // GitHub API URL to fetch release info - get latest release directly
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repository}/releases/latest";
        
        // Log the API URL we're using
        error_log('AQM Sitemap: Checking GitHub API: ' . $url);
        
        // Include access token if available
        if (!empty($this->access_token)) {
            $url = add_query_arg(array('access_token' => $this->access_token), $url);
        }
        

        
        // Send remote request
        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));
        
        // Check for errors
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            error_log('AQM Sitemap: GitHub API request failed. ' . wp_remote_retrieve_response_message($response));
            return false;
        }
        
        // Parse response
        $response_body = wp_remote_retrieve_body($response);
        $latest_release = json_decode($response_body);
        
        // Log the API response for debugging
        error_log('AQM Sitemap: GitHub API response received');
        
        // Check if response is valid
        if (is_object($latest_release) && !empty($latest_release)) {
            
            // Store the result with release assets
            $assets = array();
            if (isset($latest_release->assets) && is_array($latest_release->assets)) {
                foreach ($latest_release->assets as $asset) {
                    $assets[] = array(
                        'name' => isset($asset->name) ? $asset->name : '',
                        'browser_download_url' => isset($asset->browser_download_url) ? $asset->browser_download_url : '',
                        'size' => isset($asset->size) ? $asset->size : 0,
                        'created_at' => isset($asset->created_at) ? $asset->created_at : '',
                    );
                }
            }
            
            $this->github_response = array(
                'tag_name' => isset($latest_release->tag_name) ? $latest_release->tag_name : null,
                'published_at' => isset($latest_release->published_at) ? $latest_release->published_at : null,
                'zipball_url' => isset($latest_release->zipball_url) ? $latest_release->zipball_url : null,
                'body' => isset($latest_release->body) ? $latest_release->body : '',
                'assets' => $assets,
            );
            
            // Log the assets found
            if (!empty($assets)) {
                error_log('AQM Sitemap: Found ' . count($assets) . ' release assets');
            } else {
                error_log('AQM Sitemap: No release assets found');
            }
            
            return $this->github_response;
        }
        
        return false;
    }

    /**
     * Update the plugin transient with update info if available
     * 
     * @param object $transient Plugins update transient
     * @return object Modified transient with GitHub update info
     */
    public function set_transient($transient) {
        // If we're checking for updates, get the latest release info
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get GitHub info
        $github_data = $this->get_repository_info();
        
        // If we have valid data
        if ($github_data && isset($github_data['tag_name'])) {
            // Get current version and remove leading 'v' if present in tag name
            $current_version = $this->plugin_data['Version'];
            $latest_version = ltrim($github_data['tag_name'], 'v');
            
            // Check if we need to update
            if (version_compare($latest_version, $current_version, '>')) {
                error_log('AQM Sitemap: Update available - current: ' . $current_version . ', latest: ' . $latest_version);
                
                // Instead of using GitHub's auto-generated archive, we'll look for a custom release asset
                // This allows for properly structured ZIP files to be uploaded to GitHub releases
                $download_url = '';
                
                // Check if there are any release assets
                if (isset($github_data['assets']) && !empty($github_data['assets'])) {
                    foreach ($github_data['assets'] as $asset) {
                        // Look for a ZIP file with the plugin name
                        if (isset($asset['browser_download_url']) && strpos($asset['name'], '.zip') !== false) {
                            $download_url = $asset['browser_download_url'];
                            error_log('AQM Sitemap: Found custom release asset: ' . $asset['name']);
                            break;
                        }
                    }
                }
                
                // If no custom asset was found, fall back to the standard GitHub archive URL
                if (empty($download_url)) {
                    $download_url = 'https://github.com/' . $this->github_username . '/' . $this->github_repository . '/archive/refs/tags/' . $github_data['tag_name'] . '.zip';
                    error_log('AQM Sitemap: No custom release asset found, using standard GitHub archive URL');
                }
                
                // Log the download URL we're using
                error_log('AQM Sitemap: Using download URL: ' . $download_url);
                
                // Prepare the update object
                $obj = new stdClass();
                $obj->slug = dirname($this->slug); // Use directory name as slug
                $obj->plugin = $this->slug; // Full path including main file
                $obj->new_version = $latest_version;
                $obj->url = $this->plugin_data['PluginURI'];
                $obj->package = $download_url;
                $obj->tested = '6.5'; // Tested up to this WordPress version
                $obj->icons = array(
                    '1x' => 'https://ps.w.org/plugin-directory-assets/icon-256x256.png', // Default icon
                    '2x' => 'https://ps.w.org/plugin-directory-assets/icon-256x256.png'  // Default icon
                );
                
                // Add it to the response
                $transient->response[$this->slug] = $obj;
                
                // Store that we're going to update this plugin
                update_option('aqm_sitemap_updating_to_version', $latest_version);
                update_option('aqm_sitemap_was_active', is_plugin_active($this->slug));
            } else {
                // No update needed, just add to no_update
                $obj = new stdClass();
                $obj->slug = dirname($this->slug); // Use directory name as slug
                $obj->plugin = $this->slug; // Full path including main file
                $obj->new_version = $current_version;
                $obj->url = $this->plugin_data['PluginURI'];
                $obj->package = '';
                $obj->tested = '6.5';
                $obj->icons = array(
                    '1x' => 'https://ps.w.org/plugin-directory-assets/icon-256x256.png', // Default icon
                    '2x' => 'https://ps.w.org/plugin-directory-assets/icon-256x256.png'  // Default icon
                );
                
                $transient->no_update[$this->slug] = $obj;
            }
            
            // Return the modified transient
            return $transient;
        }
        
        // If we don't have GitHub data, return the original transient
        return $transient;
    }

    /**
     * Set plugin info for View Details screen
     * 
     * @param false|object|array $result The result object or array
     * @param string $action The type of information being requested
     * @param object $args Plugin API arguments
     * @return object|false Plugin info or false
     */
    public function set_plugin_info($result, $action, $args) {
        // Check if this API call is for this plugin
        if (empty($args->slug) || $args->slug !== dirname($this->slug)) {
            return $result;
        }
        
        // Check for "plugin_information" action
        if ('plugin_information' !== $action) {
            return $result;
        }
        
        // Get GitHub data
        $github_data = $this->get_repository_info();
        
        if (!$github_data) {
            return $result;
        }
        
        // Remove 'v' prefix from tag name for version
        $version = ltrim($github_data['tag_name'], 'v');
        
        // Create plugin info object
        $plugin_info = new stdClass();
        $plugin_info->name = $this->plugin_data['Name'];
        $plugin_info->slug = dirname($this->slug);
        $plugin_info->version = $version;
        $plugin_info->author = $this->plugin_data['Author'];
        $plugin_info->homepage = $this->plugin_data['PluginURI'];
        $plugin_info->requires = '5.0'; // Minimum WordPress version
        $plugin_info->tested = '6.5'; // Tested up to this WordPress version
        $plugin_info->downloaded = 0; // We don't track downloads
        $plugin_info->last_updated = $github_data['published_at'];
        $plugin_info->sections = array(
            'description' => $this->plugin_data['Description'],
            'changelog' => $this->format_github_changelog($github_data['body']),
        );
        $plugin_info->download_link = $github_data['zipball_url'];
        
        return $plugin_info;
    }

    /**
     * Check if we should manually check for updates (from the plugins page)
     */
    public function maybe_check_for_updates() {
        if (isset($_GET['aqm_check_for_updates']) && $_GET['aqm_check_for_updates'] == '1') {
            // Verify nonce
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'aqm-check-update')) {
                wp_die('Security check failed');
            }
            
            // Check if this is our plugin
            if (isset($_GET['plugin']) && $_GET['plugin'] == $this->slug) {
                // Clear all transients to force a completely fresh check
                delete_transient('aqm_sitemap_update_data');
                delete_site_transient('update_plugins');
                delete_site_transient('aqm_github_data');
                
                // Force a fresh check for updates
                $this->github_response = null; // Clear any cached response
                $this->check_for_updates(); // Force a new check
                
                // Force WordPress to check for updates
                wp_clean_plugins_cache(true);
                
                // Log the update check
                error_log('AQM Sitemap: Manual update check triggered for ' . $this->slug);
                
                // Redirect back to the plugins page
                wp_redirect(admin_url('plugins.php?aqm_checked=1'));
                exit;
            }
        }
        
        // Show admin notice after checking for updates
        if (isset($_GET['aqm_checked']) && $_GET['aqm_checked'] == '1') {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>AQM Enhanced Sitemap: Checked for updates. If an update is available, you will see an update notification.</p></div>';
            });
        }
    }

    /**
     * Format GitHub release notes as changelog
     * 
     * @param string $release_notes GitHub release notes
     * @return string Formatted HTML changelog
     */
    public function format_github_changelog($release_notes) {
        // Convert markdown to HTML if needed
        if (function_exists('Markdown')) {
            $changelog = Markdown($release_notes);
        } else {
            // Basic formatting - handle lists and headers
            $changelog = '<pre>' . esc_html($release_notes) . '</pre>';
        }
        
        return $changelog;
    }

    /**
     * Rename the GitHub downloaded folder to match our plugin's directory name
     * This prevents WordPress from creating a new directory with the GitHub format
     * 
     * @param string $source The source directory path
     * @param string $remote_source The remote source directory path
     * @param object $upgrader The WordPress upgrader object
     * @param array $args Additional arguments
     * @return string Modified source path
     */
    public function rename_github_folder($source, $remote_source, $upgrader, $args = array()) {
        global $wp_filesystem;
        
        // Log all parameters for debugging
        error_log('AQM Sitemap Debug - Source: ' . $source);
        error_log('AQM Sitemap Debug - Remote Source: ' . $remote_source);
        error_log('AQM Sitemap Debug - Args: ' . print_r($args, true));
        
        // Get the plugin slug from options
        $plugin_slug = get_option('aqm_sitemap_plugin_slug', 'aqm-sitemap-enhanced');
        
        // Get the basename of the source directory
        $basename = basename($source);
        error_log('AQM Sitemap Debug - Basename: ' . $basename);
        
        // If the directory is already correctly named, return it
        if ($basename === $plugin_slug) {
            error_log('AQM Sitemap: Directory already has correct name: ' . $plugin_slug);
            return $source;
        }
        
        // Create the path for the correctly named directory
        $new_source = trailingslashit(dirname($source)) . $plugin_slug;
        error_log('AQM Sitemap: Attempting to rename ' . $source . ' to ' . $new_source);
        
        // Ensure WordPress filesystem is initialized
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // If the target directory already exists, remove it
        if ($wp_filesystem->exists($new_source)) {
            error_log('AQM Sitemap: Target directory already exists, removing it');
            $wp_filesystem->delete($new_source, true);
        }
        
        // Check if the source directory contains the main plugin file or if it has a nested structure
        $main_file_path = trailingslashit($source) . $plugin_slug . '.php';
        $nested_dir_path = trailingslashit($source) . $plugin_slug;
        
        if ($wp_filesystem->exists($main_file_path)) {
            // The main plugin file exists directly in the source directory
            error_log('AQM Sitemap: Main plugin file found in source directory');
            
            // Simply rename the directory
            if ($wp_filesystem->move($source, $new_source)) {
                error_log('AQM Sitemap: Successfully renamed directory to ' . $plugin_slug);
                return $new_source;
            } else {
                error_log('AQM Sitemap: Failed to rename directory');
            }
        } elseif ($wp_filesystem->exists($nested_dir_path)) {
            // The plugin is in a nested directory with the correct name
            error_log('AQM Sitemap: Plugin found in nested directory: ' . $nested_dir_path);
            
            // Move the nested directory to the correct location
            if ($wp_filesystem->move($nested_dir_path, $new_source)) {
                error_log('AQM Sitemap: Successfully moved nested directory to correct location');
                // Clean up the original source directory
                $wp_filesystem->delete($source, true);
                return $new_source;
            } else {
                error_log('AQM Sitemap: Failed to move nested directory');
            }
        } else {
            // Check if this is a GitHub release with a different structure
            $github_dir_pattern = '/^' . preg_quote($plugin_slug, '/') . '-\d+\.\d+\.\d+(-\w+)?$/';
            $github_user_pattern = '/^' . preg_quote($this->github_username, '/') . '-' . preg_quote($plugin_slug, '/') . '-[a-f0-9]+$/';
            
            if (preg_match($github_dir_pattern, $basename) || preg_match($github_user_pattern, $basename)) {
                error_log('AQM Sitemap: GitHub release directory structure detected');
                
                // Create the new directory
                if (!$wp_filesystem->mkdir($new_source)) {
                    error_log('AQM Sitemap: Failed to create target directory');
                    return $source;
                }
                
                // Copy all files from source to the new directory
                $this->recursive_copy_dir($source, $new_source, $wp_filesystem);
                
                // Verify the copy was successful by checking for the main plugin file
                if ($wp_filesystem->exists(trailingslashit($new_source) . $plugin_slug . '.php')) {
                    error_log('AQM Sitemap: Successfully copied files to correct directory');
                    // Clean up the original source directory
                    $wp_filesystem->delete($source, true);
                    return $new_source;
                } else {
                    error_log('AQM Sitemap: Main plugin file not found after copy');
                    // Copy failed, try to find the main plugin file in subdirectories
                    $files = $wp_filesystem->dirlist($source);
                    foreach ($files as $file => $file_info) {
                        if ($file_info['type'] === 'd') {
                            $potential_plugin_dir = trailingslashit($source) . $file;
                            if ($wp_filesystem->exists($potential_plugin_dir . '/' . $plugin_slug . '.php')) {
                                error_log('AQM Sitemap: Found plugin in subdirectory: ' . $file);
                                // Copy this directory to the correct location
                                $this->recursive_copy_dir($potential_plugin_dir, $new_source, $wp_filesystem);
                                if ($wp_filesystem->exists(trailingslashit($new_source) . $plugin_slug . '.php')) {
                                    error_log('AQM Sitemap: Successfully copied from subdirectory to correct location');
                                    // Clean up the original source directory
                                    $wp_filesystem->delete($source, true);
                                    return $new_source;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // If we get here, all attempts to fix the directory structure failed
        error_log('AQM Sitemap: All directory structure fixes failed, returning original source');
        return $source;
    }

    /**
     * Recursively copy all files and subdirectories from source to destination
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     * @param WP_Filesystem_Base $wp_filesystem WordPress filesystem object
     */
    private function recursive_copy_dir($source, $destination, $wp_filesystem) {
        $files = $wp_filesystem->dirlist($source, true);
        foreach ($files as $file => $file_info) {
            $src_path = trailingslashit($source) . $file;
            $dst_path = trailingslashit($destination) . $file;
            if ('f' === $file_info['type']) {
                $wp_filesystem->copy($src_path, $dst_path, true, FS_CHMOD_FILE);
            } elseif ('d' === $file_info['type']) {
                if (!$wp_filesystem->exists($dst_path)) {
                    $wp_filesystem->mkdir($dst_path);
                }
                $this->recursive_copy_dir($src_path, $dst_path, $wp_filesystem);
            }
        }
    }
    
    /**
     * Modify the download package URL to ensure it extracts with the correct directory name
     * 
     * @param bool $reply Whether to abort the download
     * @param string $package The package URL
     * @param object $upgrader The WordPress upgrader object
     * @param array $hook_extra Extra data
     * @return bool|WP_Error Whether to abort the download or WP_Error
     */
    public function modify_download_package($reply, $package, $upgrader, $hook_extra = array()) {
        // Only process our plugin's package
        if (strpos($package, 'github.com/JustCasey76/aqm-sitemap-enhanced') !== false) {
            error_log('AQM Sitemap: Modifying download package for GitHub update');
            
            // Store the original package URL for reference
            $upgrader->skin->feedback('AQM Sitemap: Using custom directory name for GitHub download');
            
            // Set a flag to indicate this is our plugin being updated
            update_option('aqm_sitemap_is_updating', true);
        }
        
        return $reply; // Return the original reply
    }
    
    /**
     * Modify package options to ensure the correct directory name is used
     * 
     * @param array $options Package options
     * @return array Modified package options
     */
    public function modify_package_options($options) {
        // Check if this is our plugin update
        if (get_option('aqm_sitemap_is_updating', false)) {
            error_log('AQM Sitemap: Modifying package options to use correct directory name');
            
            // Set the destination directory name explicitly
            $options['destination_name'] = 'aqm-sitemap-enhanced';
            
            // Clear the flag after use
            delete_option('aqm_sitemap_is_updating');
        }
        
        return $options;
    }
    
    /**
     * Actions to perform after plugin update
     * 
     * @param bool $true Always true
     * @param array $hook_extra Extra data about the update
     * @param array $result Update result data
     * @return array Result data
     */
    public function post_install($true, $hook_extra, $result) {
        // Check if this is our plugin
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->slug) {
            // Make sure we have the necessary functions
            if (!function_exists('activate_plugin') || !function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            // Check both the stored property and the option for reliability
            $was_active = $this->plugin_activated || get_option('aqm_sitemap_was_active', false);
            
            if ($was_active) {
                // Log the reactivation attempt
                error_log('AQM Sitemap: Attempting to reactivate plugin after update');
                
                // Reactivate plugin
                $activate_result = activate_plugin($this->slug);
                
                // Check for activation errors
                if (is_wp_error($activate_result)) {
                    error_log('AQM Sitemap: Error reactivating plugin: ' . $activate_result->get_error_message());
                } else {
                    error_log('AQM Sitemap: Plugin successfully reactivated after update');
                }
            }
        }
        
        return $result;
    }
}
