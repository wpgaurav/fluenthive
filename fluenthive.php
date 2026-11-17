<?php
/**
 * Plugin Name:       FluentHive
 * Description:       Send better FluentCRM newsletters. Beehiiv- and Ghost-style publishing for FluentCRM: a public web archive of issues, paid tiers sold through FluentCart, a theme-free reading page with light/dark mode, one-click FluentCRM campaign drafts, Substack import, and optional consent and FluentCart modules.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Gaurav Tiwari
 * Author URI:        https://gauravtiwari.org
 * License:           GPL-2.0-or-later
 * Text Domain:       fluenthive
 * Update URI:        https://gauravtiwari.org/product/fluenthive/
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FLUENTHIVE_VERSION', '1.1.0' );
define( 'FLUENTHIVE_FILE', __FILE__ );
define( 'FLUENTHIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLUENTHIVE_URL', plugin_dir_url( __FILE__ ) );

require_once FLUENTHIVE_DIR . 'includes/class-settings.php';
require_once FLUENTHIVE_DIR . 'includes/class-icons.php';
require_once FLUENTHIVE_DIR . 'includes/class-typography.php';
require_once FLUENTHIVE_DIR . 'includes/class-issues.php';
require_once FLUENTHIVE_DIR . 'includes/class-content.php';
require_once FLUENTHIVE_DIR . 'includes/class-importer.php';
require_once FLUENTHIVE_DIR . 'includes/class-links.php';
require_once FLUENTHIVE_DIR . 'includes/class-shell.php';
require_once FLUENTHIVE_DIR . 'includes/class-crm-bridge.php';
require_once FLUENTHIVE_DIR . 'includes/class-email.php';
require_once FLUENTHIVE_DIR . 'includes/class-crm-store.php';
require_once FLUENTHIVE_DIR . 'includes/class-migrator.php';
require_once FLUENTHIVE_DIR . 'includes/licensing/class-client.php';
require_once FLUENTHIVE_DIR . 'includes/licensing/class-secrets.php';
require_once FLUENTHIVE_DIR . 'includes/licensing/class-license.php';
require_once FLUENTHIVE_DIR . 'includes/licensing/class-updater.php';

// Optional modules, each off until enabled in Newsletter → Settings → Modules.
require_once FLUENTHIVE_DIR . 'modules/consent-policy.php';
require_once FLUENTHIVE_DIR . 'modules/fluentcart-lifecycle.php';
require_once FLUENTHIVE_DIR . 'modules/paid-subscriptions.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FLUENTHIVE_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'fluenthive', 'FluentHive\\CLI' );
}

add_action(
	'init',
	static function (): void {
		if ( FluentHive\Settings::on( 'module_paid' ) || ! get_option( 'fluenthive_paid_db' ) ) {
			return;
		}
		require_once FLUENTHIVE_DIR . 'modules/src/class-paid-members.php';
		FluentHive\Modules\Paid\Members::register_privacy();
	}
);

FluentHive\Settings::init();
FluentHive\Issues::init();
FluentHive\Links::init();
FluentHive\Shell::init();
FluentHive\CRM_Bridge::init();
FluentHive\Email::init();
( new FluentHive\Licensing\Updater() )->register();

register_activation_hook(
	__FILE__,
	static function (): void {
		$fresh = false === get_option( FluentHive\Settings::OPTION );
		FluentHive\Settings::seed();
		if ( $fresh ) {
			FluentHive\CRM_Bridge::hold_back_catalogue();
		}
		FluentHive\Issues::register();
		flush_rewrite_rules( false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( FluentHive\CRM_Bridge::SYNC_HOOK );
		wp_clear_scheduled_hook( 'fluenthive_paid_sweep' );
		wp_clear_scheduled_hook( 'fluenthive_fc_abandoned_sweep' );
		flush_rewrite_rules( false );
	}
);
