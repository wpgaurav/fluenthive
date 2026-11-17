<?php
/**
 * One-click FluentCRM drafts. When an issue written in WordPress is published
 * (or when "Create FluentCRM draft" is clicked), FluentHive renders it as an email
 * and creates a DRAFT campaign with FluentCRM's own campaign model, addressed to
 * the configured lists. Nothing is scheduled or sent: the campaign waits in
 * FluentCRM → Emails → Campaigns for review. The email always carries FluentCRM's
 * unsubscribe and manage-subscription smart codes, filled in per recipient at send time.
 */

namespace FluentHive;

defined( 'ABSPATH' ) || exit;


final class Email {
	/** Transient prefix for a "Create FluentCRM draft" result waiting to be shown. */
	private const RESULT = 'fluenthive_draft_result_';

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_publish' ), 20, 4 );
		add_action( 'admin_post_fluenthive_draft', array( __CLASS__, 'handle_button' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function edit_url( int $campaign_id ): string {
		return CRM_Bridge::edit_url( $campaign_id );
	}

	public static function ready(): bool {
		return CRM_Bridge::available();
	}

	/** Lists the drafts are addressed to (falls back to the mirror lists). */
	public static function list_ids(): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) Settings::get( 'draft_list_ids' ) ) ) );
		return $ids ?: array_values( array_filter( array_map( 'intval', (array) Settings::get( 'mirror_list_ids' ) ) ) );
	}

	/** FluentCRM's own default sender (Settings → Email Settings). */
	public static function crm_sender(): array {
		$d = function_exists( 'fluentcrmGetGlobalSettings' ) ? (array) fluentcrmGetGlobalSettings( 'email_settings', array() ) : array();
		return array(
			'name'  => (string) ( $d['from_name'] ?? Settings::author() ),
			'email' => (string) ( $d['from_email'] ?? get_option( 'admin_email' ) ),
		);
	}

	public static function sender(): array {
		$d = self::crm_sender();
		return array(
			'name'  => (string) ( Settings::get( 'sender_name' ) ?: $d['name'] ),
			'email' => (string) ( Settings::get( 'sender_email' ) ?: $d['email'] ),
		);
	}

	public static function on_publish( int $post_id, \WP_Post $post, bool $update, $post_before ): void {
		if ( Issues::TYPE !== $post->post_type || 'publish' !== $post->post_status || ! Settings::on( 'auto_draft' ) ) {
			return;
		}
		if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
			return; // only the first publish
		}
		if ( 'wp' !== ( (string) get_post_meta( $post_id, Issues::META_SOURCE, true ) ?: 'wp' ) ) {
			return; // imported issues were already sent elsewhere
		}
		self::create_draft( $post_id );
	}

	public static function handle_button(): void {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'fluenthive_draft_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r = self::create_draft( $post_id );
		if ( ! $r['campaign_id'] ) {
			// No campaign to open, so the result goes back to the issue it came from.
			set_transient( self::RESULT . get_current_user_id(), array( 'post_id' => $post_id, 'ok' => $r['ok'], 'message' => $r['message'] ), 60 );
		}
		wp_safe_redirect( $r['campaign_id'] ? self::edit_url( $r['campaign_id'] ) : get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * Take the waiting draft result for the screen being viewed: the issue's own edit
	 * screen, or the issues list.
	 *
	 * @return array{post_id:int,ok:bool,message:string}|null
	 */
	private static function take_result(): ?array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Issues::TYPE !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return null;
		}
		$key    = self::RESULT . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return null;
		}
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( 'post' === $screen->base && (int) $result['post_id'] !== $post_id ) {
			return null;
		}
		delete_transient( $key );
		return $result;
	}

	/** Block editor: show the result as an editor notice (admin notices sit behind the editor). */
	public static function editor_notice(): void {
		$result = self::take_result();
		if ( ! $result ) {
			return;
		}
		$script = sprintf(
			'wp.data.dispatch("core/notices").createNotice(%s,%s,{id:"fluenthive-draft",isDismissible:true});',
			wp_json_encode( $result['ok'] ? 'success' : 'error' ),
			wp_json_encode( (string) $result['message'] )
		);
		wp_enqueue_script( 'wp-notices' );
		wp_add_inline_script( 'wp-notices', $script );
	}

	/** Classic editor and the issues list. */
	public static function admin_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->is_block_editor() ) {
			return; // handled by editor_notice()
		}
		$result = self::take_result();
		if ( $result ) {
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', $result['ok'] ? 'notice-success' : 'notice-error', esc_html( (string) $result['message'] ) );
		}
	}

	/**
	 * @return array{ok:bool,campaign_id:int,message:string}
	 */
	public static function create_draft( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || Issues::TYPE !== $post->post_type ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'Not a newsletter issue.' );
		}
		if ( ! self::ready() ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'FluentCRM is not active.' );
		}
		$existing = (int) get_post_meta( $post_id, Issues::META_CAMPAIGN, true );
		if ( $existing && CRM_Bridge::campaign( $existing ) ) {
			return array( 'ok' => true, 'campaign_id' => $existing, 'message' => 'This issue already has a FluentCRM campaign.' );
		}
		$known = CRM_Store::lists();
		$lists = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'fluenthive_draft_list_ids', self::list_ids(), $post ) ), static fn( $id ) => isset( $known[ $id ] ) ) );
		if ( ! $lists ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'This issue has no FluentCRM list to go to. Choose lists on the FluentCRM tab, or give its paid tier a list on the Paid tab.' );
		}

		$title   = wp_strip_all_tags( get_the_title( $post ) );
		$subject = str_replace( array( '{title}', '{newsletter}' ), array( $title, Settings::brand() ), (string) ( Settings::get( 'subject_template' ) ?: '{title}' ) );
		$name    = Settings::brand() . ': ' . $title;
		// FluentCRM keeps campaign titles unique.
		if ( \FluentCrm\App\Models\Campaign::where( 'title', $name )->first() ) {
			$name .= ' (#' . $post_id . ')';
		}
		$own     = self::sender();
		$default = self::crm_sender();
		$custom  = $own['name'] !== $default['name'] || $own['email'] !== $default['email'];

		try {
			$campaign = \FluentCrm\App\Models\Campaign::create(
				array(
					'title'            => $name,
					'status'           => 'draft',
					'email_subject'    => $subject,
					'email_pre_header' => Issues::subtitle( $post ),
					'email_body'       => self::render( $post ),
					'design_template'  => 'raw_html',
					'created_by'       => get_current_user_id() ?: (int) $post->post_author,
					'settings'         => array(
						'mailer_settings'     => array(
							'from_name'      => $custom ? $own['name'] : '',
							'from_email'     => $custom ? $own['email'] : '',
							'reply_to_name'  => '',
							'reply_to_email' => '',
							'is_custom'      => $custom ? 'yes' : 'no',
						),
						'subscribers'         => array_map( static fn( $id ) => array( 'list' => (string) $id, 'tag' => 'all' ), $lists ),
						'excludedSubscribers' => array( array( 'list' => null, 'tag' => null ) ),
						'sending_filter'      => 'list_tag',
						'dynamic_segment'     => array( 'id' => '', 'slug' => '' ),
						'advanced_filters'    => array( array() ),
						'template_config'     => \FluentCrm\App\Services\Helper::getTemplateConfig( 'raw_html' ),
						// The email carries its own footer with the unsubscribe and manage links.
						'footer_settings'     => array( 'disable_footer' => 'yes' ),
						'sending_type'        => 'instant',
						'is_transactional'    => 'no',
					),
				)
			);
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'FluentCRM could not create the campaign: ' . $e->getMessage() );
		}
		$campaign_id = $campaign ? (int) $campaign->id : 0;
		if ( ! $campaign_id ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'FluentCRM did not return a campaign.' );
		}
		fluentcrm_update_campaign_meta( $campaign_id, CRM_Bridge::ISSUE_META, $post_id );
		update_post_meta( $post_id, Issues::META_CAMPAIGN, $campaign_id );
		do_action( 'fluent_crm/campaign_created', $campaign );
		return array( 'ok' => true, 'campaign_id' => $campaign_id, 'message' => 'Draft campaign created in FluentCRM. Review it there and send when ready.' );
	}

	/**
	 * Table-based email that holds up in Gmail, Apple Mail and Outlook.
	 */
	public static function render( \WP_Post $post ): string {
		$accent  = sanitize_hex_color( (string) Settings::get( 'accent_light' ) ) ?: '#C0392B';
		$brand   = esc_html( Settings::brand() );
		$title   = esc_html( wp_strip_all_tags( get_the_title( $post ) ) );
		$sub     = esc_html( Issues::subtitle( $post ) );
		$url     = esc_url( get_permalink( $post ) );
		$date    = esc_html( get_the_date( 'F j, Y', $post ) );
		$footer  = esc_html( str_replace( array( '{newsletter}', '{site}' ), array( Settings::brand(), get_bloginfo( 'name' ) ), (string) Settings::get( 'email_footer' ) ) );
		$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$content = preg_replace( '#<(script|style|iframe|form)\b.*?</\1>#is', '', (string) $content );
		$content = preg_replace( '#<img\b#i', '<img style="max-width:100%;height:auto;border-radius:8px;display:block"', $content );
		$content = preg_replace( '#<a\b(?![^>]*style=)#i', '<a style="color:' . $accent . '"', $content );
		$content = preg_replace( '#<(h2)\b[^>]*>#i', '<h2 style="font-size:22px;line-height:1.3;margin:28px 0 10px;color:#212121">', $content );
		$content = preg_replace( '#<(h3)\b[^>]*>#i', '<h3 style="font-size:18px;line-height:1.35;margin:24px 0 8px;color:#212121">', $content );
		$content = preg_replace( '#<p\b(?![^>]*style=)#i', '<p style="margin:0 0 16px"', $content );
		$content = preg_replace( '#<blockquote\b[^>]*>#i', '<blockquote style="margin:0 0 16px;padding:12px 16px;background:#F7EFE5;border-radius:8px;font-style:italic">', $content );
		$logo    = (int) Settings::get( 'logo_id' );
		$logo    = $logo ? wp_get_attachment_image_src( $logo, 'full' ) : false;
		$head    = $logo
			? '<img src="' . esc_url( $logo[0] ) . '" width="' . (int) min( 160, (int) Settings::get( 'logo_width' ) ?: 140 ) . '" alt="' . $brand . '" style="display:block;height:auto;border:0">'
			: '<span style="font-size:15px;font-weight:600;color:#212121">' . $brand . '</span>';

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F7EFE5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#2A2A2A">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7EFE5"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFE;border-radius:14px">'
			. '<tr><td style="padding:20px 32px;border-bottom:1px solid #E7E1D9"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td>' . $head . '</td><td align="right" style="font-size:12px;color:#5C5852">' . $date . '</td></tr></table></td></tr>'
			. '<tr><td style="padding:28px 32px 4px"><h1 style="margin:0;font-size:28px;line-height:1.2;color:#212121">' . $title . '</h1>'
			. ( $sub ? '<p style="margin:10px 0 0;font-size:17px;line-height:1.55;color:#5C5852;font-style:italic">' . $sub . '</p>' : '' )
			. '<p style="margin:14px 0 0;font-size:13px"><a href="' . $url . '" style="color:' . $accent . '">' . esc_html__( 'Read this issue on the web', 'fluenthive' ) . '</a></p></td></tr>'
			. '<tr><td style="padding:20px 32px 28px;font-size:16px;line-height:1.7">' . $content . '</td></tr>'
			. '</table>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px"><tr><td align="center" style="padding:20px 24px;font-size:12px;line-height:1.6;color:#5C5852">'
			. ( $footer ? '<p style="margin:0 0 8px;font-style:italic">' . $footer . '</p>' : '' )
			. '<p style="margin:0"><a href="##crm.unsubscribe_url##" style="color:#5C5852">' . esc_html__( 'Unsubscribe', 'fluenthive' ) . '</a> | <a href="##crm.manage_subscription_url##" style="color:#5C5852">' . esc_html__( 'Manage subscription', 'fluenthive' ) . '</a></p>'
			. '</td></tr></table>'
			. '</td></tr></table></body></html>';
	}
}
