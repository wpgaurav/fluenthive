<?php
/**
 * Module loader: Core Forms → FluentCRM consent policy (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( FluentHive\Settings::on( 'module_consent_policy' ) ) {
	require_once __DIR__ . '/src/class-consent-policy.php';
}
