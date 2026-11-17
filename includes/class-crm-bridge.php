<?php
/**
 * FluentCRM bridge.
 *
 * - FluentCRM "view in browser" links 301 to the matching issue page.
 * - Campaigns that FluentHive drafts from an issue are linked back to that issue.
 * - Newsletters composed directly in FluentCRM and sent to a mirrored list are
 *   published as issue pages, once.
 *
 * Nothing here sends, schedules or edits a campaign. FluentCRM owns delivery,
 * unsubscribe/manage links and the List-Unsubscribe header.
 */

namespace FluentHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- reads two FluentCRM lookups that have no model-level finder; table names come from $wpdb->prefix and values are prepared.

final class CRM_Bridge {
	const SYNC_HOOK   = 'fluenthive_sync_campaigns';
	const HOLD_OPTION = 'fluenthive_mirror_held';

	/** Campaign meta key that points a FluentCRM campaign at its issue. */
	const ISSUE_META = '_fluenthive_issue_id';

	/** FluentCRM's status for a campaign that has finished sending. */
	const SENT = 'archived';

	public static function init(): void {
		// FluentCRM routes its public pages on init:99; issues are registered on init:10.
		add_action( 'init', array( __CLASS__, 'redirect_web_view' ), 98 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function available(): bool {
		return CRM_Store::available() && class_exists( '\FluentCrm\App\Models\Campaign' );
	}

	public static function version(): string {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' ) ? (string) FLUENTCRM_PLUGIN_VERSION : ( self::available() ? 'active' : 'not active' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SYNC_HOOK );
		}
	}

	public static function campaign( int $campaign_id ) {
		return ( self::available() && $campaign_id ) ? \FluentCrm\App\Models\Campaign::find( $campaign_id ) : null;
	}

	public static function edit_url( int $campaign_id ): string {
		return admin_url( 'admin.php?page=fluentcrm-admin#/email/campaigns/' . $campaign_id );
	}

	/**
	 * Fresh installs only: hold back newsletters already sent, so the first sync
	 * doesn't publish the whole back catalogue. Tools → "Mirror past campaigns"
	 * releases them.
	 */
	public static function hold_back_catalogue(): void {
		if ( ! self::available() ) {
			return;
		}
		$ids = array_map( 'intval', \FluentCrm\App\Models\Campaign::where( 'status', self::SENT )->pluck( 'id' )->toArray() );
		if ( $ids ) {
			update_option( self::HOLD_OPTION, $ids, false );
		}
	}

	/** Campaign IDs the sync skips until the site owner releases them. */
	public static function held(): array {
		return array_map( 'intval', (array) get_option( self::HOLD_OPTION, array() ) );
	}

	public static function issue_for_campaign( int $campaign_id ): ?\WP_Post {
		$ids = get_posts(
			array(
				'post_type'      => Issues::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Issues::META_CAMPAIGN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $campaign_id,          // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'  => true,
			)
		);
		return $ids ? get_post( $ids[0] ) : null;
	}

	/**
	 * The campaign behind a FluentCRM web-view request, or 0.
	 * Per-recipient links carry _e_hash; the shareable link carries fc_newsletter.
	 */
	private static function requested_campaign_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only links from sent email.
		$param = defined( 'FLUENTCRM_EXTERNAL_URL_PARAM' ) ? FLUENTCRM_EXTERNAL_URL_PARAM : 'fluentcrm';
		if ( empty( $_GET[ $param ] ) || 'email_preview' !== sanitize_key( wp_unslash( $_GET['route'] ?? '' ) ) ) {
			return 0;
		}
		$hash  = sanitize_text_field( wp_unslash( $_GET['_e_hash'] ?? '' ) );
		$share = sanitize_text_field( wp_unslash( $_GET['fc_newsletter'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		global $wpdb;
		if ( '' !== $hash ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT campaign_id FROM {$wpdb->prefix}fc_campaign_emails WHERE email_hash = %s LIMIT 1", $hash ) );
		}
		if ( '' !== $share ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT object_id FROM {$wpdb->prefix}fc_meta WHERE object_type = %s AND `key` = '_campaign_share_id' AND value = %s LIMIT 1", 'FluentCrm\App\Models\Campaign', $share ) );
		}
		return 0;
	}

	public static function redirect_web_view(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! self::available() ) {
			return;
		}
		$campaign_id = self::requested_campaign_id();
		if ( ! $campaign_id ) {
			return;
		}
		$issue = self::issue_for_campaign( $campaign_id );
		if ( self::campaign_is_paid_only( $campaign_id ) ) {
			self::guard_paid_web_view( $campaign_id, $issue );
		}
		if ( ! Settings::on( 'redirect_web_view' ) ) {
			return;
		}
		if ( $issue && 'publish' === $issue->post_status ) {
			wp_safe_redirect( get_permalink( $issue ), 301 );
			exit;
		}
	}

	/**
	 * A sent campaign that went only to paid lists must not stay on the FluentCRM web view.
	 * Mirror it onto a published, paywalled issue, or refuse the page.
	 */
	private static function guard_paid_web_view( int $campaign_id, ?\WP_Post $issue ): void {
		if ( $issue && 'publish' === $issue->post_status ) {
			wp_safe_redirect( get_permalink( $issue ), 301 );
			exit;
		}
		if ( ! $issue ) {
			$created = self::mirror_paid_now( $campaign_id );
			$issue   = $created ? get_post( $created ) : null;
			if ( $issue && 'publish' === $issue->post_status ) {
				wp_safe_redirect( get_permalink( $issue ), 302 );
				exit;
			}
		}
		status_header( 403 );
		nocache_headers();
		wp_die( esc_html__( 'This issue is for paid subscribers.', 'fluenthive' ), esc_html__( 'Subscribers only', 'fluenthive' ), array( 'response' => 403 ) );
	}

	private static function mirror_paid_now( int $campaign_id ): int {
		global $wpdb;
		$lock  = 'fluenthive_mirror_lock_' . $campaign_id;
		$claim = static fn(): bool => 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $lock, (string) time() ) );
		if ( ! $claim() ) {
			// A request that died mid-mirror leaves its lock behind. Take it over after ten minutes.
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) );
			if ( ! $since || $since > time() - 10 * MINUTE_IN_SECONDS || ! $wpdb->delete( $wpdb->options, array( 'option_name' => $lock, 'option_value' => (string) $since ) ) || ! $claim() ) {
				$existing = self::issue_for_campaign( $campaign_id );
				return ( $existing && 'publish' === $existing->post_status ) ? (int) $existing->ID : 0;
			}
		}
		try {
			return self::mirror( $campaign_id, 'publish' );
		} finally {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $lock ) );
		}
	}

	/** Sent, intersects a paid tier list, and does not intersect a free mirror list. */
	private static function campaign_is_paid_only( int $campaign_id ): bool {
		if ( ! class_exists( '\FluentHive\Modules\Paid\Plans' ) ) {
			return false;
		}
		$campaign = self::campaign( $campaign_id );
		if ( ! $campaign || self::SENT !== (string) $campaign->status ) {
			return false;
		}
		$lists = self::list_ids( $campaign );
		if ( in_array( 0, $lists, true ) ) {
			return false; // sent to every list, so free readers got it too
		}
		$paid = \FluentHive\Modules\Paid\Plans::tier_list_ids();
		$free = array_map( 'intval', (array) Settings::get( 'mirror_list_ids' ) );
		return (bool) array_intersect( $lists, $paid ) && ! array_intersect( $lists, $free );
	}

	/**
	 * Lists a campaign is addressed to. A campaign sent to "All lists" returns [0].
	 * Campaigns sent by segment or advanced filter return an empty array: FluentHive
	 * cannot tell who they reached, so they are never mirrored automatically.
	 *
	 * @return int[]
	 */
	public static function list_ids( $campaign ): array {
		$settings = is_array( $campaign->settings ) ? $campaign->settings : array();
		if ( 'list_tag' !== ( $settings['sending_filter'] ?? 'list_tag' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) ( $settings['subscribers'] ?? array() ) as $item ) {
			$list = is_array( $item ) ? ( $item['list'] ?? null ) : null;
			if ( 'all' === $list ) {
				$ids[] = 0;
			} elseif ( is_numeric( $list ) ) {
				$ids[] = (int) $list;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Link FluentHive-drafted campaigns to their issue, then mirror sent newsletters.
	 *
	 * @return array{linked:int,mirrored:int}
	 */
	public static function sync(): array {
		$result = array( 'linked' => 0, 'mirrored' => 0 );
		if ( ! self::available() ) {
			return $result;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, value FROM {$wpdb->prefix}fc_meta WHERE object_type = %s AND `key` = %s", 'FluentCrm\App\Models\Campaign', self::ISSUE_META ) );
		foreach ( (array) $rows as $row ) {
			$post_id = (int) maybe_unserialize( $row->value );
			if ( $post_id && Issues::TYPE === get_post_type( $post_id ) && ! get_post_meta( $post_id, Issues::META_CAMPAIGN, true ) ) {
				update_post_meta( $post_id, Issues::META_CAMPAIGN, (int) $row->object_id );
				++$result['linked'];
			}
		}

		$lists = array_map( 'intval', (array) apply_filters( 'fluenthive_mirror_list_ids', (array) Settings::get( 'mirror_list_ids' ) ) );
		if ( ! $lists || ! Settings::on( 'mirror_enabled' ) ) {
			return self::record( $result );
		}
		$held = array_flip( self::held() );
		foreach ( \FluentCrm\App\Models\Campaign::where( 'status', self::SENT )->orderBy( 'id', 'ASC' )->get() as $campaign ) {
			$id = (int) $campaign->id;
			if ( isset( $held[ $id ] ) || fluentcrm_get_campaign_meta( $id, self::ISSUE_META, true ) ) {
				continue;
			}
			$sent_to = self::list_ids( $campaign );
			if ( ! in_array( 0, $sent_to, true ) && ! array_intersect( $lists, $sent_to ) ) {
				continue;
			}
			if ( self::issue_for_campaign( $id ) ) {
				continue;
			}
			if ( self::mirror( $id, 'draft' === Settings::get( 'mirror_status' ) ? 'draft' : 'publish' ) ) {
				++$result['mirrored'];
			}
		}
		return self::record( $result );
	}

	private static function record( array $result ): array {
		update_option( Settings::SYNC_OPTION, $result + array( 'time' => time() ), false );
		return $result;
	}

	/** The campaign's email as HTML. Block-built campaigns are rendered first. */
	private static function html( $campaign ): string {
		$body = (string) $campaign->email_body;
		if ( in_array( (string) $campaign->design_template, array( 'raw_html', 'raw_classic' ), true ) ) {
			return $body;
		}
		return has_blocks( $body ) ? (string) do_blocks( $body ) : $body;
	}

	/**
	 * Publish one FluentCRM campaign as an issue. Returns the post ID or 0.
	 */
	public static function mirror( int $campaign_id, string $status = 'publish', array $overrides = array() ): int {
		$existing = self::issue_for_campaign( $campaign_id );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$campaign = self::campaign( $campaign_id );
		if ( ! $campaign ) {
			return 0;
		}
		$html = self::html( $campaign );
		if ( '' === trim( $html ) ) {
			return 0;
		}
		$title   = $overrides['title'] ?? ( $campaign->email_subject ?: $campaign->title );
		$content = Content::from_email( $html );
		// FluentCRM stores campaign times in the site's timezone.
		$date    = $overrides['date'] ?? (string) ( $campaign->scheduled_at ?: $campaign->updated_at );
		$date    = $date ?: current_time( 'mysql' );

		$post_id = wp_insert_post(
			array(
				'post_type'     => Issues::TYPE,
				'post_status'   => 'draft',
				'post_title'    => wp_strip_all_tags( (string) $title ),
				'post_name'     => sanitize_title( $overrides['slug'] ?? $title ),
				'post_content'  => '',
				'post_excerpt'  => (string) $campaign->email_pre_header,
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'meta_input'    => array(
					Issues::META_SOURCE   => 'fluentcrm',
					Issues::META_CAMPAIGN => $campaign_id,
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		do_action( 'fluenthive_issue_mirrored', (int) $post_id, self::list_ids( $campaign ) );
		$content = Content::import_images( $content, (int) $post_id );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
					'post_status'  => $status,
				)
			)
		);
		return (int) $post_id;
	}
}
