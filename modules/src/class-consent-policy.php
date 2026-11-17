<?php
/**
 * Consent policy for the Core Forms → FluentCRM action.
 *
 * Replaces the native action handler with one that fails closed: a form only adds
 * contacts when it is registered here, its FluentCRM action matches the reviewed
 * contract, its consent checkbox carries the exact disclosure on record, the
 * submission is not spam, and existing opt-outs or suppression tags are preserved.
 * Consent evidence (form, timestamp, version, disclosure) is written to the contact.
 *
 * With "Confirm signups" on, every route runs as double opt-in: the contact is created
 * as pending and FluentCRM emails its confirmation link. A pending or previously
 * unsubscribed address that submits again gets a fresh confirmation email and stays
 * pending until it is confirmed. With it off, contacts are added as subscribed and
 * nothing is emailed.
 */

namespace FluentHive\Modules;

use FluentHive\CRM_Store;

defined( 'ABSPATH' ) || exit;

final class Consent_Policy {
	const OPTION = 'fluenthive_consent_policy';
	const HOOK   = 'cf_process_form_action_fluentcrm';

	private static $native;

	public static function boot(): void {
		add_action( 'init', array( self::class, 'attach' ), PHP_INT_MAX );
		add_action( 'wp_loaded', array( self::class, 'attach' ) );
		add_action( 'cf_output_form_action_fluentcrm_settings', array( self::class, 'explain' ), 30, 2 );
	}

	/** Replace a late-registered native handler. Our own callback is left in place. */
	public static function attach(): void {
		foreach ( ( $GLOBALS['wp_filter'][ self::HOOK ]->callbacks ?? array() ) as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && $fn[0] instanceof \Core_Forms\Actions\FluentCRM && 'process' === $fn[1] ) {
					self::$native = $fn[0];
					remove_action( self::HOOK, $fn, $priority );
					add_action( self::HOOK, array( self::class, 'process' ), $priority, 3 );
				}
			}
		}
	}

	/**
	 * Core Forms only loads its FluentCRM action while that integration is switched on
	 * in Core Forms → Modules. Without it no form can add a contact at all.
	 */
	public static function core_forms_ready(): bool {
		if ( ! class_exists( '\Core_Forms\Modules\Modules' ) ) {
			return class_exists( '\Core_Forms\Actions\FluentCRM' );
		}
		return \Core_Forms\Modules\Modules::enabled( 'fluentcrm' );
	}

	/** "Confirm signups" (double opt-in) is switched on in Settings → Modules. */
	public static function confirmation_on(): bool {
		return \FluentHive\Settings::on( 'consent_double_optin' );
	}

	public static function version(): string {
		$v = (string) \FluentHive\Settings::get( 'consent_version' );
		return '' !== $v ? $v : 'v1';
	}

	/** IDs from a Core Forms list or tag setting, which is an array or a comma-separated string. */
	public static function ids( $value ): array {
		$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids );
		return $ids;
	}

	/** The parts of an action that decide who is added where. Any change needs a new review. */
	public static function contract( array $settings ): array {
		$out = array();
		foreach ( array( 'type', '_cf_action_id', 'email_field', 'name_field', 'status' ) as $key ) {
			$value       = $settings[ $key ] ?? '';
			$out[ $key ] = is_scalar( $value ) ? trim( (string) $value, '[] ' ) : '!invalid';
		}
		$out['list_ids']   = self::ids( $settings['list_ids'] ?? array() );
		$out['tag_ids']    = self::ids( $settings['tag_ids'] ?? array() );
		$out['conditions'] = $settings['conditions'] ?? array();
		return $out;
	}

	private static function skip( string $message ) {
		return \Core_Forms\Integrations\IntegrationResult::state( 'skipped', $message );
	}

	private static function fail( string $message ) {
		return \Core_Forms\Integrations\IntegrationResult::failure( $message );
	}

	/** Published FluentCRM automations. Any of them could email a contact the moment it is added. */
	private static function live_automations(): ?int {
		global $wpdb;
		$wpdb->last_error = '';
		$count            = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fc_funnels WHERE status = 'published'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FluentCRM table, name from $wpdb->prefix.
		return ( $wpdb->last_error || null === $count ) ? null : (int) $count;
	}

	public static function process( array $settings, $submission, $form ) {
		return \Core_Forms\Workflows\ActionRunner::native(
			$settings,
			$submission,
			$form,
			static function () use ( $settings, $submission, $form ) {
				$policy = get_option( self::OPTION, array() );
				$rule   = $policy['forms'][ (string) $form->ID ] ?? null;
				if ( ! $rule || ! self::$native || ! CRM_Store::available() ) {
					return self::fail( 'FluentCRM capture requires a reviewed site consent route.' );
				}
				if ( \Core_Forms\Workflows\ActionRunner::is_testing() ) {
					return self::skip( 'Live FluentCRM action tests are disabled by the site no-send policy.' );
				}
				if ( ! empty( $submission->is_spam ) ) {
					return self::skip( 'Spam submissions are not added to FluentCRM.' );
				}
				if ( empty( $submission->submitted_at ) || strcmp( $submission->submitted_at, (string) ( $policy['installed_at_utc'] ?? '' ) ) < 0 ) {
					return self::skip( 'Historical submissions are not enrolled by this integration.' );
				}
				$identity = (string) ( $settings['_cf_action_id'] ?? '' );
				$route    = $rule['routes'][ $identity ] ?? null;
				$current  = null;
				foreach ( (array) ( $form->settings['actions'] ?? array() ) as $action ) {
					if ( is_array( $action ) && ( $action['_cf_action_id'] ?? '' ) === $identity ) {
						$current = $action;
					}
				}
				if ( ! $route || ! $current || self::contract( $settings ) !== $route['contract'] || self::contract( $current ) !== $route['contract'] ) {
					return self::fail( 'The FluentCRM route changed and needs consent/mapping review.' );
				}
				$definition = $form->get_schema() ?: \Core_Forms\Schema\MarkupParser::parse( (string) $form->markup );
				$gate       = null;
				foreach ( $definition->get_input_fields() as $field ) {
					if ( $field->name === $rule['consent_field'] ) {
						$gate = $field;
					}
				}
				if ( ! $gate || ! in_array( $gate->type, array( 'checkbox', 'consent' ), true ) || $gate->checked || $gate->options ||
					$gate->label !== $rule['disclosure'] || (bool) $gate->required !== $rule['required'] ||
					( '' !== $gate->default_value ? $gate->default_value : '1' ) !== $rule['consent_value'] ) {
					return self::fail( 'The FluentCRM consent control is missing or changed.' );
				}
				$value = $submission->data[ $rule['consent_field'] ] ?? null;
				if ( ! is_string( $value ) || $value !== $rule['consent_value'] ) {
					return self::skip( 'Newsletter consent was not given; no FluentCRM contact was created.' );
				}
				if ( ! cf_evaluate_action_conditions( $settings['conditions'] ?? array(), $submission->data ) ) {
					return self::skip( 'The selected mailing preference does not match this route.' );
				}
				if ( ! empty( $route['preference'] ) ) {
					$choices = $submission->data['subscriptions'] ?? array();
					if ( ! is_array( $choices ) || ! in_array( $route['preference'], $choices, true ) ) {
						return self::skip( 'This mailing preference was not selected.' );
					}
				}
				$email = $submission->data[ $rule['email_field'] ] ?? null;
				if ( ! is_string( $email ) || ! is_email( $email ) ) {
					return self::fail( 'The configured FluentCRM email field is missing or invalid.' );
				}
				$confirm = self::confirmation_on();
				if ( ! $confirm && 'pending' === ( $settings['status'] ?? '' ) ) {
					return self::fail( 'Confirmation sending is disabled for these forms.' );
				}
				if ( ! $confirm && ! empty( $policy['no_send'] ) ) {
					$live = self::live_automations();
					if ( null === $live || $live > 0 ) {
						return self::fail( 'FluentCRM automation state requires review before capture.' );
					}
				}
				foreach ( (array) ( $route['lists'] ?? array() ) as $id => $name ) {
					if ( CRM_Store::list_name( (int) $id ) !== $name ) {
						return self::fail( 'A FluentCRM destination list is missing or changed.' );
					}
				}
				foreach ( (array) ( $route['tags'] ?? array() ) as $id => $name ) {
					if ( CRM_Store::tag_title( (int) $id ) !== $name ) {
						return self::fail( 'A FluentCRM source or preference tag is missing or changed.' );
					}
				}
				if ( empty( $route['lists'] ) ) {
					return self::fail( 'This FluentCRM route has no destination list.' );
				}

				$contact = CRM_Store::contact( $email );
				$status  = $contact ? (string) $contact->subscription_status : '';
				if ( $contact ) {
					// With confirmation on, a pending or unsubscribed address that gives consent
					// again only receives a confirmation email; it stays pending until confirmed.
					// Bounced, complained and spam-flagged addresses are never touched.
					$allowed = $confirm ? array( 'subscribed', 'pending', 'unsubscribed' ) : array( 'subscribed' );
					if ( ! in_array( $status, $allowed, true ) ) {
						return self::skip( 'The existing FluentCRM opt-out or suppression status was preserved.' );
					}
					if ( CRM_Store::has_tag_prefix( (int) $contact->contact_id, 'Suppression:' ) ) {
						return self::skip( 'The existing FluentCRM suppression tag was preserved.' );
					}
				}

				$is_new = ! $contact;
				if ( $is_new ) {
					$name    = (string) ( $submission->data[ (string) ( $settings['name_field'] ?? '' ) ] ?? '' );
					$parts   = explode( ' ', trim( sanitize_text_field( $name ) ), 2 );
					$contact = CRM_Store::ensure_contact( $email, $parts[0] ?? '', $parts[1] ?? '', 'core_forms', $confirm ? 'pending' : 'subscribed' );
					if ( ! $contact ) {
						return self::fail( 'FluentCRM did not accept this signup.' );
					}
				} elseif ( $confirm && 'unsubscribed' === $status ) {
					// A returning unsubscribed address moves to pending (never straight to subscribed)
					// so the confirmation link it is emailed can complete the signup.
					CRM_Store::set_status( (int) $contact->contact_id, 'pending' );
				}
				$id = (int) $contact->contact_id;

				foreach ( array_keys( (array) $route['lists'] ) as $list_id ) {
					CRM_Store::add_to_list( $id, (int) $list_id );
				}
				CRM_Store::add_tag_ids( $id, array_keys( (array) ( $route['tags'] ?? array() ) ) );

				$fields = array(
					'consent_form'        => (string) $form->slug,
					'consent_recorded_at' => substr( (string) $submission->submitted_at, 0, 10 ),
					'consent_version'     => self::version(),
				);
				if ( $is_new ) {
					$fields['acquisition_source'] = 'core_forms';
				}
				CRM_Store::set_fields( $id, $fields );
				CRM_Store::merge_evidence(
					$id,
					array(
						'source'              => 'core_forms',
						'form'                => (string) $form->slug,
						'consent_field'       => $rule['consent_field'],
						'consent_value'       => $rule['consent_value'],
						'consent_recorded_at' => (string) $submission->submitted_at,
						'consent_version'     => self::version(),
						'disclosure'          => $rule['disclosure'],
					)
				);

				$already = ! $is_new && 'subscribed' === $status;
				$sent    = $confirm && ! $already && CRM_Store::send_confirmation( $id );

				$evidence = (int) $submission->id > 0 ? array(
					'consent' => array(
						'form'            => (string) $form->slug,
						'field'           => $rule['consent_field'],
						'value'           => $rule['consent_value'],
						'recorded_at_utc' => (string) $submission->submitted_at,
						'version'         => self::version(),
						'disclosure'      => $rule['disclosure'],
						'lists'           => array_values( (array) $route['lists'] ),
					),
				) : array();
				return \Core_Forms\Integrations\IntegrationResult::success(
					$sent
						? 'FluentCRM accepted the signup and sent a confirmation email. The contact stays pending until the link is clicked.'
						: 'FluentCRM accepted the selected mailing preference. No confirmation email was requested.',
					$evidence
				);
			}
		);
	}

	public static function explain( $settings, $index ): void {
		$policy = get_option( self::OPTION, array() );
		$rule   = $policy['forms'][ (string) absint( $_GET['form_id'] ?? 0 ) ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice.
		echo '<p class="description">' . esc_html(
			$rule
				? ( self::confirmation_on()
					? 'Site policy: explicit newsletter consent is required, then FluentCRM double opt-in applies to every signup. Bounces, complaints and suppression tags are preserved. Live-test sending is disabled. Changes to this action require site route review.'
					: 'Site policy: explicit newsletter consent is required. Existing opt-outs are preserved. Confirmation and live-test sending are disabled. Changes to this action require site route review.' )
				: 'This FluentCRM action needs a reviewed site consent route before it can capture contacts.'
		) . '</p>';
	}

	/**
	 * Build the policy entry for one form from its FluentCRM actions and consent checkbox.
	 * Returns the entry, or a string saying why the form cannot be registered.
	 *
	 * @param array<string,string> $preferences Action ID => the "subscriptions" choice that route needs.
	 * @return array|string
	 */
	public static function describe_form( $form, array $preferences = array() ) {
		$actions = array();
		foreach ( (array) ( $form->settings['actions'] ?? array() ) as $a ) {
			if ( is_array( $a ) && 'fluentcrm' === ( $a['type'] ?? '' ) && ! empty( $a['_cf_action_id'] ) ) {
				$actions[] = $a;
			}
		}
		if ( ! $actions ) {
			return __( 'The form has no FluentCRM action.', 'fluenthive' );
		}
		$def  = $form->get_schema() ?: \Core_Forms\Schema\MarkupParser::parse( (string) $form->markup );
		$gate = null;
		foreach ( $actions as $a ) {
			foreach ( (array) ( $a['conditions'] ?? array() ) as $c ) {
				foreach ( $def->get_input_fields() as $f ) {
					if ( ! $gate && in_array( $f->type, array( 'checkbox', 'consent' ), true ) && $f->name === ( $c['field'] ?? '' ) && ! $f->options ) {
						$gate = $f;
					}
				}
			}
		}
		if ( ! $gate ) {
			foreach ( $def->get_input_fields() as $f ) {
				if ( in_array( $f->type, array( 'checkbox', 'consent' ), true ) && $f->required ) {
					$gate = $f;
					break;
				}
			}
		}
		if ( ! $gate ) {
			return __( 'The form needs a consent checkbox that its FluentCRM action depends on.', 'fluenthive' );
		}
		$routes = array();
		foreach ( $actions as $a ) {
			$lists = array();
			foreach ( self::ids( $a['list_ids'] ?? array() ) as $lid ) {
				$lists[ (string) $lid ] = CRM_Store::list_name( $lid );
			}
			$tags = array();
			foreach ( self::ids( $a['tag_ids'] ?? array() ) as $tid ) {
				$tags[ (string) $tid ] = CRM_Store::tag_title( $tid );
			}
			if ( ! $lists || in_array( '', $lists, true ) || in_array( '', $tags, true ) ) {
				return __( 'A FluentCRM action points at a list or tag that does not exist.', 'fluenthive' );
			}
			$routes[ (string) $a['_cf_action_id'] ] = array(
				'contract'   => self::contract( $a ),
				'lists'      => $lists,
				'tags'       => $tags,
				'preference' => (string) ( $preferences[ (string) $a['_cf_action_id'] ] ?? '' ),
			);
		}
		return array(
			'email_field'   => (string) ( $actions[0]['email_field'] ?? 'email' ),
			'consent_field' => $gate->name,
			'consent_value' => '' !== (string) $gate->default_value ? (string) $gate->default_value : '1',
			'required'      => (bool) $gate->required,
			'disclosure'    => (string) $gate->label,
			'routes'        => $routes,
		);
	}

	/** Save one form's entry, creating the policy on first use. */
	public static function register_form( int $form_id, array $entry ): void {
		$policy  = get_option( self::OPTION, array() );
		$policy  = is_array( $policy ) ? $policy : array();
		$policy += array( 'version' => 1, 'installed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'no_send' => true, 'forms' => array() );
		$policy['forms'][ (string) $form_id ] = $entry;
		update_option( self::OPTION, $policy, false );
	}
}
add_action( 'init', array( Consent_Policy::class, 'boot' ), 20 );
