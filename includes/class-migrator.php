<?php
/**
 * Move a site from MailerHive (MailerPress) to FluentHive (FluentCRM).
 *
 * Two independent steps, both with a dry run, both safe to run again:
 *
 * - data(): settings, issues and the paid-member table. Lists are matched to
 *   FluentCRM lists by name. Needs MailerHive deactivated, since both plugins would
 *   otherwise own the same issues.
 * - forms(): every Core Forms "mailerpress" action becomes a "fluentcrm" action with
 *   the same email field, conditions, list and tag (matched by name), and the form is
 *   registered with the consent policy. The reviewed consent wording is carried over
 *   from MailerHive's policy as it stands, never re-read from the live form.
 *
 * Nothing here creates, subscribes or emails a contact.
 */

namespace FluentHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-off migration over MailerHive and MailerPress tables; table names come from $wpdb->prefix and values are prepared.

final class Migrator {
	private const META_KEYS = array(
		'_mh_subtitle'             => '_fh_subtitle',
		'_mh_source'               => '_fh_source',
		'_mh_source_url'           => '_fh_source_url',
		'_mh_access'               => '_fh_access',
		'_mailerhive_paid_product' => '_fluenthive_paid_product',
		// MailerPress campaign IDs mean nothing to FluentCRM, so they are kept under a legacy key.
		'_mh_campaign_id'          => '_fh_mailerpress_campaign_id',
	);

	public static function mailerhive_active(): bool {
		return class_exists( '\MailerHive\Settings', false );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** MailerPress ID => name for lists or tags, or an empty array when MailerPress data is gone. */
	private static function mailerpress_names( string $what ): array {
		global $wpdb;
		$table = $wpdb->prefix . ( 'tags' === $what ? 'mailerpress_tags' : 'mailerpress_lists' );
		$id    = 'tags' === $what ? 'tag_id' : 'list_id';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT {$id} AS id, name FROM {$table}" ) as $row ) {
			$out[ (int) $row->id ] = (string) $row->name;
		}
		return $out;
	}

	/**
	 * MailerPress list ID => FluentCRM list ID, matched by name. A missing list is created,
	 * or in a dry run given a negative placeholder so the report still counts it.
	 */
	private static function list_map( bool $dry, array &$log ): array {
		$map = array();
		foreach ( self::mailerpress_names( 'lists' ) as $id => $name ) {
			if ( 'Default list' === $name ) {
				continue; // MailerPress's own unused default.
			}
			$target = CRM_Store::list_id_by_name( $name );
			if ( ! $target ) {
				$log[]  = sprintf( $dry ? 'List "%s" would be created in FluentCRM.' : 'List "%s" created in FluentCRM.', $name );
				$target = $dry ? -1 * (int) $id : CRM_Store::create_list( $name );
			}
			if ( $target ) {
				$map[ $id ] = $target;
			}
		}
		return $map;
	}

	private static function tag_map( bool $dry, array &$log ): array {
		$map = array();
		foreach ( self::mailerpress_names( 'tags' ) as $id => $name ) {
			$target = CRM_Store::tag_id( $name );
			if ( ! $target ) {
				$log[]  = sprintf( $dry ? 'Tag "%s" would be created in FluentCRM.' : 'Tag "%s" created in FluentCRM.', $name );
				$target = $dry ? -1 * (int) $id : CRM_Store::tag_id( $name, true );
			}
			if ( $target ) {
				$map[ $id ] = $target;
			}
		}
		return $map;
	}

	/**
	 * Settings, issues and paid members.
	 *
	 * @return array{ok:bool,log:string[]}
	 */
	public static function data( bool $dry = true, bool $force = false ): array {
		global $wpdb;
		$log = array();
		if ( ! CRM_Store::available() ) {
			return array( 'ok' => false, 'log' => array( 'FluentCRM is not active.' ) );
		}
		if ( self::mailerhive_active() ) {
			if ( ! $dry ) {
				return array( 'ok' => false, 'log' => array( 'Deactivate MailerHive first. Both plugins would own the same issues.' ) );
			}
			$log[] = 'MailerHive is still active. This dry run works, but deactivate MailerHive before using --apply.';
		}
		$lists = self::list_map( $dry, $log );

		// Settings.
		$old = get_option( 'mailerhive_settings' );
		if ( ! is_array( $old ) ) {
			$log[] = 'Settings: no MailerHive settings found.';
		} elseif ( false !== get_option( Settings::OPTION ) && ! $force ) {
			$log[] = 'Settings: FluentHive already has settings. Use --force to overwrite them.';
		} else {
			$new  = array_intersect_key( $old, Settings::defaults() );
			$remap = static fn( $ids ) => array_values( array_unique( array_filter( array_map( static fn( $id ) => max( 0, (int) ( $lists[ (int) $id ] ?? 0 ) ), (array) $ids ) ) ) );
			foreach ( array( 'mirror_list_ids', 'draft_list_ids' ) as $key ) {
				$new[ $key ] = $remap( $old[ $key ] ?? array() );
			}
			foreach ( (array) ( $new['paid_tiers'] ?? array() ) as $i => $tier ) {
				$new['paid_tiers'][ $i ]['list_id'] = max( 0, (int) ( $lists[ (int) ( $tier['list_id'] ?? 0 ) ] ?? 0 ) );
			}
			if ( 'mailerhive-theme' === ( $new['theme_storage_key'] ?? '' ) ) {
				$new['theme_storage_key'] = 'fluenthive-theme';
			}
			$log[] = sprintf( 'Settings: %d of %d values carried over.', count( $new ), count( $old ) );
			if ( ! $dry ) {
				update_option( Settings::OPTION, $new, false );
			}
		}

		// Issues.
		$issues = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mh_issue'" );
		$log[]  = sprintf( 'Issues: %d to move to the %s post type.', $issues, Issues::TYPE );
		if ( ! $dry && $issues ) {
			$wpdb->update( $wpdb->posts, array( 'post_type' => Issues::TYPE ), array( 'post_type' => 'mh_issue' ) );
			update_option( Settings::FLUSH_FLAG, 1, false );
		}
		foreach ( self::META_KEYS as $from => $to ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $from ) );
			if ( $n ) {
				$log[] = sprintf( 'Issue meta: %d × %s → %s.', $n, $from, $to );
				if ( ! $dry ) {
					$wpdb->update( $wpdb->postmeta, array( 'meta_key' => $to ), array( 'meta_key' => $from ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				}
			}
		}
		if ( ! $dry ) {
			wp_cache_flush();
		}

		// Paid members.
		$from = $wpdb->prefix . 'mailerhive_members';
		$to   = $wpdb->prefix . 'fluenthive_members';
		if ( self::table_exists( $from ) ) {
			$rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from}" );
			$exists = self::table_exists( $to );
			$have   = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$to}" ) : 0;
			if ( $have ) {
				$log[] = sprintf( 'Paid members: FluentHive already has %d row(s); the %d MailerHive row(s) were left alone.', $have, $rows );
			} else {
				$log[] = sprintf( 'Paid members: %d row(s) to copy.', $rows );
				if ( ! $dry ) {
					if ( ! $exists ) {
						$wpdb->query( "CREATE TABLE {$to} LIKE {$from}" );
					}
					$wpdb->query( "INSERT INTO {$to} SELECT * FROM {$from}" );
					$version = get_option( 'mailerhive_paid_db' );
					if ( false !== $version ) {
						update_option( 'fluenthive_paid_db', $version, true );
					}
				}
			}
		}

		$log[] = $dry ? 'Dry run: nothing was changed.' : 'Done. Refresh permalinks if issue URLs 404.';
		return array( 'ok' => true, 'log' => $log );
	}

	/**
	 * Core Forms: MailerPress actions become FluentCRM actions, registered with the consent policy.
	 *
	 * @return array{ok:bool,log:string[],converted:int,registered:int,unregistered:int}
	 */
	public static function forms( bool $dry = true ): array {
		$log    = array();
		$result = array( 'ok' => false, 'log' => &$log, 'converted' => 0, 'registered' => 0, 'unregistered' => 0 );
		if ( ! CRM_Store::available() || ! function_exists( 'cf_get_form' ) ) {
			$log[] = 'FluentCRM and Core Forms must both be active.';
			return $result;
		}
		if ( ! class_exists( '\FluentHive\Modules\Consent_Policy' ) ) {
			$log[] = 'Turn on the consent policy module first (Settings → Modules).';
			return $result;
		}
		if ( ! \FluentHive\Modules\Consent_Policy::core_forms_ready() ) {
			$log[] = 'Warning: Core Forms has its FluentCRM integration switched off. Turn it on in Core Forms → Modules, or converted forms will not add contacts.';
		}
		$lists = self::list_map( $dry, $log );
		$tags  = self::tag_map( $dry, $log );
		if ( ! $lists ) {
			$log[] = 'No MailerPress lists found to map from. Run this while the MailerPress tables still exist.';
			return $result;
		}
		$old_policy = get_option( 'mailerhive_consent_policy', get_option( 'gt_core_forms_mailerpress_policy', array() ) );
		$old_forms  = is_array( $old_policy['forms'] ?? null ) ? $old_policy['forms'] : array();

		$form_ids = get_posts( array( 'post_type' => 'core-form', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $form_ids as $form_id ) {
			$settings = get_post_meta( $form_id, '_cf_settings', true );
			if ( ! is_array( $settings ) || empty( $settings['actions'] ) || ! is_array( $settings['actions'] ) ) {
				continue;
			}
			$changed = false;
			$problem = '';
			foreach ( $settings['actions'] as $i => $action ) {
				if ( ! is_array( $action ) || 'mailerpress' !== ( $action['type'] ?? '' ) ) {
					continue;
				}
				$list = (int) ( $lists[ (int) ( $action['list_id'] ?? 0 ) ] ?? 0 );
				$tag  = (int) ( $action['tag_id'] ?? 0 );
				if ( ! $list || ( $tag && empty( $tags[ $tag ] ) ) ) {
					$problem = 'its list or tag has no FluentCRM match';
					break;
				}
				$settings['actions'][ $i ] = array(
					'type'          => 'fluentcrm',
					'_cf_action_id' => (string) ( $action['_cf_action_id'] ?? '' ),
					'email_field'   => (string) ( $action['email_field'] ?? 'email' ),
					'name_field'    => (string) ( $action['first_name_field'] ?? '' ),
					'list_ids'      => array( $list ),
					'tag_ids'       => $tag ? array( (int) $tags[ $tag ] ) : array(),
					// Double opt-in is decided by FluentHive's "Confirm signups" setting, not per action.
					'status'        => 'subscribed',
					'async'         => (string) ( $action['async'] ?? '0' ),
					'conditions'    => (array) ( $action['conditions'] ?? array() ),
				);
				$changed = true;
			}
			if ( $problem ) {
				$log[] = sprintf( 'Form %d skipped: %s.', $form_id, $problem );
				continue;
			}
			if ( ! $changed ) {
				continue;
			}
			++$result['converted'];
			if ( ! $dry ) {
				update_post_meta( $form_id, '_cf_settings', wp_slash( $settings ) );
				clean_post_cache( $form_id );
			}

			$old_rule = $old_forms[ (string) $form_id ] ?? null;
			if ( ! is_array( $old_rule ) ) {
				++$result['unregistered'];
				$log[] = sprintf( 'Form %d converted but not registered: MailerHive had no reviewed consent route for it, so it stays closed.', $form_id );
				continue;
			}
			// Build the routes from the converted actions, then keep the reviewed consent wording as it was.
			$preferences = array();
			foreach ( (array) ( $old_rule['routes'] ?? array() ) as $action_id => $route ) {
				$preferences[ (string) $action_id ] = (string) ( $route['preference'] ?? '' );
			}
			if ( $dry ) {
				++$result['registered'];
				continue;
			}
			$routes = self::routes( $settings['actions'], $preferences );
			if ( ! $routes ) {
				++$result['unregistered'];
				$log[] = sprintf( 'Form %d converted but its routes could not be described.', $form_id );
				continue;
			}
			++$result['registered'];
			if ( ! $dry ) {
				\FluentHive\Modules\Consent_Policy::register_form(
					(int) $form_id,
					array(
						'email_field'   => (string) ( $old_rule['email_field'] ?? 'email' ),
						'consent_field' => (string) ( $old_rule['consent_field'] ?? '' ),
						'consent_value' => (string) ( $old_rule['consent_value'] ?? '1' ),
						'required'      => (bool) ( $old_rule['required'] ?? false ),
						'disclosure'    => (string) ( $old_rule['disclosure'] ?? '' ),
						'routes'        => $routes,
					)
				);
			}
		}
		$result['ok'] = true;
		$log[]        = sprintf( '%d form(s) converted, %d registered with the consent policy, %d left closed.', $result['converted'], $result['registered'], $result['unregistered'] );
		$log[]        = $dry ? 'Dry run: nothing was changed.' : 'Done.';
		return $result;
	}

	/** Consent routes for a form's FluentCRM actions. */
	private static function routes( array $actions, array $preferences ): array {
		$routes = array();
		foreach ( $actions as $a ) {
			if ( ! is_array( $a ) || 'fluentcrm' !== ( $a['type'] ?? '' ) || empty( $a['_cf_action_id'] ) ) {
				continue;
			}
			$lists = array();
			foreach ( \FluentHive\Modules\Consent_Policy::ids( $a['list_ids'] ?? array() ) as $id ) {
				$lists[ (string) $id ] = CRM_Store::list_name( $id );
			}
			$tags = array();
			foreach ( \FluentHive\Modules\Consent_Policy::ids( $a['tag_ids'] ?? array() ) as $id ) {
				$tags[ (string) $id ] = CRM_Store::tag_title( $id );
			}
			if ( ! $lists || in_array( '', $lists, true ) || in_array( '', $tags, true ) ) {
				return array();
			}
			$routes[ (string) $a['_cf_action_id'] ] = array(
				'contract'   => \FluentHive\Modules\Consent_Policy::contract( $a ),
				'lists'      => $lists,
				'tags'       => $tags,
				'preference' => (string) ( $preferences[ (string) $a['_cf_action_id'] ] ?? '' ),
			);
		}
		return $routes;
	}
}
