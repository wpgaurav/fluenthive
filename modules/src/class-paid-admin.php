<?php
/**
 * Paid subscriptions in wp-admin: the Paid settings tab (tiers and plans), the
 * "Who can read this issue" control, the Access column, and the Paid members screen
 * with manual grants.
 */

namespace FluentHive\Modules\Paid;

use FluentHive\Issues;
use FluentHive\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FluentHive's own members table; name from $wpdb->prefix, values prepared.

final class Admin {
	const PAGE = 'fluenthive-members';

	public static function boot(): void {
		add_action( 'fluenthive_issue_meta_box', array( __CLASS__, 'issue_field' ) );
		add_action( 'fluenthive_issue_meta_save', array( __CLASS__, 'save_issue_field' ) );
		add_filter( 'manage_' . Issues::TYPE . '_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_' . Issues::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_fluenthive_members', array( __CLASS__, 'handle' ) );
	}

	/* ---------- issue editor ---------- */

	public static function issue_field( \WP_Post $post ): void {
		$tiers = Plans::tiers();
		if ( ! $tiers ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Add a paid tier in Newsletter → Settings → Paid to make issues paid-only.', 'fluenthive' ) );
			return;
		}
		$current = (string) get_post_meta( $post->ID, Access::META, true );
		echo '<p><label for="fh-access"><strong>' . esc_html__( 'Who can read this issue', 'fluenthive' ) . '</strong></label><select id="fh-access" name="fh_access" style="width:100%">';
		/* translators: %s: free tier name */
		printf( '<option value="">%s</option>', esc_html( sprintf( __( 'Everyone (%s)', 'fluenthive' ), (string) Settings::get( 'free_tier_name' ) ?: __( 'Free', 'fluenthive' ) ) ) );
		foreach ( $tiers as $i => $tier ) {
			$label = $i < count( $tiers ) - 1
				/* translators: %s: tier name */
				? sprintf( __( '%s and above', 'fluenthive' ), $tier['name'] )
				: $tier['name'];
			printf( '<option value="%s"%s>%s</option>', esc_attr( $tier['key'] ), selected( $current, $tier['key'], false ), esc_html( $label ) );
		}
		echo '</select></p><p class="description">' . esc_html__( 'Paid issues show a preview up to the More block (or the first few blocks) and then the plans. Drafts go to the matching paid lists.', 'fluenthive' ) . '</p>';
	}

	public static function save_issue_field( int $post_id ): void {
		// Nonce checked by Issues::save_meta_box() before this action fires.
		$key = isset( $_POST['fh_access'] ) ? sanitize_key( wp_unslash( $_POST['fh_access'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( null === $key ) {
			return;
		}
		if ( '' !== $key && Plans::rank( $key ) ) {
			update_post_meta( $post_id, Access::META, $key );
		} else {
			delete_post_meta( $post_id, Access::META );
		}
	}

	public static function columns( array $cols ): array {
		$cols['fh_access'] = __( 'Access', 'fluenthive' );
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'fh_access' === $col ) {
			$need = Access::required( $post_id );
			echo esc_html( $need ? Plans::name( $need ) : __( 'Free', 'fluenthive' ) );
		}
	}

	/* ---------- settings tab ---------- */

	public static function tab( array $s ): void {
		$name     = static fn( string $k ): string => Settings::OPTION . '[' . $k . ']';
		$tiers    = Plans::tiers();
		$lists    = Settings::crm_lists();
		$vars     = Plans::variations();
		$counts   = Members::counts();
		$fc_ready = Plans::fluentcart_ready();

		echo '<tr><td colspan="2" class="fh-paid-cell"><div class="fh-paid">';
		self::styles();
		echo '<p class="fh-paid__intro">' . esc_html__( 'Readers start on Free and move up. Each paid tier unlocks its own issues and every issue of the tiers below it, and has its own FluentCRM list that FluentHive keeps in sync with FluentCart.', 'fluenthive' ) . '</p>';

		echo '<ol class="fh-ladder" aria-label="' . esc_attr__( 'Tier order', 'fluenthive' ) . '"><li class="fh-ladder__step fh-ladder__step--free"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>' . esc_html( (string) $s['free_tier_name'] ?: __( 'Free', 'fluenthive' ) ) . '</li>';
		foreach ( $tiers as $tier ) {
			$n = (int) ( $counts[ $tier['key'] ] ?? 0 );
			/* translators: %d: active readers */
			echo '<li class="fh-ladder__arrow" aria-hidden="true">&rarr;</li><li class="fh-ladder__step"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html( $tier['name'] ) . ' <span class="fh-ladder__count">' . esc_html( sprintf( _n( '%d reader', '%d readers', $n, 'fluenthive' ), $n ) ) . '</span></li>';
		}
		echo '</ol>';

		if ( ! $fc_ready ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'FluentCart is not active. Tiers can be set up, but plans need FluentCart products.', 'fluenthive' ) . '</p></div>';
		}
		self::free_box( $s );
		echo '<div id="fh-tiers">';
		foreach ( $tiers as $t => $tier ) {
			self::tier_box( (string) $t, $tier, $lists, $vars, $counts[ $tier['key'] ] ?? 0 );
		}
		echo '</div><p><button type="button" class="button fh-add-tier-btn" id="fh-add-tier"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Add a higher tier', 'fluenthive' ) . '</button></p>';
		echo '<template id="fh-tier-tpl">';
		self::tier_box( '__T__', array( 'key' => '', 'name' => '', 'description' => '', 'list_id' => -1, 'product_id' => 0, 'plans' => array() ), $lists, $vars, 0 );
		echo '</template><template id="fh-plan-tpl">';
		self::plan_row( '__T__', '__P__', array(), $vars );
		echo '</template>';
		echo '</div></td></tr>';

		self::heading_row( __( 'Reading and delivery', 'fluenthive' ) );
		self::number_row( $s, 'paid_preview_blocks', __( 'Preview length', 'fluenthive' ), __( 'Blocks shown before the paywall when the issue has no More block.', 'fluenthive' ) );
		self::number_row( $s, 'paid_grace_days', __( 'Grace period (days)', 'fluenthive' ), __( 'How long a subscription keeps access after a missed renewal date while FluentCart retries the payment.', 'fluenthive' ) );
		self::number_row( $s, 'paid_cookie_days', __( 'Remember readers for (days)', 'fluenthive' ), __( 'How long a reading link keeps a browser signed in. Access still ends when the subscription does.', 'fluenthive' ) );
		self::check_row( $s, 'paid_resubscribe', __( 'Purchases', 'fluenthive' ), __( 'Re-subscribe a contact who had unsubscribed when they buy a paid plan', 'fluenthive' ), __( 'Buying the paid newsletter is a direct request to receive it. Pending, bounced, complained, and suppressed contacts are never changed. The purchase is added to the contact’s existing consent record.', 'fluenthive' ) );
		self::check_row( $s, 'paid_add_free_list', __( 'Free issues', 'fluenthive' ), __( 'Also add paying readers to the free newsletter lists', 'fluenthive' ), __( 'So they keep getting free issues. They stay on those lists after a paid plan ends.', 'fluenthive' ) );

		self::heading_row( __( 'Plans on the site', 'fluenthive' ) );
		self::check_row( $s, 'paid_show_on_landing', __( 'Newsletter page', 'fluenthive' ), __( 'Show the plans on the newsletter page, under the signup card', 'fluenthive' ) );
		self::check_row( $s, 'paid_products_in_shop', __( 'Shop', 'fluenthive' ), __( 'Show the newsletter products in the FluentCart shop', 'fluenthive' ), __( 'Off by default: the products FluentHive creates stay out of the shop, shop search, related products, product archives, site search and sitemaps, and their product pages are marked noindex. Checkout links and the paywall plans keep working either way.', 'fluenthive' ) );
		printf( '<tr><th scope="row"><label for="fh-paid_plans_heading">%s</label></th><td><input type="text" class="regular-text" id="fh-paid_plans_heading" name="%s" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Plans heading', 'fluenthive' ), esc_attr( $name( 'paid_plans_heading' ) ), esc_attr( (string) $s['paid_plans_heading'] ), esc_attr__( 'Become a paid subscriber', 'fluenthive' ) );
		printf( '<tr><th scope="row"><label for="fh-paid_plans_intro">%s</label></th><td><textarea class="large-text" rows="2" id="fh-paid_plans_intro" name="%s">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Plans intro', 'fluenthive' ), esc_attr( $name( 'paid_plans_intro' ) ), esc_textarea( (string) $s['paid_plans_intro'] ), esc_html__( 'One or two sentences on what paying readers get. Shown on the paywall and with the plans.', 'fluenthive' ) );
		printf( '<tr><th scope="row">%s</th><td><code>[fluenthive_plans]</code> <code>[fluenthive_plans tier="paid"]</code> <code>[fluenthive_account]</code><p class="description">%s</p></td></tr>', esc_html__( 'Shortcodes', 'fluenthive' ), esc_html__( 'Plans anywhere on the site, and an account box that shows a reader their access or emails them a reading link.', 'fluenthive' ) );
		self::script();
	}

	private static function tier_box( string $t, array $tier, array $lists, array $vars, int $count ): void {
		$n   = static fn( string $k ): string => Settings::OPTION . '[paid_tiers][' . $t . '][' . $k . ']';
		$id  = 'fh-t-' . $t;
		$new = '' === (string) $tier['key'];
		echo '<section class="fh-card fh-tier-box" aria-label="' . esc_attr( $tier['name'] ?: __( 'New tier', 'fluenthive' ) ) . '">';
		printf( '<input type="hidden" name="%s" value="%d">', esc_attr( $n( 'product_id' ) ), (int) ( $tier['product_id'] ?? 0 ) );
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $n( 'key' ) ), esc_attr( (string) $tier['key'] ) );

		echo '<header class="fh-card__head"><span class="fh-card__badge" aria-hidden="true"><span class="dashicons dashicons-lock"></span></span><div class="fh-card__title">';
		printf( '<label class="screen-reader-text" for="%1$s-name">%2$s</label><input type="text" id="%1$s-name" class="fh-card__name" name="%3$s" value="%4$s" placeholder="%5$s" required>', esc_attr( $id ), esc_html__( 'Tier name', 'fluenthive' ), esc_attr( $n( 'name' ) ), esc_attr( (string) $tier['name'] ), esc_attr__( 'Tier name, e.g. Insider', 'fluenthive' ) );
		echo '<div class="fh-card__meta">';
		if ( ! $new ) {
			/* translators: %s: tier key */
			echo '<span class="fh-chip" title="' . esc_attr__( 'Issues store this key, so it never changes.', 'fluenthive' ) . '">' . esc_html( sprintf( __( 'key: %s', 'fluenthive' ), $tier['key'] ) ) . '</span>';
			/* translators: %d: active readers */
			echo '<span class="fh-chip' . ( $count ? ' fh-chip--ok' : '' ) . '">' . esc_html( sprintf( _n( '%d active reader', '%d active readers', $count, 'fluenthive' ), $count ) ) . '</span>';
		} else {
			echo '<span class="fh-chip">' . esc_html__( 'New tier', 'fluenthive' ) . '</span>';
		}
		echo '</div></div>';
		echo '<button type="button" class="fh-icon-btn fh-remove-tier" aria-label="' . esc_attr__( 'Remove tier', 'fluenthive' ) . '" title="' . esc_attr__( 'Remove tier', 'fluenthive' ) . '"><span class="dashicons dashicons-trash"></span></button></header>';

		echo '<div class="fh-card__body"><div class="fh-grid">';
		printf( '<div class="fh-field"><label for="%1$s-desc">%2$s</label><textarea id="%1$s-desc" rows="3" name="%3$s" placeholder="%4$s">%5$s</textarea><p class="fh-help">%6$s</p></div>', esc_attr( $id ), esc_html__( 'What readers get', 'fluenthive' ), esc_attr( $n( 'description' ) ), esc_attr__( 'Every paid issue, the full archive, and…', 'fluenthive' ), esc_textarea( (string) $tier['description'] ), esc_html__( 'Shown on the tier’s card in the plans and on the FluentCart product.', 'fluenthive' ) );
		$select = sprintf( '<select id="%s-list" name="%s"><option value="0">%s</option><option value="-1"%s>%s</option>', esc_attr( $id ), esc_attr( $n( 'list_id' ) ), esc_html__( '— No list —', 'fluenthive' ), selected( (int) $tier['list_id'], -1, false ), esc_html__( '+ Create a list for this tier', 'fluenthive' ) );
		foreach ( $lists as $lid => $label ) {
			$select .= sprintf( '<option value="%d"%s>%s</option>', (int) $lid, selected( (int) $tier['list_id'], (int) $lid, false ), esc_html( $label ) );
		}
		printf( '<div class="fh-field"><label for="%s-list">%s</label>%s</select><p class="fh-help">%s</p></div>', esc_attr( $id ), esc_html__( 'FluentCRM list', 'fluenthive' ), $select, esc_html__( 'Paying readers are added here while their access lasts and removed when it ends. Drafts for this tier’s issues go to this list and the lists of higher tiers.', 'fluenthive' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';

		self::product_section( $t, $tier );

		echo '<div><h4 class="fh-section-title">' . esc_html__( 'Plans', 'fluenthive' ) . '</h4><div class="fh-plans-list"><div class="fh-plan-row fh-plan-row--head" aria-hidden="true"><span>' . esc_html__( 'FluentCart price', 'fluenthive' ) . '</span><span>' . esc_html__( 'Access', 'fluenthive' ) . '</span><span>' . esc_html__( 'Button label', 'fluenthive' ) . '</span><span></span></div>';
		echo '<div class="fh-plan-rows" data-empty="' . esc_attr__( 'No plans yet. Create a FluentCart product above, or add a price from an existing product.', 'fluenthive' ) . '">';
		foreach ( (array) $tier['plans'] as $p => $plan ) {
			self::plan_row( $t, (string) $p, (array) $plan, $vars );
		}
		echo '</div></div><p class="fh-plans-add"><button type="button" class="button-link fh-add-plan" data-tier="' . esc_attr( $t ) . '"><span class="dashicons dashicons-plus" aria-hidden="true"></span>' . esc_html__( 'Add a price from an existing product', 'fluenthive' ) . '</button></p></div>';
		echo '</div></section>';
	}

	private static function free_box( array $s ): void {
		$n = static fn( string $k ): string => Settings::OPTION . '[' . $k . ']';
		echo '<section class="fh-card fh-card--free" aria-label="' . esc_attr__( 'Free tier', 'fluenthive' ) . '">';
		echo '<header class="fh-card__head"><span class="fh-card__badge" aria-hidden="true"><span class="dashicons dashicons-email-alt"></span></span><div class="fh-card__title">';
		printf( '<label class="screen-reader-text" for="fh-free-name">%s</label><input type="text" id="fh-free-name" class="fh-card__name" name="%s" value="%s">', esc_html__( 'Free tier name', 'fluenthive' ), esc_attr( $n( 'free_tier_name' ) ), esc_attr( (string) $s['free_tier_name'] ) );
		echo '<div class="fh-card__meta"><span class="fh-chip fh-chip--ok">' . esc_html__( 'Always first, no charge', 'fluenthive' ) . '</span></div></div></header>';
		echo '<div class="fh-card__body"><div class="fh-grid">';
		printf( '<div class="fh-field"><label for="fh-free-desc">%s</label><input type="text" id="fh-free-desc" name="%s" value="%s" placeholder="%s"><p class="fh-help">%s</p></div>', esc_html__( 'What free readers get', 'fluenthive' ), esc_attr( $n( 'free_tier_description' ) ), esc_attr( (string) $s['free_tier_description'] ), esc_attr( Settings::tagline() ), esc_html__( 'Defaults to the tagline.', 'fluenthive' ) );
		/* translators: %s: link to the FluentCRM tab */
		printf( '<div class="fh-field"><span class="fh-field__label">%s</span><p class="fh-help fh-help--flush">%s</p></div>', esc_html__( 'Delivery', 'fluenthive' ), wp_kses_post( sprintf( __( 'Free issues go to the lists on the <a href="%s">FluentCRM tab</a>, and readers join through the signup form on the General tab.', 'fluenthive' ), esc_url( add_query_arg( 'tab', 'fluentcrm', Settings::url() ) ) ) ) );
		echo '</div>';
		printf( '<label class="fh-toggle"><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr( $n( 'paid_show_free' ) ), checked( ! empty( $s['paid_show_free'] ), true, false ), esc_html__( 'Show a Free card next to the paid plans', 'fluenthive' ) );
		echo '</div></section>';
	}

	private static function product_section( string $t, array $tier ): void {
		$product = (int) ( $tier['product_id'] ?? 0 );
		$post    = $product ? get_post( $product ) : null;
		if ( ! Products::ready() ) {
			echo '<div class="fh-product"><p class="fh-help fh-help--flush">' . esc_html__( 'Activate FluentCart to create a product for this tier in one click.', 'fluenthive' ) . '</p></div>';
			return;
		}
		$form = static function ( bool $primary ) use ( $t, $product ) {
			$sign     = Plans::currency_sign();
			$defaults = $product ? array( 'monthly' => '', 'yearly' => '', 'lifetime' => '' ) : Products::defaults();
			$suffix   = array( 'monthly' => __( '/ month', 'fluenthive' ), 'yearly' => __( '/ year', 'fluenthive' ), 'lifetime' => __( 'once', 'fluenthive' ) );
			echo '<div class="fh-prices">';
			foreach ( Products::labels() as $key => $label ) {
				$fid = 'fh-price-' . $t . '-' . $key;
				printf(
					'<div class="fh-field fh-field--price"><label for="%1$s">%2$s</label><div class="fh-money"><span>%3$s</span><input type="text" inputmode="decimal" id="%1$s" name="fh_new_price[%4$s][%5$s]" value="%6$s" placeholder="—"><span>%7$s</span></div></div>',
					esc_attr( $fid ),
					esc_html( $label ),
					esc_html( $sign ),
					esc_attr( $t ),
					esc_attr( $key ),
					esc_attr( $defaults[ $key ] ),
					esc_html( $suffix[ $key ] )
				);
			}
			printf(
				'<button type="submit" class="button %s fh-create" name="%s" value="%s"><span class="dashicons dashicons-cart" aria-hidden="true"></span>%s</button>',
				$primary ? 'button-primary' : 'button-secondary',
				esc_attr( Settings::OPTION . '[_create_product]' ),
				esc_attr( $t ),
				esc_html( $primary ? __( 'Create FluentCart product', 'fluenthive' ) : __( 'Create product', 'fluenthive' ) )
			);
			echo '</div><p class="fh-help">' . esc_html__( 'Leave a price empty to skip it. Saves your settings too.', 'fluenthive' ) . '</p>';
		};

		if ( $post ) {
			$status = 'publish' === $post->post_status ? __( 'Published', 'fluenthive' ) : ucfirst( (string) $post->post_status );
			echo '<div class="fh-product fh-product--linked"><span class="fh-product__icon dashicons dashicons-cart" aria-hidden="true"></span><div class="fh-product__main"><span class="fh-product__label">' . esc_html__( 'FluentCart product', 'fluenthive' ) . '</span><strong>' . esc_html( get_the_title( $post ) ) . '</strong></div>';
			echo '<span class="fh-chip' . ( 'publish' === $post->post_status ? ' fh-chip--ok' : '' ) . '">' . esc_html( $status ) . '</span>';
			echo '<a class="button" href="' . esc_url( Products::edit_url( $product ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit in FluentCart', 'fluenthive' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>';
			echo '<details class="fh-product-more"><summary>' . esc_html__( 'Create another product with different prices', 'fluenthive' ) . '</summary>';
			$form( false );
			echo '</details>';
			return;
		}
		echo '<div class="fh-product"><h4 class="fh-product__title"><span class="dashicons dashicons-cart" aria-hidden="true"></span>' . esc_html__( 'Sell this tier', 'fluenthive' ) . '</h4><p class="fh-help fh-help--flush">' . esc_html__( 'One click creates a published FluentCart product with these prices and adds them to the plans below.', 'fluenthive' ) . '</p>';
		$form( true );
		echo '</div>';
	}

	/**
	 * Handle the "Create FluentCart product" button during the settings save.
	 *
	 * @param string $posted_index Form index of the tier whose button was pressed.
	 * @param array  $posted       Raw posted tiers (form order).
	 * @param array  $tiers        Sanitized tiers (same order, nameless tiers dropped).
	 */
	private static function create_product_for( string $posted_index, array $posted, array $tiers ): array {
		static $done = false; // WordPress can run the sanitize callback twice on one save.
		if ( $done ) {
			return $tiers;
		}
		$done = true;
		$pos  = -1;
		$i    = 0;
		foreach ( $posted as $index => $row ) {
			if ( '' === sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			if ( (string) $index === $posted_index ) {
				$pos = $i;
				break;
			}
			++$i;
		}
		if ( $pos < 0 || ! isset( $tiers[ $pos ] ) ) {
			add_settings_error( 'fluenthive', 'fh-product', __( 'Give the tier a name before creating its product.', 'fluenthive' ) );
			return $tiers;
		}
		$raw    = isset( $_POST['fh_new_price'][ $posted_index ] ) ? (array) wp_unslash( $_POST['fh_new_price'][ $posted_index ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- options.php verified the nonce; amounts are parsed to integers below.
		$prices = array();
		foreach ( array_keys( Products::OPTIONS ) as $key ) {
			$prices[ $key ] = Products::to_cents( sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) ) );
		}
		$result = Products::create( $tiers[ $pos ], $prices );
		if ( $result['ok'] ) {
			$tiers[ $pos ]['product_id'] = $result['product_id'];
			$tiers[ $pos ]['plans']      = array_merge( $tiers[ $pos ]['plans'], $result['plans'] );
			wp_schedule_single_event( time() + 30, 'fluenthive_paid_reconcile_all' );
		}
		add_settings_error( 'fluenthive', 'fh-product', $result['message'], $result['ok'] ? 'success' : 'error' );
		return $tiers;
	}

	private static function plan_row( string $t, string $p, array $plan, array $vars ): void {
		$n   = static fn( string $k ): string => Settings::OPTION . '[paid_tiers][' . $t . '][plans][' . $p . '][' . $k . ']';
		$vid = (int) ( $plan['variation_id'] ?? 0 );
		$sel = sprintf( '<select name="%s" class="fh-plan-var" aria-label="%s"><option value="0">%s</option>', esc_attr( $n( 'variation_id' ) ), esc_attr__( 'FluentCart price', 'fluenthive' ), esc_html__( '— Choose a product price —', 'fluenthive' ) );
		$grp = '';
		foreach ( $vars as $v ) {
			if ( $v['product'] !== $grp ) {
				$sel .= ( $grp ? '</optgroup>' : '' ) . '<optgroup label="' . esc_attr( $v['product'] . ( 'publish' !== $v['status'] ? ' (' . $v['status'] . ')' : '' ) ) . '">';
				$grp  = $v['product'];
			}
			$terms = 'subscription' === $v['payment_type'] ? Plans::terms_label( array(), $v ) : __( 'one time', 'fluenthive' );
			$sel  .= sprintf( '<option value="%d" data-type="%s"%s>%s — %s %s</option>', (int) $v['id'], esc_attr( $v['payment_type'] ), selected( $vid, (int) $v['id'], false ), esc_html( $v['title'] ?: '#' . $v['id'] ), esc_html( Plans::price( (int) $v['price'] ) ), esc_html( $terms ) );
		}
		$sel .= ( $grp ? '</optgroup>' : '' ) . '</select>';
		$var  = $vid ? Plans::variation( $vid ) : null;
		$sub  = $var && 'subscription' === $var['payment_type'];
		$unit = sprintf( '<select name="%s" aria-label="%s">', esc_attr( $n( 'unit' ) ), esc_attr__( 'Unit', 'fluenthive' ) );
		foreach ( array( 'day' => __( 'days', 'fluenthive' ), 'week' => __( 'weeks', 'fluenthive' ), 'month' => __( 'months', 'fluenthive' ), 'year' => __( 'years', 'fluenthive' ) ) as $u => $label ) {
			$unit .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $u ), selected( (string) ( $plan['unit'] ?? 'month' ), $u, false ), esc_html( $label ) );
		}
		$unit .= '</select>';
		printf(
			'<div class="fh-plan-row">%1$s<div class="fh-plan-access"><span class="fh-plan-sub fh-pill"%2$s><span class="dashicons dashicons-update" aria-hidden="true"></span>%3$s</span><span class="fh-plan-fixed"%4$s><input type="number" min="0" class="small-text" name="%5$s" value="%6$s" aria-label="%7$s"> %8$s<small>%9$s</small></span></div><input type="text" name="%10$s" value="%11$s" placeholder="%12$s" aria-label="%13$s"><button type="button" class="fh-icon-btn fh-remove-plan" aria-label="%14$s" title="%14$s"><span class="dashicons dashicons-no-alt"></span></button></div>',
			$sel, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$sub ? '' : ' hidden',
			esc_html__( 'Follows the subscription', 'fluenthive' ),
			$sub ? ' hidden' : '',
			esc_attr( $n( 'duration' ) ),
			esc_attr( (string) ( $plan['duration'] ?? 12 ) ),
			esc_attr__( 'Access length', 'fluenthive' ),
			$unit, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			esc_html__( '0 = lifetime', 'fluenthive' ),
			esc_attr( $n( 'label' ) ),
			esc_attr( (string) ( $plan['label'] ?? '' ) ),
			esc_attr__( 'Price name', 'fluenthive' ),
			esc_attr__( 'Button label', 'fluenthive' ),
			esc_attr__( 'Remove plan', 'fluenthive' )
		);
	}

	private static function styles(): void {
		echo '<style>
.fh-paid-cell{padding:0!important}
.fh-paid{max-width:980px}
.fh-paid__intro{max-width:720px;margin:4px 0 16px;font-size:14px;line-height:1.6;color:#3c434a}
.fh-ladder{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 20px;padding:0;list-style:none}
.fh-ladder li{margin:0}
.fh-ladder__step{display:inline-flex;align-items:center;gap:6px;padding:6px 14px 6px 10px;background:#fff;border:1px solid #dcdcde;border-radius:999px;font-weight:600;color:#1d2327}
.fh-ladder__step .dashicons{font-size:16px;width:16px;height:16px;color:#2271b1}
.fh-ladder__step--free .dashicons{color:#008a20}
.fh-ladder__count{font-weight:400;font-size:12px;color:#646970}
.fh-ladder__arrow{color:#a7aaad;font-size:16px}
.fh-card{margin:0 0 16px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.fh-card__head{display:flex;align-items:center;gap:14px;padding:14px 16px 14px 20px;border-bottom:1px solid #f0f0f1}
.fh-card__badge{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:10px;background:#f0f6fc;color:#2271b1}
.fh-card--free .fh-card__badge{background:#edfaef;color:#008a20}
.fh-card__title{flex:1;min-width:0}
.fh-paid .fh-card__name{width:100%;max-width:360px;margin:0 0 0 -8px;padding:2px 8px;font-size:17px;font-weight:600;line-height:1.5;border:1px solid transparent;background:transparent;box-shadow:none;color:#1d2327}
.fh-paid .fh-card__name:hover{border-color:#dcdcde}
.fh-paid .fh-card__name:focus{border-color:#2271b1;background:#fff;box-shadow:0 0 0 1px #2271b1}
.fh-card__meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px}
.fh-chip{display:inline-flex;align-items:center;padding:1px 9px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:12px;line-height:20px}
.fh-chip--ok{background:#edfaef;color:#007017}
.fh-card__body{display:grid;gap:22px;padding:20px}
.fh-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px}
.fh-field label,.fh-field__label{display:block;margin:0 0 6px;font-size:13px;font-weight:600;color:#1d2327}
.fh-paid .fh-field input[type=text],.fh-paid .fh-field select,.fh-paid .fh-field textarea{width:100%;max-width:none}
.fh-help{margin:6px 0 0;font-size:12px;line-height:1.5;color:#646970}
.fh-help--flush{margin:0}
.fh-toggle{display:inline-flex;align-items:center;gap:6px}
.fh-section-title{margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970}
.fh-product{padding:16px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px}
.fh-product__title{display:flex;align-items:center;gap:6px;margin:0 0 4px;font-size:14px}
.fh-product__title .dashicons{color:#2271b1}
.fh-product--linked{display:flex;flex-wrap:wrap;align-items:center;gap:12px;background:#fff;border-style:solid;border-color:#dcdcde}
.fh-product__icon{display:grid;place-items:center;width:36px;height:36px;border-radius:8px;background:#f0f6fc;color:#2271b1}
.fh-product__main{display:flex;flex-direction:column;flex:1;min-width:200px}
.fh-product__label{font-size:12px;color:#646970}
.fh-product--linked .button .dashicons{font-size:14px;width:14px;height:14px;margin-top:6px}
.fh-product-more{margin-top:-10px}
.fh-product-more summary{padding:4px 0;color:#2271b1;cursor:pointer}
.fh-product-more[open]{padding:12px 16px 16px;background:#f6f7f7;border-radius:8px}
.fh-prices{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-top:12px}
.fh-field--price label{font-weight:500;font-size:12px;color:#50575e;margin-bottom:4px}
.fh-money{display:flex;align-items:stretch;overflow:hidden;background:#fff;border:1px solid #8c8f94;border-radius:4px}
.fh-money:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.fh-money span{display:flex;align-items:center;padding:0 8px;background:#f6f7f7;color:#50575e;font-size:13px;white-space:nowrap}
.fh-paid .fh-money input{width:72px;min-height:34px;margin:0;border:0;border-radius:0;box-shadow:none;text-align:right}
.fh-create{display:inline-flex!important;align-items:center;gap:4px;min-height:36px!important}
.fh-create .dashicons{font-size:16px;width:16px;height:16px}
.fh-plans-list{overflow:hidden;border:1px solid #dcdcde;border-radius:8px}
.fh-plan-row{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(0,1.4fr) minmax(0,1fr) 32px;gap:12px;align-items:center;padding:10px 12px;border-top:1px solid #f0f0f1}
.fh-plan-rows .fh-plan-row:first-child{border-top:0}
.fh-plan-row--head{padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970}
.fh-paid .fh-plan-row select,.fh-paid .fh-plan-row input[type=text]{width:100%;max-width:none}
.fh-plan-access small{display:block;margin-top:2px;color:#646970}
.fh-plan-access [hidden]{display:none!important}
.fh-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 10px 2px 6px;border-radius:999px;background:#f0f6fc;color:#135e96;font-size:12px;line-height:22px}
.fh-pill .dashicons{font-size:14px;width:14px;height:14px}
.fh-plan-rows:empty::before{content:attr(data-empty);display:block;padding:18px 16px;text-align:center;color:#646970}
.fh-plans-add{margin:8px 0 0}
.fh-plans-add .button-link,.fh-add-tier-btn{display:inline-flex;align-items:center;gap:4px;text-decoration:none}
.fh-plans-add .dashicons{font-size:16px;width:16px;height:16px}
.fh-add-tier-btn .dashicons{font-size:16px;width:16px;height:16px;margin-top:5px}
.fh-icon-btn{display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:6px;background:none;color:#8c8f94;cursor:pointer}
.fh-icon-btn:hover,.fh-icon-btn:focus{background:#fcf0f1;color:#b32d2e}
@media (max-width:782px){.fh-grid{grid-template-columns:1fr}.fh-plan-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 32px}.fh-plan-row > select{grid-column:1 / -1}.fh-plan-row--head{display:none}.fh-card__body{padding:16px}}
</style>';
	}

	private static function script(): void {
		?>
<script>
(function(){
	var wrap=document.getElementById('fh-tiers'),tt=document.getElementById('fh-tier-tpl'),pt=document.getElementById('fh-plan-tpl'),n=Date.now();
	function sync(row){var s=row.querySelector('.fh-plan-var'),o=s.options[s.selectedIndex],sub=o&&o.getAttribute('data-type')==='subscription';row.querySelector('.fh-plan-sub').hidden=!sub;row.querySelector('.fh-plan-fixed').hidden=sub;}
	document.getElementById('fh-add-tier').addEventListener('click',function(){var t='n'+(n++);wrap.insertAdjacentHTML('beforeend',tt.innerHTML.split('__T__').join(t));var c=wrap.lastElementChild;c.querySelector('.fh-card__name').focus();});
	document.addEventListener('click',function(e){
		var b=e.target.closest('button');if(!b)return;
		if(b.classList.contains('fh-add-plan')){var t=b.getAttribute('data-tier');b.closest('.fh-tier-box').querySelector('.fh-plan-rows').insertAdjacentHTML('beforeend',pt.innerHTML.split('__T__').join(t).split('__P__').join('n'+(n++)));}
		if(b.classList.contains('fh-remove-plan')){b.closest('.fh-plan-row').remove();}
		if(b.classList.contains('fh-remove-tier')&&confirm(<?php echo wp_json_encode( __( 'Remove this tier? Issues set to it become free, and its readers are taken off its list at their next update.', 'fluenthive' ) ); ?>)){b.closest('.fh-tier-box').remove();}
	});
	document.addEventListener('change',function(e){if(e.target.classList.contains('fh-plan-var')){sync(e.target.closest('.fh-plan-row'));}});
})();
</script>
		<?php
	}

	private static function heading_row( string $text ): void {
		echo '</table><h2 class="title">' . esc_html( $text ) . '</h2><table class="form-table" role="presentation">';
	}

	private static function number_row( array $s, string $key, string $label, string $help ): void {
		printf( '<tr><th scope="row"><label for="fh-%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" id="fh-%1$s" name="%3$s" value="%4$d"><p class="description">%5$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( Settings::OPTION . '[' . $key . ']' ), (int) $s[ $key ], esc_html( $help ) );
	}

	private static function check_row( array $s, string $key, string $label, string $text, string $help = '' ): void {
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label>%s</td></tr>', esc_html( $label ), esc_attr( Settings::OPTION . '[' . $key . ']' ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $text ), $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' );
	}

	/** Called from Settings::sanitize() for the Paid tab. */
	public static function sanitize( array $in, array $out ): array {
		$before            = wp_list_pluck( Plans::tiers(), 'list_id', 'key' );
		$out['paid_tiers'] = Plans::sanitize( $in['paid_tiers'] ?? array() );
		foreach ( array( 'paid_preview_blocks' => array( 1, 50 ), 'paid_grace_days' => array( 0, 60 ), 'paid_cookie_days' => array( 1, 400 ) ) as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = min( $range[1], max( $range[0], absint( $in[ $k ] ) ) );
			}
		}
		foreach ( array( 'paid_plans_heading' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		if ( isset( $in['paid_plans_intro'] ) ) {
			$out['paid_plans_intro'] = sanitize_textarea_field( $in['paid_plans_intro'] );
		}
		if ( isset( $in['free_tier_name'] ) ) {
			$out['free_tier_name'] = sanitize_text_field( $in['free_tier_name'] ) ?: __( 'Free', 'fluenthive' );
		}
		if ( isset( $in['free_tier_description'] ) ) {
			$out['free_tier_description'] = sanitize_textarea_field( $in['free_tier_description'] );
		}
		if ( isset( $in['_create_product'] ) ) {
			$out['paid_tiers'] = self::create_product_for( (string) $in['_create_product'], (array) ( $in['paid_tiers'] ?? array() ), $out['paid_tiers'] );
		}
		// A changed tier map or list needs every reader re-checked once.
		$after = wp_list_pluck( $out['paid_tiers'], 'list_id', 'key' );
		if ( $before !== $after || wp_json_encode( Plans::tiers() ) !== wp_json_encode( $out['paid_tiers'] ) ) {
			wp_schedule_single_event( time() + 30, 'fluenthive_paid_reconcile_all' );
		}
		return $out;
	}

	/* ---------- members screen ---------- */

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Issues::TYPE, __( 'Paid members', 'fluenthive' ), __( 'Paid members', 'fluenthive' ), 'manage_options', self::PAGE, array( __CLASS__, 'screen' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . Issues::TYPE . '&page=' . self::PAGE ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fluenthive' ) );
		}
		check_admin_referer( 'fluenthive_members' );
		$op    = sanitize_key( wp_unslash( $_REQUEST['op'] ?? '' ) );
		$msg   = '';
		$error = false;
		if ( 'grant' === $op ) {
			$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$tier    = sanitize_key( wp_unslash( $_POST['tier'] ?? '' ) );
			$until   = sanitize_text_field( wp_unslash( $_POST['until'] ?? '' ) );
			$expires = $until && strtotime( $until ) ? gmdate( 'Y-m-d 23:59:59', strtotime( $until ) ) : null;
			$id      = Members::grant( $email, $tier, $expires, sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
			/* translators: 1: email, 2: tier */
			$msg   = $id ? sprintf( __( '%1$s now has %2$s access.', 'fluenthive' ), $email, Plans::name( $tier ) ) : __( 'Enter a valid email and tier.', 'fluenthive' );
			$error = ! $id;
		} elseif ( 'revoke' === $op ) {
			$done  = Members::revoke( absint( $_REQUEST['id'] ?? 0 ) );
			$msg   = $done ? __( 'Access revoked.', 'fluenthive' ) : __( 'Only manual grants can be revoked here. Cancel or refund FluentCart purchases in FluentCart.', 'fluenthive' );
			$error = ! $done;
		} elseif ( 'reconcile' === $op ) {
			/* translators: %d: number of customers */
			$msg = sprintf( __( 'Re-checked %d FluentCart customer(s).', 'fluenthive' ), Members::reconcile_all() );
		}
		Settings::flash( $msg, $error );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function screen(): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tier   = sanitize_key( wp_unslash( $_GET['tier'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state  = sanitize_key( wp_unslash( $_GET['state'] ?? 'live' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$live   = "'" . implode( "','", Members::LIVE ) . "'";
		$where  = array( '1=1' );
		$args   = array();
		if ( $tier ) {
			$where[] = 'tier = %s';
			$args[]  = $tier;
		}
		if ( 'live' === $state ) {
			$where[] = "status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s)";
			$args[]  = $now;
		} elseif ( 'ended' === $state ) {
			$where[] = "NOT (status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s))";
			$args[]  = $now;
		}
		if ( $search ) {
			$where[] = 'email LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( strtolower( $search ) ) . '%';
		}
		$sql   = 'FROM ' . Members::table() . ' WHERE ' . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) {$sql}", $args ) : "SELECT COUNT(*) {$sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * {$sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) ) ?: array();

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Paid members', 'fluenthive' ) . '</h1>';
		Settings::print_flash();
		if ( ! Plans::tiers() ) {
			echo '<div class="notice notice-info"><p><a href="' . esc_url( add_query_arg( 'tab', 'paid', Settings::url() ) ) . '">' . esc_html__( 'Set up a paid tier first.', 'fluenthive' ) . '</a></p></div></div>';
			return;
		}

		$counts = Members::counts();
		echo '<p>';
		foreach ( Plans::tiers() as $t ) {
			/* translators: 1: tier name, 2: count */
			echo '<span style="margin-right:1.25rem"><strong>' . esc_html( $t['name'] ) . '</strong>: ' . (int) ( $counts[ $t['key'] ] ?? 0 ) . '</span>';
		}
		echo '</p>';

		echo '<form method="get" style="margin:1rem 0"><input type="hidden" name="post_type" value="' . esc_attr( Issues::TYPE ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<select name="tier"><option value="">' . esc_html__( 'All tiers', 'fluenthive' ) . '</option>';
		foreach ( Plans::tiers() as $t ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $t['key'] ), selected( $tier, $t['key'], false ), esc_html( $t['name'] ) );
		}
		echo '</select> <select name="state">';
		foreach ( array( 'live' => __( 'Active', 'fluenthive' ), 'ended' => __( 'Ended', 'fluenthive' ), 'all' => __( 'All', 'fluenthive' ) ) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $state, $k, false ), esc_html( $label ) );
		}
		echo '</select> <input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Email', 'fluenthive' ) . '"> ';
		submit_button( __( 'Filter', 'fluenthive' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Email', 'fluenthive' ) . '</th><th>' . esc_html__( 'Tier', 'fluenthive' ) . '</th><th>' . esc_html__( 'Access', 'fluenthive' ) . '</th><th>' . esc_html__( 'Source', 'fluenthive' ) . '</th><th>' . esc_html__( 'Started', 'fluenthive' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No members match.', 'fluenthive' ) . '</td></tr>';
		}
		$fc = admin_url( 'admin.php?page=fluent-cart#/' );
		foreach ( $rows as $r ) {
			if ( $r->subscription_id ) {
				$source = '<a href="' . esc_url( $fc . 'subscriptions/' . (int) $r->subscription_id . '/view' ) . '">' . esc_html( sprintf( /* translators: %d: id */ __( 'Subscription #%d', 'fluenthive' ), $r->subscription_id ) ) . '</a>';
			} elseif ( $r->order_id ) {
				$source = '<a href="' . esc_url( $fc . 'orders/' . (int) $r->order_id . '/view' ) . '">' . esc_html( sprintf( /* translators: %d: id */ __( 'Order #%d', 'fluenthive' ), $r->order_id ) ) . '</a> · ' . esc_html( 'lifetime' === $r->mode ? __( 'lifetime', 'fluenthive' ) : __( 'fixed term', 'fluenthive' ) );
			} else {
				$source = esc_html__( 'Manual', 'fluenthive' ) . ( $r->note ? ' · <em>' . esc_html( $r->note ) . '</em>' : '' );
			}
			$action = '';
			if ( 'manual' === $r->mode && Members::is_live( $r ) ) {
				$action = '<a class="button-link-delete" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'fluenthive_members', 'op' => 'revoke', 'id' => (int) $r->id ), admin_url( 'admin-post.php' ) ), 'fluenthive_members' ) ) . '">' . esc_html__( 'Revoke', 'fluenthive' ) . '</a>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->email ),
				esc_html( Plans::name( (string) $r->tier ) ),
				esc_html( Access::status_label( $r ) ),
				$source, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $r->starts_at ? wp_date( get_option( 'date_format' ), strtotime( $r->starts_at . ' UTC' ) ) : '' ),
				$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</p>';
		}

		echo '<h2 style="margin-top:2rem">' . esc_html__( 'Give access by hand', 'fluenthive' ) . '</h2><p class="description">' . esc_html__( 'For comps, gifts and team members. Leave the date empty for access with no end.', 'fluenthive' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		wp_nonce_field( 'fluenthive_members' );
		echo '<input type="hidden" name="action" value="fluenthive_members"><input type="hidden" name="op" value="grant">';
		echo '<input type="email" name="email" required placeholder="' . esc_attr__( 'reader@example.com', 'fluenthive' ) . '" class="regular-text"> <select name="tier">';
		foreach ( Plans::tiers() as $t ) {
			printf( '<option value="%s">%s</option>', esc_attr( $t['key'] ), esc_html( $t['name'] ) );
		}
		echo '</select> <label>' . esc_html__( 'Until', 'fluenthive' ) . ' <input type="date" name="until"></label> <input type="text" name="note" placeholder="' . esc_attr__( 'Note (optional)', 'fluenthive' ) . '"> ';
		submit_button( __( 'Give access', 'fluenthive' ), 'primary', '', false );
		echo '</form>';

		echo '<h2 style="margin-top:2rem">' . esc_html__( 'Re-check FluentCart', 'fluenthive' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'fluenthive_members' );
		echo '<input type="hidden" name="action" value="fluenthive_members"><input type="hidden" name="op" value="reconcile"><p class="description">' . esc_html__( 'Rebuilds every paid reader from FluentCart orders and subscriptions, then updates their FluentCRM lists and tags. Safe to run any time.', 'fluenthive' ) . '</p>';
		submit_button( __( 'Re-check all purchases', 'fluenthive' ), 'secondary', '', false );
		echo '</form></div>';
	}
}
