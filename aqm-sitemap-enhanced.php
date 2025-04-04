<?php
/**
 * Plugin Name: AQM Enhanced Sitemap
 * Description: Enhanced sitemap plugin with folder selection and shortcode management
 * Version: 1.3.0
 * Author: AQ Marketing
 * Plugin URI: https://github.com/JustCasey76/aqm-sitemap-enhanced
 * GitHub Plugin URI: https://github.com/JustCasey76/aqm-sitemap-enhanced
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Version for cache busting
define('AQM_SITEMAP_VERSION', '1.3.0');

// Include the GitHub Updater
require_once plugin_dir_path(__FILE__) . 'includes/class-aqm-github-updater.php';

// Setup GitHub Updater - initialize after plugins are loaded
function aqm_sitemap_init_github_updater() {
    // Only setup if class exists
    if (class_exists('AQM_GitHub_Updater')) {
        new AQM_GitHub_Updater(
            __FILE__,
            'JustCasey76',
            'aqm-sitemap-enhanced'
        );
    }
}
add_action('plugins_loaded', 'aqm_sitemap_init_github_updater');

// Add menu item
function aqm_sitemap_menu() {
    add_menu_page(
        'AQM Sitemap',
        'AQM Sitemap',
        'manage_options',
        'aqm-sitemap',
        'aqm_sitemap_page',
        'dashicons-layout'
    );
}
add_action('admin_menu', 'aqm_sitemap_menu');

// Register scripts and styles
function aqm_sitemap_admin_scripts($hook) {
    // Only load on our plugin page
    if ('toplevel_page_aqm-sitemap' !== $hook) {
        return;
    }

    wp_enqueue_script('jquery');
    // Enqueue our script
    wp_enqueue_script(
        'aqm-sitemap-admin-script', 
        plugins_url('js/admin-script.js', __FILE__), 
        array('jquery'), 
        AQM_SITEMAP_VERSION, 
        true
    );
    
    wp_localize_script('aqm-sitemap-admin-script', 'aqmSitemap', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aqm_sitemap_nonce')
    ));
}
add_action('admin_enqueue_scripts', 'aqm_sitemap_admin_scripts');

// Register and enqueue frontend styles
function aqm_sitemap_enqueue_styles() {
    wp_register_style(
        'aqm-sitemap-frontend',
        plugins_url('css/frontend-style.css', __FILE__),
        array(),
        AQM_SITEMAP_VERSION
    );
}
add_action('wp_enqueue_scripts', 'aqm_sitemap_enqueue_styles');

// Register and enqueue admin styles
function aqm_sitemap_admin_styles($hook) {
    if ('toplevel_page_aqm-sitemap' !== $hook) {
        return;
    }
    
    wp_enqueue_style(
        'aqm-sitemap-admin',
        plugins_url('css/admin-style.css', __FILE__),
        array(),
        AQM_SITEMAP_VERSION
    );
}
add_action('admin_enqueue_scripts', 'aqm_sitemap_admin_styles');

// Migrate shortcodes from old option names
function migrate_old_shortcodes() {
    // Get shortcodes from all possible old option names
    $old_shortcodes = array_merge(
        get_option('aqm_saved_shortcodes', array()),
        get_option('aqm_saved_sitemap_shortcodes', array())
    );

    if (!empty($old_shortcodes)) {
        // Save to new option name
        update_option('aqm_sitemap_shortcodes', $old_shortcodes);
        
        // Clean up old options
        delete_option('aqm_saved_shortcodes');
        delete_option('aqm_saved_sitemap_shortcodes');
        
        error_log('AQM Sitemap: Migrated ' . count($old_shortcodes) . ' shortcodes to new option name');
    }
}

// Add plugin options on activation
function aqm_sitemap_activate() {
    // Set default options if they don't exist
    if (get_option('aqm_sitemap_show_debug') === false) {
        add_option('aqm_sitemap_show_debug', 1); // Default to showing debug info
    }
    
    // Set the last update check time
    if (get_option('aqm_sitemap_last_update_check') === false) {
        add_option('aqm_sitemap_last_update_check', time());
    }
}
register_activation_hook(__FILE__, 'aqm_sitemap_activate');

// Force WordPress to check for updates
function aqm_force_update_check() {
    if (!current_user_can('update_plugins')) {
        wp_send_json_error('Permission denied');
    }
    
    // Verify nonce
    if (!check_ajax_referer('aqm_sitemap_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid security token');
    }
    
    // Delete the transient that stores update info
    delete_site_transient('update_plugins');
    
    // Update the last check time
    update_option('aqm_sitemap_last_update_check', time());
    
    // Force WordPress to check for updates
    wp_update_plugins();
    
    // Get current plugin data
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];
    
    wp_send_json_success(array(
        'message' => 'Update check complete',
        'last_check' => human_time_diff(time(), time()) . ' ago',
        'current_version' => $current_version
    ));
}
add_action('wp_ajax_aqm_force_update_check', 'aqm_force_update_check');

// Main admin page
function aqm_sitemap_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Migrate old shortcodes if needed
    migrate_old_shortcodes();

    // Get all folders
    $folders = get_terms(array(
        'taxonomy' => 'folder',
        'hide_empty' => false,
    ));

    // Get saved shortcodes
    $saved_shortcodes = get_option('aqm_sitemap_shortcodes', array());
    
    // Debug log
    error_log('AQM Sitemap: Number of saved shortcodes: ' . count($saved_shortcodes));
    
    // Handle debug toggle if form submitted
    if (isset($_POST['aqm_debug_toggle_submit'])) {
        $show_debug = isset($_POST['aqm_show_debug']) ? 1 : 0;
        update_option('aqm_sitemap_show_debug', $show_debug);
        echo '<div class="notice notice-success is-dismissible"><p>Debug settings updated successfully.</p></div>';
    }
    
    // Get current debug setting
    $show_debug = get_option('aqm_sitemap_show_debug', 1);
    
    // Get last update check time
    $last_update_check = get_option('aqm_sitemap_last_update_check', 0);
    $last_check_text = $last_update_check ? human_time_diff($last_update_check, time()) . ' ago' : 'Never';
    
    // Get current plugin version
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];
    ?>
    <div class="wrap">
        <div class="aqm-header">
            <h1>AQM Sitemap Generator</h1>
            <div class="theme-toggle">
                <label class="switch">
                    <input type="checkbox" id="theme-switch">
                    <span class="slider round"></span>
                </label>
                <span class="theme-label">Dark Mode</span>
            </div>
        </div>
        
        <!-- Admin Debug Toggle Section -->
        <div class="aqm-admin-settings">
            <h2>Admin Settings</h2>
            <form method="post" action="">
                <div class="form-group">
                    <label for="aqm_show_debug">
                        <input type="checkbox" id="aqm_show_debug" name="aqm_show_debug" value="1" <?php checked(1, $show_debug); ?>>
                        Show Debug Information in Sitemap Shortcodes
                    </label>
                    <p class="description">When enabled, sitemap shortcodes will display debug information (folder slug, excluded IDs, etc.) to admin users.</p>
                </div>
                <p>
                    <input type="submit" name="aqm_debug_toggle_submit" class="button button-primary" value="Save Settings">
                </p>
            </form>
            
            <!-- Update Check Section -->
            <div class="update-check-section">
                <h3>Plugin Updates</h3>
                <p>Last update check: <span id="last-update-check"><?php echo esc_html($last_check_text); ?></span></p>
                <p>Current version: <strong><?php echo esc_html($current_version); ?></strong></p>
                <button id="check-for-updates" class="button button-secondary">Check for Updates</button>
                <span id="update-check-status" style="margin-left: 10px; display: none;"></span>
                
                <div class="update-instructions">
                    <p class="description">After checking for updates, visit the <a href="<?php echo admin_url('plugins.php'); ?>">Plugins page</a> to see if an update is available.</p>
                </div>
            </div>
        </div>
        
        <div class="aqm-main-content">
            <div class="aqm-left-column">
                <div class="aqm-sitemap-generator">
                    <form id="aqm-sitemap-form">
                        <input type="hidden" id="edit_mode" name="edit_mode" value="0">
                        <input type="hidden" id="original_name" name="original_name" value="">
                        
                        <div class="form-grid">
                            <!-- Content Settings Section -->
                            <div class="form-section content-settings">
                                <h3>Content Settings</h3>
                                <div class="form-group">
                                    <label>Select Folders:</label>
                                    <div class="folder-checklist">
                                        <?php foreach ($folders as $folder): ?>
                                            <?php 
                                            $folder_name = esc_html($folder->name);
                                            $folder_name = str_replace('-', ' ', $folder_name);
                                            $folder_name = ucwords($folder_name);
                                            ?>
                                            <div class="folder-checkbox-item">
                                                <input type="checkbox" 
                                                       id="folder_<?php echo esc_attr($folder->slug); ?>" 
                                                       name="folder[]" 
                                                       value="<?php echo esc_attr($folder->slug); ?>">
                                                <label for="folder_<?php echo esc_attr($folder->slug); ?>"><?php echo $folder_name; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="shortcode_name">Shortcode Name:</label>
                                    <input type="text" id="shortcode_name" name="shortcode_name" placeholder="Enter shortcode name" required>
                                    <div class="form-help">Leave empty to auto-generate from folder name</div>
                                </div>

                                <div class="form-group">
                                    <label for="order">Sort Order:</label>
                                    <select id="order" name="order">
                                        <option value="menu_order">Menu Order</option>
                                        <option value="title">Title</option>
                                        <option value="date">Date</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="display_type">Display Type:</label>
                                    <select id="display_type" name="display_type">
                                        <option value="columns">Display in Columns</option>
                                        <option value="inline">Display Inline with Separators</option>
                                    </select>
                                </div>

                                <div class="form-group columns-option">
                                    <label for="columns">Number of Columns:</label>
                                    <select id="columns" name="columns">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?php echo $i; ?>"<?php echo $i === 2 ? ' selected' : ''; ?>><?php echo $i; ?> Column<?php echo $i > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group page-exclusions">
                                    <label for="exclude_ids">Exclude Pages:</label>
                                    <div class="excluded-pages-container">
                                        <select id="page_to_exclude" class="page-selector">
                                            <option value="">Select a page to exclude</option>
                                            <?php 
                                            $all_pages = get_pages();
                                            foreach ($all_pages as $page): ?>
                                                <option value="<?php echo esc_attr($page->ID); ?>"><?php echo esc_html($page->post_title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" id="add_excluded_page" class="button">Add</button>
                                    </div>
                                    <div id="excluded_pages_list" class="excluded-pages-list"></div>
                                    <input type="hidden" id="exclude_ids" name="exclude_ids" value="">
                                    <div class="form-help">Select pages to exclude from the sitemap</div>
                                </div>
                            </div>

                            <div class="form-footer">
                                <button type="submit" id="submit_button" class="button button-primary">Generate Shortcode</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="aqm-right-column">
                <div class="aqm-saved-shortcodes">
                    <h2>Saved Shortcodes</h2>
                    <div class="shortcodes-list" id="shortcodes-list">
                        <?php foreach ($saved_shortcodes as $name => $shortcode): ?>
                            <div class="shortcode-card">
                                <div class="shortcode-header">
                                    <h3><?php echo esc_html($name); ?></h3>
                                </div>
                                <div class="shortcode-content">
                                    <code><?php echo esc_html(wp_unslash($shortcode)); ?></code>
                                </div>
                                <div class="shortcode-actions">
                                    <button class="button copy-shortcode" data-shortcode="<?php echo esc_attr(wp_unslash($shortcode)); ?>">Copy</button>
                                    <button class="button edit-shortcode" 
                                            data-name="<?php echo esc_attr($name); ?>"
                                            data-shortcode="<?php echo esc_attr(wp_unslash($shortcode)); ?>">Edit</button>
                                    <button class="button delete-shortcode" 
                                            data-name="<?php echo esc_attr($name); ?>">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Save shortcode
function aqm_save_shortcode() {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Log the raw POST data
    error_log('AQM Sitemap Raw POST: ' . print_r($_POST, true));

    // Check if this is an AJAX request
    if (!wp_doing_ajax()) {
        error_log('AQM Sitemap: Not an AJAX request');
        die('Invalid request method');
    }

    // Verify nonce first
    if (!isset($_POST['nonce'])) {
        error_log('AQM Sitemap: Nonce is missing');
        wp_send_json_error('Security token is missing');
        wp_die();
    }

    if (!wp_verify_nonce($_POST['nonce'], 'aqm_sitemap_nonce')) {
        error_log('AQM Sitemap: Invalid nonce');
        wp_send_json_error('Invalid security token');
        wp_die();
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        error_log('AQM Sitemap: Insufficient permissions');
        wp_send_json_error('You do not have permission to perform this action');
        wp_die();
    }

    // Get and validate required data
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $shortcode = isset($_POST['shortcode']) ? sanitize_text_field($_POST['shortcode']) : '';
    $edit_mode = isset($_POST['edit_mode']) ? $_POST['edit_mode'] === '1' : false;
    $original_name = isset($_POST['original_name']) ? sanitize_text_field($_POST['original_name']) : '';
    
    // Log the sanitized data
    error_log('AQM Sitemap: Sanitized data - ' . print_r([
        'name' => $name,
        'shortcode' => $shortcode,
        'edit_mode' => $edit_mode,
        'original_name' => $original_name
    ], true));

    // Validate required fields
    if (empty($name)) {
        wp_send_json_error('Shortcode name is required');
        wp_die();
    }

    if (empty($shortcode)) {
        wp_send_json_error('Shortcode content is required');
        wp_die();
    }

    try {
        // Get existing shortcodes with error checking
        $saved_shortcodes = get_option('aqm_sitemap_shortcodes', array());
        if (!is_array($saved_shortcodes)) {
            $saved_shortcodes = array();
        }

        // Check for duplicates in create mode
        if (!$edit_mode && isset($saved_shortcodes[$name])) {
            wp_send_json_error('A shortcode with this name already exists');
            wp_die();
        }

        // Handle edit mode name changes
        if ($edit_mode && $name !== $original_name && isset($saved_shortcodes[$name])) {
            wp_send_json_error('Cannot rename: a shortcode with this name already exists');
            wp_die();
        }

        // Remove old shortcode in edit mode
        if ($edit_mode && $name !== $original_name && isset($saved_shortcodes[$original_name])) {
            unset($saved_shortcodes[$original_name]);
        }

        // Save the shortcode
        $saved_shortcodes[$name] = $shortcode;

        // Update option with error checking
        $update_result = update_option('aqm_sitemap_shortcodes', $saved_shortcodes);
        
        if ($update_result) {
            error_log('AQM Sitemap: Shortcode saved successfully - ' . $name);
            wp_send_json_success(array(
                'message' => 'Shortcode saved successfully',
                'name' => $name
            ));
        } else {
            error_log('AQM Sitemap: Failed to update option');
            wp_send_json_error('Failed to save shortcode');
        }
    } catch (Exception $e) {
        error_log('AQM Sitemap Exception: ' . $e->getMessage());
        wp_send_json_error('An unexpected error occurred: ' . $e->getMessage());
    }

    // Ensure we always die at the end
    wp_die();
}

// Remove any existing action to prevent duplicates
remove_action('wp_ajax_save_sitemap_shortcode', 'aqm_save_shortcode');
remove_action('wp_ajax_nopriv_save_sitemap_shortcode', 'aqm_save_shortcode');

// Add our action
add_action('wp_ajax_save_sitemap_shortcode', 'aqm_save_shortcode');

// Delete shortcode
function aqm_delete_shortcode() {
    if (!check_ajax_referer('aqm_sitemap_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $name = sanitize_text_field($_POST['name']);
    
    $saved_shortcodes = get_option('aqm_sitemap_shortcodes', array());
    unset($saved_shortcodes[$name]);
    
    update_option('aqm_sitemap_shortcodes', $saved_shortcodes);
    
    wp_send_json_success();
}
add_action('wp_ajax_aqm_delete_shortcode', 'aqm_delete_shortcode');

// Add admin notice to ensure debug is showing
function aqm_admin_debug_notice() {
    // Only show on admin pages
    if (!is_admin()) return;
    
    echo '<div class="notice notice-info is-dismissible">
        <p><strong>AQM Sitemap Debug:</strong> Enhanced debugging is enabled for all logged-in users. Make sure you are logged in when viewing pages with the sitemap shortcode to see debug information.</p>
    </div>';
}
add_action('admin_notices', 'aqm_admin_debug_notice');

// The actual shortcode function
function display_enhanced_page_sitemap($atts) {
    global $wpdb;
    
    // Get debug setting from admin options (default to off)
    $show_debug = (bool) get_option('aqm_sitemap_show_debug', 0);
    
    // Only show debug info to admins when the option is enabled
    $show_debug = $show_debug && current_user_can('manage_options');
    
    // Ensure our styles are loaded with forced cache busting
    $css_version = AQM_SITEMAP_VERSION . '.' . time(); // Ultra-aggressive cache busting
    wp_enqueue_style(
        'aqm-sitemap-frontend',
        plugins_url('css/frontend-style.css', __FILE__),
        array(),
        $css_version
    );

    // Extract shortcode attributes with defaults
    $atts = shortcode_atts(array(
        'folder_slug' => '',
        'folder_slugs' => '', // New parameter for multiple folders
        'display_type' => 'columns',
        'columns' => '2',
        'order' => 'menu_order',
        'exclude_ids' => '', // Parameter to exclude pages by ID
        'show_all' => 'no' // New parameter to show all pages
    ), $atts, 'sitemap_page');

    // Sanitize attributes
    $folder_slug = sanitize_text_field($atts['folder_slug']);
    $folder_slugs = sanitize_text_field($atts['folder_slugs']);
    $display_type = in_array($atts['display_type'], array('columns', 'inline')) ? $atts['display_type'] : 'columns';
    $columns = intval($atts['columns']);
    $columns = $columns > 0 && $columns <= 6 ? $columns : 2;
    $order = in_array($atts['order'], array('menu_order', 'title', 'date')) ? $atts['order'] : 'menu_order';
    $show_all = in_array(strtolower($atts['show_all']), array('yes', 'true', '1')) ? true : false;
    
    // Process exclude IDs
    $exclude_ids = array();
    if (!empty($atts['exclude_ids'])) {
        $exclude_ids_raw = trim($atts['exclude_ids']);
        if (!empty($exclude_ids_raw)) {
            $exclude_ids = array_map('intval', explode(',', $exclude_ids_raw));
            $exclude_ids = array_filter($exclude_ids); // Remove any zero/invalid IDs
        }
    }
    
    // Debug information
    $debug = '';
    if ($show_debug) {
        $debug .= '<div style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin-bottom:20px;font-family:monospace;">';
        $debug .= '<p><strong>Debug Info:</strong></p>';
        $debug .= '<p>Shortcode used: ' . current_filter() . '</p>';
        $debug .= '<p>Show All Pages: ' . ($show_all ? 'Yes' : 'No') . '</p>';
        if (!$show_all) {
            $debug .= '<p>Folder: ' . esc_html($folder_slug) . '</p>';
        }
        $debug .= '<p>Excluded IDs: ' . (!empty($exclude_ids) ? esc_html(implode(', ', $exclude_ids)) : 'None') . '</p>';
        
        // Add Premio Folders debugging
        $terms = get_terms(array(
            'taxonomy' => 'folder',
            'hide_empty' => false,
        ));
        if (!empty($terms) && !is_wp_error($terms)) {
            $debug .= '<p><strong>Available Folders:</strong></p><ul>';
            foreach ($terms as $term) {
                $debug .= '<li>' . esc_html($term->name) . ' [' . esc_html($term->slug) . '] (ID: ' . esc_html($term->term_id) . ')</li>';
            }
            $debug .= '</ul>';
        } else {
            $debug .= '<p>No folder terms found. Check if Premio Folders is active.</p>';
        }
        
        $debug .= '</div>';
    }
    
    // Initialize page_ids array
    $page_ids = array();
    
    // If show_all is true, get all published pages
    if ($show_all) {
        $all_pages_query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'";
        
        // Add exclude IDs if any
        if (!empty($exclude_ids)) {
            $exclude_ids_str = implode(',', array_map('intval', $exclude_ids));
            $all_pages_query .= " AND ID NOT IN ({$exclude_ids_str})";
        }
        
        // Add ordering
        if ($order === 'title') {
            $all_pages_query .= " ORDER BY post_title ASC";
        } elseif ($order === 'date') {
            $all_pages_query .= " ORDER BY post_date DESC";
        } else {
            $all_pages_query .= " ORDER BY menu_order ASC, post_title ASC";
        }
        
        // Get the final list of page IDs
        $page_ids = $wpdb->get_col($all_pages_query);
        
        if ($show_debug) {
            $debug .= '<div style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin-bottom:20px;font-family:monospace;">';
            $debug .= '<p><strong>Show All Pages Mode:</strong> Getting all published pages except excluded IDs</p>';
            $debug .= '</div>';
        }
    } else {
        // Check if folder slug or slugs are empty when not showing all
        if (empty($folder_slug) && empty($folder_slugs)) {
            if ($show_debug) {
                return $debug . '<p>Error: No folder_slug(s) provided in shortcode and show_all is not enabled.</p>';
            }
            return '<p>No pages found.</p>';
        }
        
        $folder_terms = array();
        $all_page_ids = array();
        
        // Handle multiple folder slugs (comma separated)
        if (!empty($folder_slugs)) {
            $slug_array = array_map('trim', explode(',', $folder_slugs));
            
            foreach ($slug_array as $slug) {
                $term = get_term_by('slug', $slug, 'folder');
                if ($term) {
                    $folder_terms[] = $term;
                }
            }
        }
        // Handle single folder slug for backward compatibility
        elseif (!empty($folder_slug)) {
            $term = get_term_by('slug', $folder_slug, 'folder');
            if ($term) {
                $folder_terms[] = $term;
            }
        }
        
        // If no valid folders found
        if (empty($folder_terms)) {
            if ($show_debug) {
                return $debug . '<p>No valid folders found.</p>';
            }
            return '<p>No pages found.</p>';
        }
        
        // Additional debug info about the selected folders
        if ($show_debug) {
            $debug .= '<div style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin-bottom:20px;font-family:monospace;">';
            $debug .= '<p><strong>Selected Folder Details:</strong></p>';
            
            foreach ($folder_terms as $folder_term) {
                $debug .= '<div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #ddd;">';
                $debug .= '<p>Name: ' . esc_html($folder_term->name) . '</p>';
                $debug .= '<p>Slug: ' . esc_html($folder_term->slug) . '</p>';
                $debug .= '<p>Term ID: ' . esc_html($folder_term->term_id) . '</p>';
                $debug .= '</div>';
            }
            
            $debug .= '</div>';
        }
        
        // Get pages from all selected folders
        foreach ($folder_terms as $folder_term) {
            $folder_page_ids = get_objects_in_term($folder_term->term_id, 'folder');
            $all_page_ids = array_merge($all_page_ids, $folder_page_ids);
        }
        
        // Remove duplicates
        $all_page_ids = array_unique($all_page_ids);
        
        // Filter to only include published pages
        if (!empty($all_page_ids)) {
            $page_ids_str = implode(',', array_map('intval', $all_page_ids));
            $published_query = "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$page_ids_str}) AND post_type = 'page' AND post_status = 'publish'";
            
            // Add exclude IDs if any
            if (!empty($exclude_ids)) {
                $exclude_ids_str = implode(',', array_map('intval', $exclude_ids));
                $published_query .= " AND ID NOT IN ({$exclude_ids_str})";
            }
            
            // Add ordering
            if ($order === 'title') {
                $published_query .= " ORDER BY post_title ASC";
            } elseif ($order === 'date') {
                $published_query .= " ORDER BY post_date DESC";
            } else {
                $published_query .= " ORDER BY menu_order ASC, post_title ASC";
            }
            
            // Get the final list of page IDs
            $page_ids = $wpdb->get_col($published_query);
        }
    }
    
    // Debug page IDs
    if ($show_debug) {
        $debug .= '<div style="background:#f5f5f5;border:1px solid #ccc;padding:10px;margin-bottom:20px;font-family:monospace;">';
        $debug .= '<p><strong>Page IDs ' . ($show_all ? '(All Pages)' : 'in Folder') . ':</strong></p>';
        if (!empty($page_ids)) {
            $debug .= '<p>' . implode(', ', $page_ids) . '</p>';
            
            // Add page titles for easier identification
            $debug .= '<p><strong>Pages with titles:</strong></p><ul>';
            foreach ($page_ids as $page_id) {
                $title = get_the_title($page_id);
                $permalink = get_permalink($page_id);
                $debug .= '<li>ID ' . $page_id . ': <a href="' . esc_url($permalink) . '" target="_blank">' . esc_html($title) . '</a></li>';
            }
            $debug .= '</ul>';
        } else {
            $debug .= '<p>No page IDs found' . ($show_all ? '' : ' in this folder') . '.</p>';
        }
        $debug .= '</div>';
    }
    
    // Get pages by ID
    $pages = array();
    if (!empty($page_ids)) {
        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);
            if ($page && $page->post_type == 'page' && $page->post_status == 'publish') {
                $pages[] = $page;
            }
        }
    }
    
    if (empty($pages)) {
        if ($show_debug) {
            return $debug . '<p>No pages found' . ($show_all ? '' : ' in the selected folder') . '.</p>';
        }
        return '<p>No pages found.</p>';
    }
    
    // Build output
    $output = '';
    if ($show_debug) {
        $output .= $debug;
    }
    
    // Create wrapper with classes
    $classes = array('aqm-sitemap');
    if ($display_type === 'columns') {
        $classes[] = 'columns';
        $classes[] = 'columns-' . esc_attr($columns);
    } else {
        $classes[] = 'inline';
    }
    
    $output .= '<div class="' . esc_attr(implode(' ', $classes)) . '">';
    
    // For inline display
    if ($display_type === 'inline') {
        $links = array();
        foreach ($pages as $page) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(get_permalink($page->ID)),
                esc_html($page->post_title)
            );
        }
        $output .= implode(' | ', $links);
    } 
    // For column display
    else {
        // Calculate items per column for balanced distribution
        $total_items = count($pages);
        $items_per_column = ceil($total_items / $columns);
        
        // Create column layout with inline CSS to force styling
        $output .= '<div style="display:flex;flex-wrap:wrap;margin:0 -10px;box-sizing:border-box;">';
        
        // Distribute pages across columns (top to bottom ordering)
        for ($col = 0; $col < $columns; $col++) {
            // Calculate start index for this column
            $start_idx = $col * $items_per_column;
            
            // Skip if no more pages
            if ($start_idx >= $total_items) continue;
            
            // Column width
            $col_width = (100 / $columns);
            
            // Start column
            $output .= '<div style="flex:0 0 ' . $col_width . '%;max-width:' . $col_width . '%;padding:0 10px;box-sizing:border-box;">';
            
            // Add pages for this column
            for ($i = 0; $i < $items_per_column; $i++) {
                $idx = $start_idx + $i;
                if ($idx < $total_items) {
                    $output .= sprintf(
                        '<div style="margin-bottom:10px;"><a href="%s">%s</a></div>',
                        esc_url(get_permalink($pages[$idx]->ID)),
                        esc_html($pages[$idx]->post_title)
                    );
                }
            }
            
            // End column
            $output .= '</div>';
        }
        
        // End column layout
        $output .= '</div>';
    }
    
    // Close main wrapper
    $output .= '</div>';
    
    // Prevent wpautop from adding empty paragraphs
    remove_filter('the_content', 'wpautop');
    add_filter('the_content', 'wpautop', 99);
    
    return $output;
}

// Also register the plural version of the shortcode for consistency
add_shortcode('sitemap_pages', 'display_enhanced_page_sitemap');
add_shortcode('sitemap_page', 'display_enhanced_page_sitemap');
