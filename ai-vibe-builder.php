<?php
/**
 * Plugin Name:       PressViber
 * Plugin URI:        https://falt.ai
 * Description:       An AI-powered interface for building and editing WordPress sites through a modern chat-style UI.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Falt AI
 * Author URI:        https://falt.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressviber
 */

defined( 'ABSPATH' ) || exit;

define( 'PV_VERSION',     '1.0.0' );
define( 'PV_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PV_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'PV_PLUGIN_FILE', __FILE__ );

require_once PV_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once PV_PLUGIN_DIR . 'includes/class-ai-client.php';
require_once PV_PLUGIN_DIR . 'includes/class-command-runner.php';
require_once PV_PLUGIN_DIR . 'includes/class-file-manager.php';
require_once PV_PLUGIN_DIR . 'includes/class-site-inspector.php';
require_once PV_PLUGIN_DIR . 'includes/class-agent.php';

function pv_init(): void {
    ( new PV_Admin_Page() )->init();
    ( new PV_AI_Client() )->init();
    ( new PV_File_Manager() )->init();
    ( new PV_Site_Inspector() )->init();
    ( new PV_Agent() )->init();
}
add_action( 'plugins_loaded', 'pv_init' );

register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
