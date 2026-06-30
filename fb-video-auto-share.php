<?php
/**
 * Plugin Name: FB Video Auto Share
 * Version: 1.0.0
 * Description: Shares published WordPress posts (with featured image) as link posts to a Facebook Page via Graph API, with a per-post opt-in checkbox.
 * Author: Jubayer
 */

if (!defined('ABSPATH')) {
	exit;
}

define('FB_AUTO_SHARE_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include necessary files
require_once FB_AUTO_SHARE_PLUGIN_DIR . 'includes/class-fb-api.php';
require_once FB_AUTO_SHARE_PLUGIN_DIR . 'includes/class-post-hook.php';
require_once FB_AUTO_SHARE_PLUGIN_DIR . 'admin/settings-page.php';

/**
 * Activation Hook
 */
register_activation_hook(__FILE__, 'fb_auto_share_activate');

function fb_auto_share_activate()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'fb_auto_share_log';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		facebook_post_id varchar(100) DEFAULT '' NOT NULL,
		status varchar(20) NOT NULL,
		error_message text,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

/**
 * Add Settings link to plugin row
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fb_auto_share_add_settings_link');

function fb_auto_share_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=fb-auto-share') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Enqueue Gutenberg block editor assets
 */
add_action('enqueue_block_editor_assets', 'fb_auto_share_enqueue_block_assets');

function fb_auto_share_enqueue_block_assets() {
    wp_enqueue_script(
        'fb-share-panel',
        plugin_dir_url(__FILE__) . 'assets/js/fb-share-panel.js',
        array('wp-plugins', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-element', 'wp-compose'),
        '1.0.0',
        true
    );
}
