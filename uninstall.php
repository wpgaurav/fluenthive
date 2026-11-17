<?php
/**
 * Uninstall: remove FluentHive settings and scheduled events. Issues (posts) and
 * the consent policy option are kept, since they hold content and consent records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'fluenthive_settings' );
delete_option( 'fluenthive_last_sync' );
delete_option( 'fluenthive_flush_rewrites' );
delete_option( 'fluenthive_license' );
delete_option( 'fluenthive_mirror_held' );
delete_transient( 'fluenthive_update_metadata' );
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'fluenthive_used_' ) . '%', $wpdb->esc_like( 'fluenthive_mirror_lock_' ) . '%' ) );
wp_clear_scheduled_hook( 'fluenthive_sync_campaigns' );
wp_clear_scheduled_hook( 'fluenthive_fc_abandoned_sweep' );
wp_clear_scheduled_hook( 'fluenthive_paid_sweep' );
wp_clear_scheduled_hook( 'fluenthive_paid_reconcile_all' );
// The fluenthive_members table is kept: it records what readers paid for.
