<?php
/**
 * FluentCRM data helpers shared by every module: contacts, list membership, named
 * tags, custom fields and consent evidence. Everything goes through FluentCRM's own
 * models, so its hooks, caches and suppression rules stay in charge.
 *
 * A contact is handed back as a small plain object so callers never depend on a
 * FluentCRM model: contact_id, email, first_name, last_name, subscription_status.
 */

namespace FluentHive;

defined( 'ABSPATH' ) || exit;

final class CRM_Store {
	/** FluentCRM statuses a signup, purchase or import must never lift on its own. */
	const SUPPRESSED = array( 'unsubscribed', 'bounced', 'complained', 'spammed' );

	/** Subscriber meta object type that holds FluentHive's own records. */
	const META_TYPE = 'fluenthive';

	/** Custom contact fields FluentHive writes, with their FluentCRM field type. */
	const FIELDS = array(
		'acquisition_source'        => array( 'Acquisition Source', 'text' ),
		'consent_form'              => array( 'Consent Form', 'text' ),
		'consent_recorded_at'       => array( 'Consent Recorded At', 'date' ),
		'consent_version'           => array( 'Consent Version', 'text' ),
		'fluentcart_customer_since' => array( 'FluentCart Customer Since', 'date' ),
		'last_purchase_at'          => array( 'Last Purchase At', 'date' ),
		'last_purchased_product'    => array( 'Last Purchased Product', 'text' ),
	);

	public static function available(): bool {
		return function_exists( 'FluentCrmApi' ) && class_exists( '\FluentCrm\App\Models\Subscriber' );
	}

	/* ---------- contacts ---------- */

	private static function model( int $contact_id ) {
		return ( self::available() && $contact_id ) ? \FluentCrm\App\Models\Subscriber::find( $contact_id ) : null;
	}

	private static function shape( $subscriber ): ?object {
		if ( ! $subscriber ) {
			return null;
		}
		return (object) array(
			'contact_id'          => (int) $subscriber->id,
			'email'               => (string) $subscriber->email,
			'first_name'          => (string) $subscriber->first_name,
			'last_name'           => (string) $subscriber->last_name,
			'subscription_status' => (string) $subscriber->status,
		);
	}

	public static function contact( string $email ): ?object {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! self::available() ) {
			return null;
		}
		return self::shape( \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first() );
	}

	/**
	 * Create a contact when none exists. An existing contact is returned untouched;
	 * changing its status is a separate, explicit call.
	 *
	 * @param string $status subscribed | pending | unsubscribed
	 */
	public static function ensure_contact( string $email, string $first = '', string $last = '', string $source = 'fluenthive', string $status = 'subscribed' ): ?object {
		$contact = self::contact( $email );
		if ( $contact || ! self::available() ) {
			return $contact;
		}
		$status = in_array( $status, array( 'subscribed', 'pending', 'unsubscribed' ), true ) ? $status : 'pending';
		\FluentCrmApi( 'contacts' )->createOrUpdate(
			array(
				'email'      => sanitize_email( $email ),
				'first_name' => sanitize_text_field( $first ),
				'last_name'  => sanitize_text_field( $last ),
				'status'     => $status,
				'source'     => sanitize_key( $source ),
			)
		);
		return self::contact( $email );
	}

	/**
	 * Move a contact to a status on purpose. This is the only place FluentHive forces a
	 * status, so every caller that lifts a suppression is easy to find.
	 */
	public static function set_status( int $contact_id, string $status ): void {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber || $subscriber->status === $status ) {
			return;
		}
		\FluentCrmApi( 'contacts' )->createOrUpdate( array( 'email' => $subscriber->email, 'status' => $status ), true );
	}

	/**
	 * Mark a contact subscribed and merge this purchase into the consent record.
	 * Earlier consent keys are kept. Purchase history is capped so it cannot grow without bound.
	 */
	public static function resubscribe( int $contact_id, array $evidence ): void {
		$details = self::evidence( $contact_id );
		$history = isset( $details['fluenthive_purchases'] ) && is_array( $details['fluenthive_purchases'] ) ? $details['fluenthive_purchases'] : array();
		unset( $details['fluenthive_purchases'] );
		$history[] = $evidence;
		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}
		$details                         = array_merge( $details, $evidence );
		$details['fluenthive_purchases'] = $history;
		self::save_evidence( $contact_id, $details );
		self::set_status( $contact_id, 'subscribed' );
	}

	/** Send FluentCRM's double opt-in email to a pending contact. */
	public static function send_confirmation( int $contact_id ): bool {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber || 'pending' !== $subscriber->status ) {
			return false;
		}
		$subscriber->sendDoubleOptinEmail();
		return true;
	}

	/* ---------- consent evidence ---------- */

	public static function evidence( int $contact_id ): array {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber ) {
			return array();
		}
		$raw     = $subscriber->getMeta( 'consent', self::META_TYPE );
		$details = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $details ) ? $details : array();
	}

	public static function save_evidence( int $contact_id, array $details ): void {
		$subscriber = self::model( $contact_id );
		if ( $subscriber ) {
			$subscriber->updateMeta( 'consent', wp_json_encode( $details ), self::META_TYPE );
		}
	}

	public static function merge_evidence( int $contact_id, array $details ): void {
		self::save_evidence( $contact_id, array_merge( self::evidence( $contact_id ), $details ) );
	}

	/* ---------- lists ---------- */

	/** @return array<int,string> list ID => title */
	public static function lists(): array {
		if ( ! self::available() ) {
			return array();
		}
		$out = array();
		foreach ( \FluentCrm\App\Models\Lists::orderBy( 'title', 'ASC' )->get() as $list ) {
			$out[ (int) $list->id ] = (string) $list->title;
		}
		return $out;
	}

	public static function list_name( int $list_id ): string {
		if ( ! self::available() || ! $list_id ) {
			return '';
		}
		$list = \FluentCrm\App\Models\Lists::find( $list_id );
		return $list ? (string) $list->title : '';
	}

	public static function list_id_by_name( string $name ): int {
		if ( ! self::available() || '' === $name ) {
			return 0;
		}
		$list = \FluentCrm\App\Models\Lists::where( 'title', $name )->first();
		return $list ? (int) $list->id : 0;
	}

	public static function create_list( string $name, string $description = '' ): int {
		$name = sanitize_text_field( $name );
		if ( ! self::available() || '' === $name ) {
			return 0;
		}
		$existing = self::list_id_by_name( $name );
		if ( $existing ) {
			return $existing;
		}
		$list = \FluentCrm\App\Models\Lists::create(
			array(
				'title'       => $name,
				'slug'        => self::unique_slug( '\FluentCrm\App\Models\Lists', $name ),
				'description' => sanitize_text_field( $description ),
			)
		);
		return $list ? (int) $list->id : 0;
	}

	public static function add_to_list( int $contact_id, int $list_id ): void {
		$subscriber = self::model( $contact_id );
		if ( $subscriber && $list_id ) {
			$subscriber->attachLists( array( $list_id ) );
		}
	}

	public static function remove_from_list( int $contact_id, int $list_id ): void {
		$subscriber = self::model( $contact_id );
		if ( $subscriber && $list_id ) {
			$subscriber->detachLists( array( $list_id ) );
		}
	}

	public static function list_ids_of( int $contact_id ): array {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber ) {
			return array();
		}
		return array_map( 'intval', $subscriber->lists()->get()->pluck( 'id' )->toArray() );
	}

	/* ---------- tags ---------- */

	public static function tag_name( string $name ): string {
		$name = sanitize_text_field( $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
	}

	public static function tag_title( int $tag_id ): string {
		if ( ! self::available() || ! $tag_id ) {
			return '';
		}
		$tag = \FluentCrm\App\Models\Tag::find( $tag_id );
		return $tag ? (string) $tag->title : '';
	}

	public static function tag_id( string $name, bool $create = false ): int {
		$name = self::tag_name( $name );
		if ( ! self::available() || '' === $name ) {
			return 0;
		}
		$tag = \FluentCrm\App\Models\Tag::where( 'title', $name )->first();
		if ( ! $tag && $create ) {
			$tag = \FluentCrm\App\Models\Tag::create(
				array(
					'title' => $name,
					'slug'  => self::unique_slug( '\FluentCrm\App\Models\Tag', $name ),
				)
			);
		}
		return $tag ? (int) $tag->id : 0;
	}

	public static function add_tag( int $contact_id, string $name ): void {
		$subscriber = self::model( $contact_id );
		$tag_id     = $subscriber ? self::tag_id( $name, true ) : 0;
		if ( $tag_id ) {
			$subscriber->attachTags( array( $tag_id ) );
		}
	}

	public static function add_tag_ids( int $contact_id, array $tag_ids ): void {
		$subscriber = self::model( $contact_id );
		$tag_ids    = array_values( array_filter( array_map( 'intval', $tag_ids ) ) );
		if ( $subscriber && $tag_ids ) {
			$subscriber->attachTags( $tag_ids );
		}
	}

	public static function remove_tag( int $contact_id, string $name ): void {
		$subscriber = self::model( $contact_id );
		$tag_id     = $subscriber ? self::tag_id( $name ) : 0;
		if ( $tag_id ) {
			$subscriber->detachTags( array( $tag_id ) );
		}
	}

	/** True when the contact carries a tag whose title starts with the prefix, such as "Suppression:". */
	public static function has_tag_prefix( int $contact_id, string $prefix ): bool {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber || '' === $prefix ) {
			return false;
		}
		foreach ( $subscriber->tags()->get() as $tag ) {
			if ( 0 === stripos( (string) $tag->title, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- custom fields ---------- */

	/** Define any FluentHive custom contact field that FluentCRM does not have yet. */
	public static function ensure_fields(): void {
		if ( ! self::available() || ! class_exists( '\FluentCrm\App\Models\CustomContactField' ) ) {
			return;
		}
		$store   = new \FluentCrm\App\Models\CustomContactField();
		$fields  = (array) ( $store->getGlobalFields()['fields'] ?? array() );
		$have    = array_column( $fields, 'slug' );
		$changed = false;
		foreach ( self::FIELDS as $slug => $def ) {
			if ( ! in_array( $slug, $have, true ) ) {
				$fields[] = array( 'field_key' => $def[1], 'type' => $def[1], 'label' => $def[0], 'slug' => $slug );
				$changed  = true;
			}
		}
		if ( $changed ) {
			$store->saveGlobalFields( $fields );
		}
	}

	public static function field( int $contact_id, string $key ): string {
		$subscriber = self::model( $contact_id );
		if ( ! $subscriber ) {
			return '';
		}
		$values = (array) $subscriber->custom_fields();
		return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
	}

	public static function set_field( int $contact_id, string $key, string $value ): void {
		self::set_fields( $contact_id, array( $key => $value ) );
	}

	public static function set_fields( int $contact_id, array $values ): void {
		$subscriber = self::model( $contact_id );
		$values     = array_intersect_key( $values, self::FIELDS );
		if ( ! $subscriber || ! $values ) {
			return;
		}
		self::ensure_fields();
		$subscriber->syncCustomFieldValues( array_map( 'strval', $values ), false );
	}

	public static function set_field_if_empty( int $contact_id, string $key, string $value ): void {
		if ( '' === self::field( $contact_id, $key ) ) {
			self::set_field( $contact_id, $key, $value );
		}
	}

	/* ---------- internals ---------- */

	private static function unique_slug( string $model, string $title ): string {
		$base = substr( sanitize_title( $title ), 0, 180 ) ?: 'item';
		$slug = $base;
		$n    = 2;
		while ( $model::where( 'slug', $slug )->first() ) {
			$slug = $base . '-' . $n++;
		}
		return $slug;
	}
}
