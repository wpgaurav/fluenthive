<?php
/**
 * FluentHive settings: one option, a tabbed screen under Newsletter → Settings, and
 * auto-detection of values the site already holds (Rybbit tag in theme footer scripts,
 * the FluentCRM Newsletter list, the /newsletter/ page, the signup form).
 */

namespace FluentHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FluentCart and Core Forms keep their data in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class Settings {
	const OPTION      = 'fluenthive_settings';
	const SYNC_OPTION = 'fluenthive_last_sync';
	const FLUSH_FLAG  = 'fluenthive_flush_rewrites';

	/** Checkbox fields per tab, so a partial (one-tab) save can record "off". */
	const CHECKBOXES = array(
		'issues'      => array( 'show_subscribe', 'show_pager', 'show_share', 'nofollow', 'external_new_tab' ),
		'design'      => array( 'detach_theme', 'show_theme_toggle', 'landing_shell' ),
		'fluentcrm'   => array( 'auto_draft', 'mirror_enabled', 'redirect_web_view' ),
		'analytics'   => array( 'rybbit_enabled' ),
		'modules'     => array( 'module_consent_policy', 'consent_double_optin', 'module_fluentcart_lifecycle', 'module_paid' ),
		'paid'        => array( 'paid_resubscribe', 'paid_add_free_list', 'paid_show_on_landing', 'paid_show_free', 'paid_products_in_shop' ),
	);

	const TABS = array(
		'general'     => 'General',
		'issues'      => 'Issues',
		'design'      => 'Design',
		'fluentcrm'   => 'FluentCRM',
		'analytics'   => 'Analytics',
		'modules'     => 'Modules',
		'paid'        => 'Paid',
		'license'     => 'License',
		'tools'       => 'Tools & status',
	);

	public static function defaults(): array {
		return array(
			// General.
			'brand_name'              => '',
			'author_name'             => '',
			'author_initials'         => '',
			'avatar_url'              => '',
			'avatar_id'               => 0,
			'header_style'            => 'avatar',
			'logo_id'                 => 0,
			'logo_dark_id'            => 0,
			'logo_width'              => 140,
			'byline'                  => '',
			'sender_line'             => '',
			'subscribe_label'         => 'Subscribe',
			'tagline'                 => '',
			'landing_page_id'         => 0,
			'form_slug'               => '',
			'form_shortcode'          => '',
			// Issues.
			'issue_base'              => 'newsletter/issue',
			'archive_title'           => '',
			'archive_intro'           => '',
			'subscribe_heading'       => '',
			'subscribe_facts'         => "Free\nNo fixed schedule\nUnsubscribe anytime",
			'show_subscribe'          => true,
			'show_pager'              => true,
			'show_share'              => true,
			'nofollow'                => true,
			'external_new_tab'        => false,
			'internal_hosts'          => array(),
			// Design.
			'detach_theme'            => true,
			'landing_shell'           => true,
			'landing_recent'          => 6,
			'color_scheme'            => 'auto',
			'show_theme_toggle'       => true,
			'theme_storage_key'       => 'fluenthive-theme',
			'copyright'               => '© {year} {site}',
			'accent_light'            => '#C0392B',
			'accent_dark'             => '#E8836F',
			'header_links'            => '',
			'footer_note'             => 'Already subscribed? Every email ends with links to manage or cancel your subscription.',
			'footer_links'            => '',
			'privacy_url'             => '',
			'custom_css'              => '',
			// FluentCRM.
			'auto_draft'              => false,
			'draft_list_ids'          => array(),
			'sender_name'             => '',
			'sender_email'            => '',
			'subject_template'        => '{title}',
			'email_footer'            => 'You are receiving this because you subscribed to {newsletter}.',
			'mirror_enabled'          => true,
			'mirror_list_ids'         => array(),
			'mirror_status'           => 'publish',
			'redirect_web_view'       => true,
			// Analytics.
			'rybbit_enabled'          => false,
			'rybbit_src'              => '',
			'rybbit_site_id'          => '',
			'head_html'               => '',
			// Imports.
			'substack_publication'    => '',
			// Modules (off for new installs).
			'module_consent_policy'   => false,
			'module_fluentcart_lifecycle' => false,
			'consent_double_optin'    => false,
			'consent_version'         => 'v1',
			'fc_category_tags'        => '',
			'fc_customer_list'        => 'Customers',
			'fc_consent_disclosure'   => '',
			// Paid subscriptions (module off for new installs).
			'module_paid'             => false,
			'paid_tiers'              => array(),
			'paid_preview_blocks'     => 3,
			'paid_grace_days'         => 3,
			'paid_cookie_days'        => 60,
			'paid_resubscribe'        => true,
			'paid_add_free_list'      => true,
			'paid_show_on_landing'    => true,
			'paid_plans_heading'      => '',
			'paid_plans_intro'        => '',
			'paid_show_free'          => true,
			'paid_products_in_shop'   => false,
			'free_tier_name'          => 'Free',
			'free_tier_description'   => '',
			'paid_seeded'             => false,
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_fluenthive_tool', array( __CLASS__, 'run_tool' ) );
		add_action( 'admin_post_fluenthive_license', array( __CLASS__, 'license_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'license_notice' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'plugin_action_links_' . plugin_basename( FLUENTHIVE_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/* ---------- values with sensible fallbacks for a fresh install ---------- */

	public static function brand(): string {
		$v = trim( (string) self::get( 'brand_name' ) );
		/* translators: %s: site title */
		return '' !== $v ? $v : sprintf( __( '%s Newsletter', 'fluenthive' ), get_bloginfo( 'name' ) );
	}

	public static function author(): string {
		$v = trim( (string) self::get( 'author_name' ) );
		return '' !== $v ? $v : (string) get_bloginfo( 'name' );
	}

	public static function initials(): string {
		$v = trim( (string) self::get( 'author_initials' ) );
		if ( '' !== $v ) {
			return $v;
		}
		$words = preg_split( '/\s+/', self::author() );
		$out   = '';
		foreach ( array_slice( array_filter( $words ), 0, 2 ) as $w ) {
			$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		return $out;
	}

	public static function tagline(): string {
		$v = trim( (string) self::get( 'tagline' ) );
		return '' !== $v ? $v : (string) get_bloginfo( 'description' );
	}

	public static function subscribe_heading(): string {
		$v = trim( (string) self::get( 'subscribe_heading' ) );
		return '' !== $v ? $v : __( 'Get the next issue', 'fluenthive' );
	}

	public static function copyright(): string {
		return str_replace( array( '{year}', '{site}' ), array( wp_date( 'Y' ), get_bloginfo( 'name' ) ), (string) self::get( 'copyright' ) );
	}

	public static function on( string $key ): bool {
		return (bool) self::get( $key );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . Issues::TYPE . '&page=fluenthive' );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	/* ---------- derived values ---------- */

	public static function form_shortcode(): string {
		$custom = trim( (string) self::get( 'form_shortcode' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$slug = sanitize_title( (string) self::get( 'form_slug' ) );
		return $slug ? '[cf_form slug="' . $slug . '"]' : '';
	}

	public static function issue_base(): string {
		$base = trim( (string) self::get( 'issue_base' ), '/' );
		$base = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', $base ) ) ) );
		return $base ?: 'newsletter/issue';
	}

	/** "Label | URL" lines to [label, url] pairs. */
	public static function link_lines( string $key ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( $key ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[] = array( $parts[0], $parts[1] );
			}
		}
		return $out;
	}

	public static function lines( string $key ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::get( $key ) ) ) ) );
	}

	public static function internal_hosts(): array {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( (array) self::get( 'internal_hosts' ) as $h ) {
			$hosts[] = strtolower( trim( (string) $h ) );
		}
		return array_values( array_filter( array_unique( array_map( 'strtolower', $hosts ) ) ) );
	}

	/**
	 * Fill blanks from what the site already has. Never overwrites a saved value.
	 */
	public static function seed(): void {
		$s = self::all();

		if ( ! $s['landing_page_id'] ) {
			$page                 = get_page_by_path( 'newsletter' );
			$s['landing_page_id'] = $page ? (int) $page->ID : 0;
		}

		if ( ! $s['rybbit_src'] || ! $s['rybbit_site_id'] ) {
			$md      = get_option( 'marketers_delight', array() );
			$scripts = (string) ( $md['settings']['footer_scripts'] ?? '' ) . (string) ( $md['settings']['header_scripts'] ?? '' );
			if ( preg_match( '/<script\b[^>]*>/i', $scripts ) ) {
				foreach ( array_filter( preg_split( '/(?=<script\b)/i', $scripts ) ) as $tag ) {
					if ( preg_match( '/\bsrc=["\']([^"\']*\/script\.js)["\']/i', $tag, $src ) && preg_match( '/\bdata-site-id=["\']([A-Za-z0-9_-]+)["\']/i', $tag, $id ) ) {
						$s['rybbit_src']     = $src[1];
						$s['rybbit_site_id'] = $id[1];
						break;
					}
				}
			}
		}

		if ( ! $s['mirror_list_ids'] ) {
			foreach ( self::crm_lists() as $id => $name ) {
				if ( 'Newsletter' === $name ) {
					$s['mirror_list_ids'] = array( (int) $id );
				}
			}
		}

		if ( ! $s['privacy_url'] ) {
			$s['privacy_url'] = (string) get_privacy_policy_url();
		}

		if ( 'fluenthive-theme' === $s['theme_storage_key'] && function_exists( 'md_setting' ) ) {
			$s['theme_storage_key'] = 'md-theme'; // share the Marketers Delight toggle's choice
		}
		if ( $s['rybbit_src'] && $s['rybbit_site_id'] && ! isset( get_option( self::OPTION, array() )['rybbit_enabled'] ) ) {
			$s['rybbit_enabled'] = true;
		}

		if ( '' === trim( (string) $s['header_links'] ) ) {
			$s['header_links'] = 'All issues | /' . self::issue_base() . '/';
		}

		if ( '' === trim( (string) $s['footer_links'] ) ) {
			$lines = array( 'All issues | /' . self::issue_base() . '/', get_bloginfo( 'name' ) . ' | /' );
			if ( $s['privacy_url'] ) {
				$lines[] = 'Privacy | ' . $s['privacy_url'];
			}
			$s['footer_links'] = implode( "\n", $lines );
		}

		update_option( self::OPTION, $s, false );
	}

	/** Queue a one-time admin notice for the current user. */
	public static function flash( string $message, bool $error = false ): void {
		if ( '' === $message ) {
			return;
		}
		set_transient( 'fluenthive_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 60 );
	}

	/** Print and clear the queued notice. Notices queued as plain strings count as successes. */
	public static function print_flash(): void {
		$key    = 'fluenthive_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$notice = is_array( $notice ) ? $notice : array( 'message' => (string) $notice );
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', empty( $notice['error'] ) ? 'notice-success' : 'notice-error', esc_html( (string) ( $notice['message'] ?? '' ) ) );
	}

	public static function maybe_flush(): void {
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/* ---------- data sources for the screen ---------- */

	/** FluentCRM lists as ID => name. */
	public static function crm_lists(): array {
		return CRM_Store::lists();
	}

	public static function core_forms(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'core-form', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $f ) {
			$out[ $f->post_name ] = $f->post_title;
		}
		return $out;
	}

	/* ---------- registration and sanitizing ---------- */

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Issues::TYPE, 'FluentHive Settings', 'Settings', 'manage_options', 'fluenthive', array( __CLASS__, 'screen' ) );
	}

	public static function register(): void {
		register_setting(
			'fluenthive',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();
		$out = self::all();
		$tab = sanitize_key( $in['_tab'] ?? '' );

		foreach ( array( 'brand_name', 'author_name', 'author_initials', 'archive_title', 'subscribe_heading', 'footer_note', 'rybbit_site_id', 'form_shortcode', 'byline', 'sender_line', 'subscribe_label', 'sender_name', 'subject_template', 'email_footer', 'copyright', 'consent_version', 'fc_customer_list' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'tagline', 'archive_intro', 'subscribe_facts', 'header_links', 'footer_links', 'fc_category_tags', 'fc_consent_disclosure' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		foreach ( array( 'avatar_url', 'rybbit_src', 'privacy_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( $in[ $k ] );
			}
		}
		foreach ( array( 'theme_storage_key', 'substack_publication' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_key( $in[ $k ] );
			}
		}
		if ( isset( $in['form_slug'] ) ) {
			$out['form_slug'] = sanitize_title( $in['form_slug'] );
		}
		if ( isset( $in['landing_page_id'] ) ) {
			$out['landing_page_id'] = absint( $in['landing_page_id'] );
		}
		foreach ( array( 'avatar_id', 'logo_id', 'logo_dark_id' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = absint( $in[ $k ] );
			}
		}
		if ( isset( $in['landing_recent'] ) ) {
			$out['landing_recent'] = min( 50, absint( $in['landing_recent'] ) );
		}
		if ( isset( $in['logo_width'] ) ) {
			$out['logo_width'] = min( 400, max( 40, absint( $in['logo_width'] ) ) );
		}
		if ( isset( $in['header_style'] ) ) {
			$out['header_style'] = in_array( $in['header_style'], array( 'avatar', 'logo', 'logo_text' ), true ) ? $in['header_style'] : 'avatar';
		}
		if ( isset( $in['issue_base'] ) ) {
			$old               = self::issue_base();
			$out['issue_base'] = $in['issue_base'];
			$out['issue_base'] = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', trim( (string) $in['issue_base'], '/' ) ) ) ) ) ?: 'newsletter/issue';
			if ( $out['issue_base'] !== $old ) {
				update_option( self::FLUSH_FLAG, 1, false );
			}
		}
		if ( isset( $in['internal_hosts'] ) ) {
			$out['internal_hosts'] = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', (string) $in['internal_hosts'] ) ) ) );
		}
		if ( isset( $in['color_scheme'] ) ) {
			$out['color_scheme'] = in_array( $in['color_scheme'], array( 'auto', 'light', 'dark' ), true ) ? $in['color_scheme'] : 'auto';
		}
		foreach ( array( 'accent_light', 'accent_dark' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_hex_color( $in[ $k ] ) ?: self::defaults()[ $k ];
			}
		}
		if ( isset( $in['custom_css'] ) ) {
			$out['custom_css'] = str_replace( '<', '', wp_strip_all_tags( (string) $in['custom_css'] ) );
		}
		if ( isset( $in['mirror_status'] ) ) {
			$out['mirror_status'] = 'draft' === $in['mirror_status'] ? 'draft' : 'publish';
		}
		if ( 'fluentcrm' === $tab ) {
			$out['mirror_list_ids'] = array_values( array_filter( array_map( 'absint', (array) ( $in['mirror_list_ids'] ?? array() ) ) ) );
			$out['draft_list_ids']  = array_values( array_filter( array_map( 'absint', (array) ( $in['draft_list_ids'] ?? array() ) ) ) );
		}
		if ( isset( $in['sender_email'] ) ) {
			$out['sender_email'] = sanitize_email( $in['sender_email'] );
		}
		if ( isset( $in['head_html'] ) ) {
			$out['head_html'] = current_user_can( 'unfiltered_html' ) ? (string) $in['head_html'] : wp_kses_post( (string) $in['head_html'] );
		}
		if ( 'paid' === $tab && class_exists( '\\FluentHive\\Modules\\Paid\\Admin' ) ) {
			$out = \FluentHive\Modules\Paid\Admin::sanitize( $in, $out );
		}
		foreach ( self::CHECKBOXES[ $tab ] ?? array() as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] );
		}
		if ( 'modules' === $tab && $out['module_paid'] && ! $out['paid_tiers'] && ! $out['paid_seeded'] ) {
			// First switch-on: start with Free (the existing lists) plus one Paid tier.
			$out['paid_tiers']  = array(
				array(
					'key'         => 'paid',
					'name'        => __( 'Paid', 'fluenthive' ),
					'description' => __( 'Every issue, including the paid-only ones, and the full archive.', 'fluenthive' ),
					'list_id'     => CRM_Store::available() ? CRM_Store::create_list( __( 'Paid subscribers', 'fluenthive' ), __( 'Managed by FluentHive from FluentCart purchases.', 'fluenthive' ) ) : 0,
					'product_id'  => 0,
					'plans'       => array(),
				),
			);
			$out['paid_seeded'] = true;
		}
		return $out;
	}

	/* ---------- tools ---------- */

	public static function run_tool(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'fluenthive_tool' );
		$tool = sanitize_key( wp_unslash( $_REQUEST['tool'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		$msg   = '';
		$error = false;
		$back  = 'tools';
		switch ( $tool ) {
			case 'sync':
				$r   = CRM_Bridge::sync();
				$msg = sprintf( 'Sync finished: %d campaign(s) linked, %d newsletter(s) mirrored as %s issues.', $r['linked'], $r['mirrored'], 'draft' === self::get( 'mirror_status' ) ? 'draft' : 'published' );
				break;
			case 'release_held':
				delete_option( CRM_Bridge::HOLD_OPTION );
				$r   = CRM_Bridge::sync();
				$msg = sprintf( 'Past campaigns released: %d newsletter(s) mirrored.', $r['mirrored'] );
				break;
			case 'relink':
				$msg = sprintf( 'Relinked %d issue(s).', Importer::relink() );
				break;
			case 'import':
				$slugs = array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', sanitize_textarea_field( wp_unslash( $_POST['slugs'] ?? '' ) ) ) ) );
				$pub   = sanitize_key( wp_unslash( $_POST['publication'] ?? '' ) );
				if ( '' === $pub ) {
					$msg   = __( 'Enter the Substack publication subdomain.', 'fluenthive' );
					$error = true;
					break;
				}
				$done  = array();
				foreach ( $slugs as $slug ) {
					$r      = Importer::substack( $slug, $pub );
					$done[] = $slug . ': ' . $r['message'];
				}
				Importer::relink();
				$msg = $done ? implode( ' · ', $done ) : 'No slugs given.';
				break;
			case 'flush':
				flush_rewrite_rules( false );
				$msg = 'Permalinks refreshed.';
				break;
			case 'seed':
				self::seed();
				$msg = 'Blank settings refilled from the site.';
				break;
			case 'register_form':
				$msg  = self::register_signup_form();
				$back = 'modules';
				break;
			case 'enable_auto_draft':
			case 'disable_auto_draft':
				$all               = self::all();
				$all['auto_draft'] = 'enable_auto_draft' === $tool;
				update_option( self::OPTION, $all, false );
				$msg  = $all['auto_draft'] ? 'Automatic drafts are on. The next issue you publish becomes a FluentCRM draft.' : 'Automatic drafts are off.';
				$back = 'fluentcrm';
				break;
			case 'draft_latest':
				$latest = get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
				$r      = $latest ? Email::create_draft( (int) $latest[0]->ID ) : array( 'message' => 'There is no published issue yet.', 'campaign_id' => 0 );
				$msg    = $r['message'];
				$error  = empty( $r['ok'] );
				$back   = 'fluentcrm';
				if ( ! empty( $r['campaign_id'] ) ) {
					set_transient( 'fluenthive_notice_' . get_current_user_id(), $msg, 60 );
					wp_safe_redirect( Email::edit_url( (int) $r['campaign_id'] ) );
					exit;
				}
				break;
		}
		self::flash( $msg, $error );
		wp_safe_redirect( add_query_arg( 'tab', $back, self::url() ) );
		exit;
	}

	/* ---------- screen ---------- */

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'general';
		$s   = self::all();

		echo '<div class="wrap fluenthive-settings"><h1>FluentHive</h1>';
		self::print_flash();
		settings_errors( 'fluenthive' );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $key === $tab ? ' nav-tab-active' : '', esc_url( add_query_arg( 'tab', $key, self::url() ) ), esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'tools' === $tab ) {
			self::tools_tab();
			echo '</div>';
			return;
		}
		if ( 'license' === $tab ) {
			self::license_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'fluenthive' );
		printf( '<input type="hidden" name="%s[_tab]" value="%s">', esc_attr( self::OPTION ), esc_attr( $tab ) );
		echo '<table class="form-table" role="presentation">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $s );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	private static function name( string $key ): string {
		return self::OPTION . '[' . $key . ']';
	}

	private static function row( string $label, string $html, string $help = '', string $for = '' ): void {
		printf(
			'<tr><th scope="row">%s</th><td>%s%s</td></tr>',
			$for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ),
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
		);
	}

	private static function text( array $s, string $key, string $label, string $help = '', string $class = 'regular-text', string $type = 'text' ): void {
		self::row( $label, sprintf( '<input type="%s" class="%s" id="fh-%s" name="%s" value="%s">', esc_attr( $type ), esc_attr( $class ), esc_attr( $key ), esc_attr( self::name( $key ) ), esc_attr( (string) $s[ $key ] ) ), $help, 'fh-' . $key );
	}

	private static function area( array $s, string $key, string $label, string $help = '', int $rows = 4, bool $code = false ): void {
		self::row( $label, sprintf( '<textarea class="large-text%s" rows="%d" id="fh-%s" name="%s">%s</textarea>', $code ? ' code' : '', $rows, esc_attr( $key ), esc_attr( self::name( $key ) ), esc_textarea( is_array( $s[ $key ] ) ? implode( "\n", $s[ $key ] ) : (string) $s[ $key ] ) ), $help, 'fh-' . $key );
	}

	private static function check( array $s, string $key, string $label, string $text, string $help = '' ): void {
		self::row( $label, sprintf( '<label><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr( self::name( $key ) ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $text ) ), $help );
	}

	private static function select( array $s, string $key, string $label, array $options, string $help = '' ): void {
		$html = sprintf( '<select id="fh-%s" name="%s">', esc_attr( $key ), esc_attr( self::name( $key ) ) );
		foreach ( $options as $value => $text ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( (string) $s[ $key ], (string) $value, false ), esc_html( $text ) );
		}
		self::row( $label, $html . '</select>', $help, 'fh-' . $key );
	}

	private static function media( array $s, string $key, string $label, string $help = '' ): void {
		$id  = (int) $s[ $key ];
		$img = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		$html = sprintf(
			'<div class="fh-media" data-target="fh-%1$s"><input type="hidden" id="fh-%1$s" name="%2$s" value="%3$d"><img class="fh-media__preview" src="%4$s" alt=""%5$s> <button type="button" class="button fh-media__pick">Choose image</button> <button type="button" class="button-link-delete fh-media__clear"%5$s>Remove</button></div>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			$id,
			esc_url( (string) $img ),
			$img ? '' : ' hidden'
		);
		self::row( $label, $html, $help );
	}

	public static function admin_assets( string $hook ): void {
		if ( ! isset( $_GET['page'] ) || 'fluenthive' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_enqueue_media();
		wp_add_inline_style( 'wp-admin', '.fh-media__preview{display:block;max-width:200px;max-height:90px;margin:0 0 8px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;padding:4px}.fh-media__preview[hidden],.fh-media__clear[hidden]{display:none}.fluenthive-settings h2.title{margin-top:2em}' );
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){\$(document).on('click','.fh-media__pick',function(e){e.preventDefault();var box=\$(this).closest('.fh-media');var frame=wp.media({title:'Choose image',library:{type:'image'},multiple:false});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();box.find('input').val(a.id);var u=(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url);box.find('.fh-media__preview').attr('src',u).prop('hidden',false);box.find('.fh-media__clear').prop('hidden',false);});frame.open();});\$(document).on('click','.fh-media__clear',function(e){e.preventDefault();var box=\$(this).closest('.fh-media');box.find('input').val(0);box.find('.fh-media__preview').attr('src','').prop('hidden',true);\$(this).prop('hidden',true);});});"
		);
	}

	private static function heading( string $text ): void {
		echo '</table><h2 class="title">' . esc_html( $text ) . '</h2><table class="form-table" role="presentation">';
	}

	private static function tab_general( array $s ): void {
		self::text( $s, 'brand_name', __( 'Newsletter name', 'fluenthive' ) );
		self::text( $s, 'author_name', __( 'Sender name', 'fluenthive' ), __( 'Shown on every issue as the email sender.', 'fluenthive' ) );
		self::text( $s, 'byline', __( 'Byline', 'fluenthive' ), __( 'Shown under the newsletter name in the header, for example "by Jane Doe". Leave blank to hide.', 'fluenthive' ) );
		self::text( $s, 'sender_line', __( 'Sender line', 'fluenthive' ), __( 'The "from" line on each issue. Default: "{Sender name} · {Newsletter name}".', 'fluenthive' ), 'large-text' );
		self::area( $s, 'tagline', __( 'Tagline', 'fluenthive' ), __( 'Used on the archive and in the subscribe box.', 'fluenthive' ), 2 );
		self::heading( __( 'Branding', 'fluenthive' ) );
		self::select( $s, 'header_style', __( 'Header shows', 'fluenthive' ), array( 'avatar' => 'Avatar, newsletter name and byline', 'logo_text' => 'Logo, newsletter name and byline', 'logo' => 'Logo only' ) );
		self::media( $s, 'logo_id', __( 'Logo', 'fluenthive' ), __( 'SVG or PNG with a transparent background works best.', 'fluenthive' ) );
		self::media( $s, 'logo_dark_id', __( 'Logo for dark mode', 'fluenthive' ), __( 'Optional. Falls back to the main logo.', 'fluenthive' ) );
		self::text( $s, 'logo_width', __( 'Logo width (px)', 'fluenthive' ), __( 'Display width in the header. Height follows the image.', 'fluenthive' ), 'small-text', 'number' );
		self::media( $s, 'avatar_id', __( 'Avatar', 'fluenthive' ), __( 'Square photo or mark shown in the header and on each issue. Falls back to the initials.', 'fluenthive' ) );
		self::text( $s, 'author_initials', __( 'Avatar initials', 'fluenthive' ), __( 'Used when no avatar image is set.', 'fluenthive' ), 'small-text' );
		self::heading( __( 'Signup', 'fluenthive' ) );
		self::row(
			'Newsletter page',
			wp_dropdown_pages(
				array(
					'name'              => self::name( 'landing_page_id' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its output.
					'id'                => 'fh-landing_page_id',
					'selected'          => (int) $s['landing_page_id'],
					'show_option_none'  => '— None —',
					'option_none_value' => 0,
					'echo'              => 0,
				)
			),
			'The signup page the header brand links to.',
			'fh-landing_page_id'
		);
		$forms = array( '' => '— None —' ) + self::core_forms();
		self::select( $s, 'form_slug', __( 'Signup form', 'fluenthive' ), $forms, __( 'The Core Forms form shown on issue pages and the archive. It must be registered with the site FluentCRM consent policy, or signups fail closed.', 'fluenthive' ) );
		self::text( $s, 'form_shortcode', __( 'Custom form shortcode', 'fluenthive' ), __( 'Optional. Overrides the form above, for example a different form plugin.', 'fluenthive' ), 'large-text code' );
		self::text( $s, 'subscribe_label', __( 'Header button label', 'fluenthive' ) );
	}

	private static function tab_issues( array $s ): void {
		self::text( $s, 'issue_base', __( 'Issue URL base', 'fluenthive' ), 'Issues live at <code>' . esc_html( home_url( '/' . self::issue_base() . '/' ) ) . '{slug}/</code>. Changing this refreshes permalinks; old URLs are not redirected.', 'regular-text code' );
		self::text( $s, 'archive_title', __( 'Archive title', 'fluenthive' ), __( 'Default: "{Newsletter name}: every issue".', 'fluenthive' ) );
		self::area( $s, 'archive_intro', __( 'Archive intro', 'fluenthive' ), __( 'Default: the tagline.', 'fluenthive' ), 2 );
		self::check( $s, 'show_subscribe', __( 'Subscribe box', 'fluenthive' ), __( 'Show the signup box under every issue and on the archive', 'fluenthive' ) );
		self::text( $s, 'subscribe_heading', __( 'Subscribe heading', 'fluenthive' ), '', 'large-text' );
		self::area( $s, 'subscribe_facts', __( 'Subscribe facts', 'fluenthive' ), __( 'One short fact per line, shown with dot bullets under the form.', 'fluenthive' ), 3 );
		self::check( $s, 'show_pager', __( 'Issue navigation', 'fluenthive' ), __( 'Show previous and next issue links', 'fluenthive' ) );
		self::check( $s, 'show_share', __( 'Share links', 'fluenthive' ), __( 'Show share links under every issue', 'fluenthive' ), __( 'X, LinkedIn, Facebook, Bluesky, Threads, WhatsApp, email and copy link. Plain links, no third-party scripts.', 'fluenthive' ) );
		self::check( $s, 'nofollow', __( 'External links', 'fluenthive' ), __( 'Add rel="nofollow noopener" to every external link in issues', 'fluenthive' ) );
		self::check( $s, 'external_new_tab', __( 'Open externally', 'fluenthive' ), __( 'Open external links in a new tab', 'fluenthive' ) );
		self::area( $s, 'internal_hosts', __( 'Extra internal hosts', 'fluenthive' ), __( 'Hosts treated as internal (no nofollow). The site and its subdomains are always internal. One per line.', 'fluenthive' ), 3 );
	}

	private static function tab_design( array $s ): void {
		self::check( $s, 'detach_theme', __( 'Theme isolation', 'fluenthive' ), __( 'Detach theme styles, scripts and hooks on FluentHive pages', 'fluenthive' ), __( 'WordPress core and every plugin keep working, including Functionalities fonts, Rank Math, Core Forms and Perfmatters.', 'fluenthive' ) );
		self::check( $s, 'landing_shell', __( 'Newsletter page', 'fluenthive' ), __( 'Render the newsletter page in the FluentHive design', 'fluenthive' ), __( 'Signup card, recent issues, then the page’s own content as a reading card. The page template setting is ignored while this is on.', 'fluenthive' ) );
		self::text( $s, 'landing_recent', __( 'Recent issues on the newsletter page', 'fluenthive' ), __( 'How many recent issues to list, up to 50. Set 0 to hide the section.', 'fluenthive' ), 'small-text', 'number' );
		self::select( $s, 'color_scheme', __( 'Color scheme', 'fluenthive' ), array( 'auto' => 'Follow the reader’s system setting', 'light' => 'Always light', 'dark' => 'Always dark' ) );
		self::check( $s, 'show_theme_toggle', __( 'Theme toggle', 'fluenthive' ), __( 'Show a light/dark toggle in the header', 'fluenthive' ), __( 'Starts from the reader’s system setting. A choice is remembered and shared with the main site’s toggle. Hidden when the scheme is forced to light or dark.', 'fluenthive' ) );
		self::text( $s, 'accent_light', __( 'Accent (light)', 'fluenthive' ), '', 'small-text', 'color' );
		self::text( $s, 'accent_dark', __( 'Accent (dark)', 'fluenthive' ), __( 'Used for buttons and links on dark backgrounds.', 'fluenthive' ), 'small-text', 'color' );
		self::area( $s, 'header_links', __( 'Header links', 'fluenthive' ), __( 'One per line as <code>Label | URL</code>. A Subscribe button is always added.', 'fluenthive' ), 3, true );
		self::text( $s, 'footer_note', __( 'Footer note', 'fluenthive' ), '', 'large-text' );
		self::area( $s, 'footer_links', __( 'Footer links', 'fluenthive' ), __( 'One per line as <code>Label | URL</code>.', 'fluenthive' ), 4, true );
		self::text( $s, 'privacy_url', __( 'Privacy policy URL', 'fluenthive' ), '', 'large-text', 'url' );
		self::area( $s, 'custom_css', __( 'Extra CSS', 'fluenthive' ), __( 'Loaded after FluentHive’s stylesheet on its pages only.', 'fluenthive' ), 6, true );
	}

	private static function list_boxes( array $s, string $key, array $lists ): string {
		$boxes = '';
		foreach ( $lists as $id => $name ) {
			$boxes .= sprintf( '<label style="display:block;margin:.2em 0"><input type="checkbox" name="%s[]" value="%d"%s> %s</label>', esc_attr( self::name( $key ) ), (int) $id, checked( in_array( (int) $id, array_map( 'intval', (array) $s[ $key ] ), true ), true, false ), esc_html( $name ) );
		}
		return $boxes ?: 'FluentCRM is not active, or it has no lists yet.';
	}

	private static function tab_fluentcrm( array $s ): void {
		$lists  = self::crm_lists();
		$sender = Email::crm_sender();
		$state  = Settings::on( 'auto_draft' )
			? '<strong style="color:#008a20">On.</strong> Publishing a new issue creates a draft campaign in FluentCRM automatically.'
			: '<strong>Off.</strong> Turn it on to get a ready-to-review FluentCRM draft every time you publish an issue.';
		self::row( __( 'One-click email drafts', 'fluenthive' ), $state . self::tool_button_html( Settings::on( 'auto_draft' ) ? 'disable_auto_draft' : 'enable_auto_draft', Settings::on( 'auto_draft' ) ? 'Turn off' : 'Turn on automatic drafts', Settings::on( 'auto_draft' ) ? 'secondary' : 'primary' ) . self::tool_button_html( 'draft_latest', 'Create a draft for the latest issue', 'secondary' ), __( 'Drafts are never scheduled or sent. Open FluentCRM → Emails → Campaigns to review and send. Every draft includes Unsubscribe and Manage subscription links, which FluentCRM fills in per recipient. No FluentCRM Automation is needed.', 'fluenthive' ) );
		echo '<tr><td colspan="2" style="padding:0"><input type="hidden" name="' . esc_attr( self::name( 'auto_draft' ) ) . '" value="' . esc_attr( Settings::on( 'auto_draft' ) ? '1' : '' ) . '"></td></tr>';
		self::row( __( 'Send drafts to', 'fluenthive' ), self::list_boxes( $s, 'draft_list_ids', $lists ), __( 'Leave empty to use the mirror lists below.', 'fluenthive' ) );
		self::text( $s, 'subject_template', __( 'Subject line', 'fluenthive' ), __( 'Placeholders: <code>{title}</code>, <code>{newsletter}</code>.', 'fluenthive' ), 'regular-text' );
		self::text( $s, 'sender_name', __( 'From name', 'fluenthive' ), 'Default from FluentCRM: ' . esc_html( $sender['name'] ) . '.' );
		self::text( $s, 'sender_email', __( 'From email', 'fluenthive' ), 'Default from FluentCRM: ' . esc_html( $sender['email'] ) . '.', 'regular-text', 'email' );
		self::text( $s, 'email_footer', __( 'Email footer note', 'fluenthive' ), __( 'Shown above the Unsubscribe and Manage subscription links.', 'fluenthive' ), 'large-text' );
		self::heading( __( 'Campaigns written in FluentCRM', 'fluenthive' ) );
		self::check( $s, 'mirror_enabled', __( 'Mirror campaigns', 'fluenthive' ), __( 'Publish newsletters composed directly in FluentCRM as issues once they are sent', 'fluenthive' ) );
		$help = __( 'Only campaigns sent to at least one of these lists are mirrored. Customer and transactional sends stay private.', 'fluenthive' );
		if ( CRM_Bridge::held() ) {
			$help .= ' ' . __( 'Newsletters sent before FluentHive was installed are held back until you use Mirror past campaigns on the Tools tab.', 'fluenthive' );
		}
		self::row( __( 'Mirror lists', 'fluenthive' ), self::list_boxes( $s, 'mirror_list_ids', $lists ), $help );
		self::select( $s, 'mirror_status', __( 'Mirrored issue status', 'fluenthive' ), array( 'publish' => 'Publish immediately', 'draft' => 'Save as draft for review' ) );
		self::check( $s, 'redirect_web_view', __( 'View in browser', 'fluenthive' ), __( 'Send FluentCRM "view in browser" links to the matching issue page', 'fluenthive' ) );
		self::row( __( 'Subscription pages', 'fluenthive' ), esc_html__( 'FluentCRM serves its own unsubscribe and manage-subscription pages.', 'fluenthive' ), __( 'Set the preference lists and page in FluentCRM → Settings → Email Settings.', 'fluenthive' ) );
	}

	/** A tool button rendered inside the settings form (submits to admin-post via formaction). */
	private static function tool_button_html( string $tool, string $label, string $class ): string {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'fluenthive_tool', 'tool' => $tool ), admin_url( 'admin-post.php' ) ), 'fluenthive_tool' );
		return ' <p style="margin:.6em 0 0"><a class="button button-' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	private static function tab_analytics( array $s ): void {
		self::check( $s, 'rybbit_enabled', __( 'Rybbit', 'fluenthive' ), __( 'Load the Rybbit tracking script on FluentHive pages', 'fluenthive' ), __( 'The site’s Rybbit tag comes from the theme’s footer scripts, which theme isolation detaches, so FluentHive prints its own copy.', 'fluenthive' ) );
		self::text( $s, 'rybbit_src', __( 'Script URL', 'fluenthive' ), '', 'large-text code', 'url' );
		self::text( $s, 'rybbit_site_id', __( 'Site ID', 'fluenthive' ), '', 'regular-text code' );
		self::area( $s, 'head_html', __( 'Extra head HTML', 'fluenthive' ), __( 'Optional tags printed in the &lt;head&gt; of FluentHive pages, such as another analytics or verification tag.', 'fluenthive' ), 4, true );
	}

	private static function tab_paid( array $s ): void {
		if ( ! class_exists( '\\FluentHive\\Modules\\Paid\\Admin' ) ) {
			self::row( __( 'Paid tiers', 'fluenthive' ), '<a href="' . esc_url( add_query_arg( 'tab', 'modules', self::url() ) ) . '">' . esc_html__( 'Turn on Paid subscriptions on the Modules tab first.', 'fluenthive' ) . '</a>' );
			return;
		}
		\FluentHive\Modules\Paid\Admin::tab( $s );
	}

	private static function tab_modules( array $s ): void {
		self::check( $s, 'module_consent_policy', __( 'Consent policy', 'fluenthive' ), __( 'Gate Core Forms → FluentCRM signups on a reviewed consent route', 'fluenthive' ), __( 'Only registered forms can add contacts, the consent checkbox must carry the exact disclosure on record, spam and existing opt-outs are respected, and consent evidence is saved on the contact. Unregistered forms fail closed.', 'fluenthive' ) );
		$policy = get_option( 'fluenthive_consent_policy', array() );
		$forms  = is_array( $policy['forms'] ?? null ) ? $policy['forms'] : array();
		$slug   = (string) self::get( 'form_slug' );
		$form   = $slug ? get_page_by_path( $slug, OBJECT, 'core-form' ) : null;
		$state  = sprintf( /* translators: %d: number of forms */ _n( '%d form registered.', '%d forms registered.', count( $forms ), 'fluenthive' ), count( $forms ) );
		if ( $form ) {
			$state .= ' ' . ( isset( $forms[ (string) $form->ID ] ) ? esc_html__( 'Your signup form is registered.', 'fluenthive' ) : '<strong>' . esc_html__( 'Your signup form is not registered yet.', 'fluenthive' ) . '</strong>' );
		}
		if ( class_exists( '\\FluentHive\\Modules\\Consent_Policy' ) && ! \FluentHive\Modules\Consent_Policy::core_forms_ready() ) {
			$state .= ' <strong style="color:#b32d2e">' . esc_html__( 'Core Forms has its FluentCRM integration switched off, so no form can add contacts. Turn it on in Core Forms → Modules.', 'fluenthive' ) . '</strong>';
		}
		self::row( __( 'Registered forms', 'fluenthive' ), $state . self::tool_button_html( 'register_form', __( 'Register the signup form', 'fluenthive' ), 'secondary' ), __( 'Registers the form chosen on the General tab using its FluentCRM action and consent checkbox. Re-run after editing that form’s action or consent wording.', 'fluenthive' ) );
		self::text( $s, 'consent_version', __( 'Consent version', 'fluenthive' ), __( 'Saved with every new consent record. Change it when you change the disclosure wording.', 'fluenthive' ), 'regular-text code' );
		self::check( $s, 'consent_double_optin', __( 'Confirm signups', 'fluenthive' ), __( 'Send FluentCRM\'s double opt-in email for every form signup', 'fluenthive' ), __( 'New contacts are added as pending and become subscribed when they click the link. Off by default: contacts are added as subscribed and no email is sent.', 'fluenthive' ) );
		self::heading( __( 'Paid subscriptions', 'fluenthive' ) );
		self::check( $s, 'module_paid', __( 'Paid tiers', 'fluenthive' ), __( 'Sell newsletter subscriptions through FluentCart', 'fluenthive' ), __( 'Recurring plans follow the FluentCart subscription; one-time plans give a fixed term or lifetime access. Paid issues show a preview and the plans, and each tier gets its own FluentCRM list. Set tiers on the Paid tab.', 'fluenthive' ) );
		self::heading( __( 'FluentCart', 'fluenthive' ) );
		self::check( $s, 'module_fluentcart_lifecycle', __( 'Lifecycle tags', 'fluenthive' ), __( 'Tag FluentCRM contacts from FluentCart purchases, refunds and abandoned checkouts', 'fluenthive' ), __( 'A paid order creates a contact and applies purchase tags. The contact is subscribed and added to the customer list only when the buyer agreed at checkout: they ticked FluentCRM\'s checkout checkbox, or a custom field posted the disclosure below. Existing contacts are never re-subscribed.', 'fluenthive' ) );
		self::text( $s, 'fc_customer_list', __( 'Customer list', 'fluenthive' ), __( 'FluentCRM list name for paying customers who agreed at checkout.', 'fluenthive' ) );
		self::area( $s, 'fc_consent_disclosure', __( 'Checkout disclosure', 'fluenthive' ), __( 'Only for a custom checkout field: the exact text it must post as fluenthive_disclosure. FluentCRM\'s own checkout checkbox needs nothing here; its label is saved as the disclosure. With neither, a purchase never counts as newsletter consent.', 'fluenthive' ), 4 );
		self::area( $s, 'fc_category_tags', __( 'Category tags', 'fluenthive' ), __( 'One per line as <code>product-category-slug | Tag name</code>. Buyers of products in that category get the tag.', 'fluenthive' ), 4, true );
		self::heading( __( 'Imports', 'fluenthive' ) );
		self::text( $s, 'substack_publication', __( 'Substack publication', 'fluenthive' ), __( 'Subdomain only, for example <code>yourname</code> for yourname.substack.com. Used by the importer and relinking.', 'fluenthive' ), 'regular-text code' );
	}

	/** Register the configured signup form with the consent policy. */
	private static function register_signup_form(): string {
		if ( ! class_exists( '\\FluentHive\\Modules\\Consent_Policy' ) ) {
			return __( 'Turn on the consent policy module first.', 'fluenthive' );
		}
		$slug = (string) self::get( 'form_slug' );
		$post = $slug ? get_page_by_path( $slug, OBJECT, 'core-form' ) : null;
		if ( ! $post || ! function_exists( 'cf_get_form' ) ) {
			return __( 'Choose a Core Forms signup form on the General tab first.', 'fluenthive' );
		}
		$entry = \FluentHive\Modules\Consent_Policy::describe_form( cf_get_form( (int) $post->ID ) );
		if ( is_string( $entry ) ) {
			return $entry;
		}
		\FluentHive\Modules\Consent_Policy::register_form( (int) $post->ID, $entry );
		/* translators: %s: form title */
		return sprintf( __( '“%s” is registered with the consent policy.', 'fluenthive' ), $post->post_title );
	}

	/* ---------- license ---------- */

	public static function license_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fluenthive' ) );
		}
		check_admin_referer( 'fluenthive_license' );
		$license = new \FluentHive\Licensing\License();
		$op      = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		if ( 'activate' === $op ) {
			$result = $license->activate( (string) wp_unslash( $_POST['license_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and encrypted in activate().
			$ok     = __( 'License activated. This site now gets FluentHive updates.', 'fluenthive' );
		} elseif ( 'check' === $op ) {
			$result = $license->check();
			$ok     = __( 'License is active.', 'fluenthive' );
		} else {
			$result = $license->disconnect();
			$ok     = __( 'License disconnected from this site.', 'fluenthive' );
		}
		self::flash( is_wp_error( $result ) ? $result->get_error_message() : $ok, is_wp_error( $result ) );
		wp_safe_redirect( add_query_arg( 'tab', 'license', self::url() ) );
		exit;
	}

	/** One quiet reminder on the plugins screen and FluentHive pages until a license is active. */
	public static function license_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ! in_array( $screen->id, array( 'plugins', 'fh_issue_page_fluenthive', 'edit-fh_issue' ), true ) ) {
			return;
		}
		if ( 'valid' === ( ( new \FluentHive\Licensing\License() )->state()['status'] ?? '' ) || ( isset( $_GET['tab'] ) && 'license' === $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Activate your free FluentHive license to get automatic updates.', 'fluenthive' ),
			esc_url( add_query_arg( 'tab', 'license', self::url() ) ),
			esc_html__( 'Activate license', 'fluenthive' )
		);
	}

	private static function license_tab(): void {
		$license = new \FluentHive\Licensing\License();
		$state   = $license->state();
		$form    = static function ( string $op, string $label, string $class = 'secondary', string $extra = '' ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 .5rem .5rem 0">';
			wp_nonce_field( 'fluenthive_license' );
			echo '<input type="hidden" name="action" value="fluenthive_license"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
			submit_button( $label, $class, 'submit', false );
			echo '</form>';
		};
		echo '<h2>' . esc_html__( 'License', 'fluenthive' ) . '</h2>';
		echo '<p>' . esc_html__( 'The license only unlocks automatic updates. Every FluentHive feature works without it.', 'fluenthive' ) . '</p>';
		if ( $state ) {
			$valid = 'valid' === ( $state['status'] ?? '' );
			echo '<table class="widefat striped" style="max-width:640px"><tbody>';
			echo '<tr><th style="width:180px">' . esc_html__( 'Status', 'fluenthive' ) . '</th><td>' . ( $valid ? '<strong style="color:#008a20">' . esc_html__( 'Active', 'fluenthive' ) . '</strong>' : '<strong style="color:#b32d2e">' . esc_html__( 'Inactive', 'fluenthive' ) . '</strong>' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Key', 'fluenthive' ) . '</th><td><code>&bull;&bull;&bull;&bull;' . esc_html( (string) ( $state['suffix'] ?? '' ) ) . '</code></td></tr>';
			if ( ! empty( $state['plan'] ) ) {
				echo '<tr><th>' . esc_html__( 'Plan', 'fluenthive' ) . '</th><td>' . esc_html( (string) $state['plan'] ) . '</td></tr>';
			}
			echo '<tr><th>' . esc_html__( 'Expires', 'fluenthive' ) . '</th><td>' . esc_html( ! empty( $state['expires'] ) && 'lifetime' !== $state['expires'] ? (string) $state['expires'] : __( 'Never', 'fluenthive' ) ) . '</td></tr>';
			if ( ! empty( $state['checked_at'] ) ) {
				echo '<tr><th>' . esc_html__( 'Last checked', 'fluenthive' ) . '</th><td>' . esc_html( wp_date( 'M j, Y H:i', (int) $state['checked_at'] ) ) . '</td></tr>';
			}
			echo '</tbody></table><p></p>';
			$form( 'check', __( 'Check license', 'fluenthive' ) );
			$form( 'disconnect', __( 'Disconnect from this site', 'fluenthive' ) );
		} else {
			$form( 'activate', __( 'Activate license', 'fluenthive' ), 'primary', '<p><label for="fh-license-key"><strong>' . esc_html__( 'License key', 'fluenthive' ) . '</strong></label><br><input type="password" id="fh-license-key" name="license_key" class="regular-text code" autocomplete="off" spellcheck="false" required></p>' );
			printf(
				'<p class="description">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
				esc_html__( 'No key yet? The license is free, for unlimited sites.', 'fluenthive' ),
				esc_url( \FluentHive\Licensing\Client::PAGE ),
				esc_html__( 'Get your free license', 'fluenthive' )
			);
		}
	}

	private static function tools_tab(): void {
		$counts = array();
		foreach ( get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			$src            = (string) get_post_meta( $id, Issues::META_SOURCE, true ) ?: 'wp';
			$counts[ $src ] = ( $counts[ $src ] ?? 0 ) + 1;
		}
		$where = static function ( string $class ): string {
			if ( ! class_exists( $class, false ) ) {
				return '<span style="color:#b32d2e">not loaded</span>';
			}
			$file = wp_normalize_path( (string) ( new \ReflectionClass( $class ) )->getFileName() );
			return str_contains( $file, '/fluenthive/' ) ? 'on' : 'loaded from elsewhere';
		};
		$last = get_option( self::SYNC_OPTION, array() );
		$next = wp_next_scheduled( CRM_Bridge::SYNC_HOOK );
		$mpv  = CRM_Bridge::version();
		$rows = array(
			'FluentCRM'                 => esc_html( (string) $mpv ),
			'Consent policy module'       => self::on( 'module_consent_policy' ) ? $where( 'FluentHive\\Modules\\Consent_Policy' ) : 'off',
			'FluentCart lifecycle module' => self::on( 'module_fluentcart_lifecycle' ) ? $where( 'FluentHive\\Modules\\FluentCart_Lifecycle' ) : 'off',
			'Paid subscriptions module'   => self::on( 'module_paid' ) ? $where( 'FluentHive\\Modules\\Paid\\Members' ) . ( wp_next_scheduled( 'fluenthive_paid_sweep' ) ? ', next expiry check ' . esc_html( wp_date( 'M j, H:i', (int) wp_next_scheduled( 'fluenthive_paid_sweep' ) ) ) : '' ) : 'off',
			'Issues'                      => $counts ? esc_html( implode( ', ', array_map( static fn( $k, $v ) => "{$v} {$k}", array_keys( $counts ), $counts ) ) ) : '0',
			'Issue URLs'                  => '<code>' . esc_html( home_url( '/' . self::issue_base() . '/{slug}/' ) ) . '</code>',
			'Last campaign sync'          => $last ? esc_html( sprintf( '%s: %d linked, %d mirrored', wp_date( 'M j, Y H:i', (int) $last['time'] ), $last['linked'], $last['mirrored'] ) ) : 'never',
			'Next scheduled sync'         => $next ? esc_html( wp_date( 'M j, Y H:i', $next ) ) : 'not scheduled',
		);
		echo '<h2>Status</h2><table class="widefat striped" style="max-width:820px"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:240px">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</tbody></table>';

		$button = static function ( string $tool, string $label, string $help, string $extra = '' ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
			wp_nonce_field( 'fluenthive_tool' );
			echo '<input type="hidden" name="action" value="fluenthive_tool"><input type="hidden" name="tool" value="' . esc_attr( $tool ) . '">';
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
			submit_button( $label, 'secondary', 'submit', false );
			echo ' <span class="description">' . esc_html( $help ) . '</span></form>';
		};
		echo '<h2>Tools</h2>';
		$button( 'sync', 'Sync FluentCRM now', 'Link drafted campaigns to their issues and mirror sent newsletters.' );
		$held = count( CRM_Bridge::held() );
		if ( $held ) {
			/* translators: %d: number of campaigns */
			$button( 'release_held', __( 'Mirror past campaigns', 'fluenthive' ), sprintf( _n( '%d newsletter sent before FluentHive was installed is held back. This mirrors it if it went to a mirror list, using the mirrored issue status on the FluentCRM tab.', '%d newsletters sent before FluentHive was installed are held back. This mirrors the ones sent to a mirror list, using the mirrored issue status on the FluentCRM tab.', $held, 'fluenthive' ), $held ) );
		}
		$button( 'relink', 'Relink imported issues', 'Point links between imported Substack issues at their local pages.' );
		$button( 'flush', 'Refresh permalinks', 'Use after changing the issue URL base.' );
		$button( 'seed', 'Re-detect settings', 'Fill blank settings from the site. Saved values are kept.' );
		$button(
			'import',
			'Import from Substack',
			'Images are copied into the media library. Already-imported posts are skipped.',
			'<p><label>' . esc_html__( 'Publication subdomain', 'fluenthive' ) . ' <input name="publication" value="' . esc_attr( (string) self::get( 'substack_publication' ) ) . '" class="regular-text code" placeholder="yourname"></label></p><p><label>Post slugs, one per line<br><textarea name="slugs" rows="4" class="large-text code"></textarea></label></p>'
		);
	}
}
