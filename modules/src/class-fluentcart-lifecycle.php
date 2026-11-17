<?php
/**
 * FluentCart → FluentCRM lifecycle classification: purchase, refund and
 * abandoned-checkout tags plus purchase custom fields. A paid order may create a
 * contact on the customer list; it never changes an existing contact's status, so
 * a previous unsubscribe is never reversed by a purchase.
 */

namespace FluentHive\Modules;

use FluentHive\CRM_Store;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FluentCart keeps orders in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class FluentCart_Lifecycle {
	private const CONSENT_VERSION = 'newsletter-consent-v1';
	private const ABANDONED_TAG   = 'Abandoned';
	private const SWEEP_HOOK      = 'fluenthive_fc_abandoned_sweep';

	/** Name of FluentCRM's own checkout checkbox (CheckoutSubscription::OPTIN_FIELD). */
	private const CRM_CHECKBOX = '_fc_cart_checkout_subscribe';

	/**
	 * How long an unpaid checkout must sit before it counts as abandoned. Tagging on
	 * order creation would label every shopper mid-checkout and untag them seconds
	 * later, which is harmless as a record but unsafe to trigger an automation from.
	 */
	private const ABANDONED_AFTER_HOURS = 24;

	public static function boot(): void {
		add_action( 'fluent_cart/order_created', array( __CLASS__, 'record_checkout_consent' ), 30, 1 );
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'classify_paid_order' ), 30, 1 );
		add_action( 'fluent_cart/order_refunded', array( __CLASS__, 'classify_refund' ), 30, 1 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'run_abandoned_sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	public static function record_checkout_consent( $data ): void {
		list( $disclosure, $field ) = self::posted_consent();
		if ( '' === $disclosure ) {
			return;
		}

		$email = self::email_from_event( $data );
		if ( '' === $email ) {
			return;
		}
		$recorded_at = current_time( 'mysql', true );
		set_transient(
			'fh_fc_consent_' . md5( strtolower( $email ) ),
			array(
				'recorded_at' => $recorded_at,
				'disclosure'  => $disclosure,
				'field'       => $field,
			),
			7 * DAY_IN_SECONDS
		);

		$contact = CRM_Store::contact( $email );
		if ( ! $contact ) {
			return;
		}

		$contact_id = (int) $contact->contact_id;
		CRM_Store::set_fields(
			$contact_id,
			array(
				'acquisition_source'  => 'fluentcart_checkout',
				'consent_form'        => 'fluentcart_checkout',
				'consent_recorded_at' => gmdate( 'Y-m-d', strtotime( $recorded_at ) ),
				'consent_version'     => self::CONSENT_VERSION,
			)
		);
		CRM_Store::merge_evidence(
			$contact_id,
			array(
				'source'              => 'fluentcart_checkout',
				'consent_recorded_at' => $recorded_at,
				'consent_version'     => self::CONSENT_VERSION,
				'consent_field'       => $field,
				'consent_disclosure'  => $disclosure,
			)
		);
	}

	public static function classify_paid_order( $data ): void {
		$contact = self::ensure_contact_from_event( $data );
		$order   = is_array( $data ) ? ( $data['order'] ?? null ) : null;

		if ( ! $contact || ! $order ) {
			return;
		}

		$order_id = (int) ( is_object( $order ) ? ( $order->id ?? 0 ) : ( $order['id'] ?? 0 ) );
		if ( ! $order_id ) {
			return;
		}

		$contact_id = (int) $contact->contact_id;
		CRM_Store::add_tag( $contact_id, 'Source: FluentCart Purchase' );
		CRM_Store::add_tag( $contact_id, 'Customer: Paid' );
		// They converted, so the abandoned record no longer describes them.
		CRM_Store::remove_tag( $contact_id, self::ABANDONED_TAG );

		$paid_at = is_object( $order )
			? ( $order->completed_at ?? $order->created_at ?? '' )
			: ( $order['completed_at'] ?? $order['created_at'] ?? '' );

		if ( $paid_at ) {
			$day = gmdate( 'Y-m-d', strtotime( (string) $paid_at ) );
			CRM_Store::set_field_if_empty( $contact_id, 'fluentcart_customer_since', $day );
			CRM_Store::set_field( $contact_id, 'last_purchase_at', $day );
		}

		foreach ( self::order_items( $order_id ) as $item ) {
			$title = sanitize_text_field( (string) $item['post_title'] );
			if ( '' !== $title ) {
				CRM_Store::add_tag( $contact_id, 'Purchased: ' . $title );
				CRM_Store::set_field( $contact_id, 'last_purchased_product', $title );
			}

			$product_id = (int) $item['post_id'];
			if ( $product_id ) {
				foreach ( self::category_tags() as $slug => $tag ) {
					if ( has_term( $slug, 'product-categories', $product_id ) ) {
						CRM_Store::add_tag( $contact_id, $tag );
					}
				}
			}
		}
	}

	/** "category-slug | Tag name" lines from Settings → Modules. */
	private static function category_tags(): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) \FluentHive\Settings::get( 'fc_category_tags' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[ sanitize_title( $parts[0] ) ] = sanitize_text_field( $parts[1] );
			}
		}
		return $out;
	}

	public static function classify_refund( $data ): void {
		$contact = CRM_Store::contact( self::email_from_event( $data ) );
		if ( $contact ) {
			CRM_Store::add_tag( (int) $contact->contact_id, 'Customer: Refunded' );
		}
	}

	/**
	 * Resolve the contact for a paid order, creating one when it does not exist.
	 *
	 * Contacts are created from paid orders so every paying customer has a record.
	 * A buyer who did not agree at checkout is stored unsubscribed and on no list.
	 * Creation is limited to this paid path, and an existing contact is never modified
	 * here, so a previous unsubscribe can never be reversed by a purchase.
	 */
	private static function ensure_contact_from_event( $data ): ?object {
		$email = self::email_from_event( $data );
		if ( '' === $email || ! CRM_Store::available() ) {
			return null;
		}
		$contact = CRM_Store::contact( $email );
		if ( $contact ) {
			return $contact;
		}

		$customer  = is_array( $data ) ? ( $data['customer'] ?? null ) : null;
		$consented = self::checkout_consent_granted( $email );
		$contact   = CRM_Store::ensure_contact(
			$email,
			(string) ( is_object( $customer ) ? ( $customer->first_name ?? '' ) : '' ),
			(string) ( is_object( $customer ) ? ( $customer->last_name ?? '' ) : '' ),
			'fluentcart_purchase',
			$consented ? 'subscribed' : 'unsubscribed'
		);

		$list_id = ( $contact && $consented ) ? CRM_Store::list_id_by_name( (string) ( \FluentHive\Settings::get( 'fc_customer_list' ) ?: 'Customers' ) ) : 0;
		if ( $list_id ) {
			CRM_Store::add_to_list( (int) $contact->contact_id, $list_id );
		}

		return $contact;
	}

	/**
	 * Tag customers whose checkouts never completed, and only after they have had time
	 * to complete. Runs daily; safe to run by hand at any time.
	 */
	public static function run_abandoned_sweep(): void {
		global $wpdb;

		if ( ! CRM_Store::available() ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::ABANDONED_AFTER_HOURS * HOUR_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.email, c.first_name, c.last_name
				 FROM {$wpdb->prefix}fct_customers c
				 INNER JOIN {$wpdb->prefix}fct_orders o ON o.customer_id = c.id
				 WHERE c.email <> ''
				 GROUP BY c.id, c.email, c.first_name, c.last_name
				 HAVING SUM( o.payment_status IN ('paid', 'partially_refunded') ) = 0
				    AND SUM( o.status IN ('on-hold', 'pending', 'processing') ) > 0
				    AND MIN( o.created_at ) <= %s",
				$cutoff
			),
			ARRAY_A
		) ?: array();

		foreach ( $rows as $row ) {
			self::record_abandoned( (string) $row['email'], (string) $row['first_name'], (string) $row['last_name'] );
		}
	}

	/**
	 * An abandoned checkout is a business record, not a mailing permission, so a new
	 * contact is stored unsubscribed and on no list. It can never receive email until
	 * the person subscribes through a real opt-in. An existing contact only gains the
	 * tag; its status and lists are left untouched.
	 */
	private static function record_abandoned( string $email, string $first, string $last ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return;
		}
		$contact = CRM_Store::ensure_contact( $email, $first, $last, 'fluentcart_abandoned', 'unsubscribed' );
		if ( $contact ) {
			CRM_Store::add_tag( (int) $contact->contact_id, self::ABANDONED_TAG );
		}
	}

	/**
	 * The disclosure this checkout agreed to and the field that carried it, or two empty strings.
	 *
	 * A custom checkout field posts the exact text saved in settings. FluentCRM's own
	 * FluentCart checkbox posts "1", so when that checkbox is switched on and ticked, the
	 * label FluentCRM showed next to it is the disclosure.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function posted_consent(): array {
		// FluentCart validates the checkout request before this action runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$saved = (string) \FluentHive\Settings::get( 'fc_consent_disclosure' );
		if ( '' !== $saved && isset( $_POST['fluenthive_disclosure'] ) ) {
			$posted = wp_unslash( $_POST['fluenthive_disclosure'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared for exact equality with the saved disclosure, never stored or echoed.
			if ( is_string( $posted ) && $posted === $saved ) {
				return array( $saved, 'checkout_disclosure' );
			}
		}
		$ticked = isset( $_POST[ self::CRM_CHECKBOX ] ) && '1' === (string) wp_unslash( $_POST[ self::CRM_CHECKBOX ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with a literal.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$label = $ticked ? self::crm_checkbox_label() : '';
		return '' === $label ? array( '', '' ) : array( $label, 'fluentcrm_checkout_checkbox' );
	}

	/** Label of FluentCRM's FluentCart checkout checkbox, or '' when that checkbox is not shown. */
	private static function crm_checkbox_label(): string {
		if ( ! class_exists( '\FluentCrm\App\Services\AutoSubscribe' ) || ! method_exists( '\FluentCrm\App\Services\AutoSubscribe', 'getFluentCartCheckoutSettings' ) ) {
			return '';
		}
		$settings = (array) ( new \FluentCrm\App\Services\AutoSubscribe() )->getFluentCartCheckoutSettings();
		if ( 'yes' !== ( $settings['status'] ?? '' ) ) {
			return '';
		}
		// The same fallback FluentCRM renders, so the stored text is what the buyer saw.
		$label = (string) ( $settings['checkbox_label'] ?? '' );
		return trim( '' !== $label ? $label : __( 'Sign me up for the newsletter!', 'fluent-crm' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- FluentCRM's own default label, translated as FluentCRM shows it.
	}

	/** Checkout gave consent (see posted_consent()), or this email already has that consent on file. */
	private static function checkout_consent_granted( string $email ): bool {
		$stored = get_transient( 'fh_fc_consent_' . md5( strtolower( $email ) ) );
		if ( is_array( $stored ) && ! empty( $stored['disclosure'] ) ) {
			return true;
		}
		$contact = CRM_Store::contact( $email );
		return $contact && 'fluentcart_checkout' === CRM_Store::field( (int) $contact->contact_id, 'consent_form' );
	}

	private static function email_from_event( $data ): string {
		$customer = is_array( $data ) ? ( $data['customer'] ?? null ) : null;
		$email    = '';

		if ( is_object( $customer ) ) {
			$email = $customer->email ?? '';
		} elseif ( is_array( $customer ) ) {
			$email = $customer['email'] ?? '';
		}

		$email = sanitize_email( (string) $email );

		return is_email( $email ) ? $email : '';
	}

	private static function order_items( int $order_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_title FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
	}
}

FluentCart_Lifecycle::boot();
