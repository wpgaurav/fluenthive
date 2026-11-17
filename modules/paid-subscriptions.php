<?php
/**
 * Module loader: paid newsletter subscriptions sold through FluentCart (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( FluentHive\Settings::on( 'module_paid' ) ) {
	require_once __DIR__ . '/src/class-paid-plans.php';
	require_once __DIR__ . '/src/class-paid-members.php';
	require_once __DIR__ . '/src/class-paid-access.php';
	require_once __DIR__ . '/src/class-paid-delivery.php';
	require_once __DIR__ . '/src/class-paid-admin.php';
	require_once __DIR__ . '/src/class-paid-products.php';
	require_once __DIR__ . '/src/class-paid-visibility.php';

	FluentHive\Modules\Paid\Members::boot();
	FluentHive\Modules\Paid\Access::boot();
	FluentHive\Modules\Paid\Delivery::boot();
	FluentHive\Modules\Paid\Admin::boot();
	FluentHive\Modules\Paid\Visibility::boot();
	add_action( 'fluenthive_paid_reconcile_all', array( 'FluentHive\\Modules\\Paid\\Members', 'reconcile_all' ) );
} else {
	// Switched off: drop the hourly expiry check it scheduled, which nothing handles now.
	add_action(
		'init',
		static function (): void {
			if ( wp_next_scheduled( 'fluenthive_paid_sweep' ) ) {
				wp_clear_scheduled_hook( 'fluenthive_paid_sweep' );
			}
		}
	);
}
