<?php
/**
 * Uninstall script for PressViber.
 *
 * Runs automatically when the plugin is deleted from the WordPress admin.
 * Removes all plugin-created options and cleans up the trash directory.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Delete individual named options.
$named_options = [
    'pv_agent_run_logs',
    'pv_widget_option_backups',
    'pv_openai_api_key',
    'pv_deepseek_api_key',
    'pv_agent_manual',
];

foreach ( $named_options as $option ) {
    delete_option( $option );
}

// Delete any remaining options with the pv_ prefix.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        'pv\_%'
    )
);

// Remove the .aivb_trash / .pv_trash directory if it exists.
$trash_dirs = [
    ABSPATH . '.pv_trash',
    ABSPATH . '.aivb_trash',
];

foreach ( $trash_dirs as $trash_dir ) {
    if ( ! is_dir( $trash_dir ) ) {
        continue;
    }

    // Recursively remove files and the directory.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $trash_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $file ) {
        if ( $file->isDir() ) {
            @rmdir( $file->getPathname() );
        } else {
            @unlink( $file->getPathname() );
        }
    }

    @rmdir( $trash_dir );
}
