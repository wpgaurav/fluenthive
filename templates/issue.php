<?php
/**
 * Single newsletter issue, rendered as the email it was.
 */

defined( 'ABSPATH' ) || exit;

use FluentHive\Icons;
use FluentHive\Issues;
use FluentHive\Settings;
use FluentHive\Shell;

Shell::head();
the_post();
$fluenthive_post       = get_post();
$fluenthive_subtitle   = Issues::subtitle( $fluenthive_post );
$fluenthive_neighbours = Issues::neighbours( $fluenthive_post );
$fluenthive_text = (string) $fluenthive_post->post_content;
if ( class_exists( '\FluentHive\Modules\Paid\Access' ) && \FluentHive\Modules\Paid\Access::is_paid( $fluenthive_post ) && ! \FluentHive\Modules\Paid\Access::can_read( $fluenthive_post ) ) {
	$fluenthive_text = \FluentHive\Modules\Paid\Access::preview_plain( $fluenthive_post );
}
// Whitespace-separated tokens, so Devanagari, Cyrillic and other non-Latin scripts count too.
$fluenthive_plain = html_entity_decode( wp_strip_all_tags( $fluenthive_text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
$fluenthive_split = preg_split( '/[\s\p{Z}]+/u', $fluenthive_plain, -1, PREG_SPLIT_NO_EMPTY );
$fluenthive_words = false === $fluenthive_split ? str_word_count( $fluenthive_plain ) : count( $fluenthive_split );
$fluenthive_minutes    = max( 1, (int) round( $fluenthive_words / 230 ) );
?>
<main id="fh-main" class="fh-main">
	<article class="fh-mail" aria-labelledby="fh-subject">
		<header class="fh-mail__from">
			<?php echo Shell::avatar( 76 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="fh-mail__sender"><?php echo Shell::sender_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sender_line(). ?></p>
			<p class="fh-mail__meta">
				<span><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></span>
				<span><?php echo Icons::get( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php /* translators: %d: minutes */ echo esc_html( sprintf( _n( '%d min read', '%d min read', $fluenthive_minutes, 'fluenthive' ), $fluenthive_minutes ) ); ?></span>
			</p>
		</header>
		<div class="fh-mail__head">
			<h1 class="fh-mail__subject" id="fh-subject"><?php the_title(); ?></h1>
			<?php if ( $fluenthive_subtitle ) : ?>
				<p class="fh-mail__dek"><?php echo esc_html( $fluenthive_subtitle ); ?></p>
			<?php endif; ?>
		</div>
		<div class="fh-mail__body fh-prose">
			<?php
			$fluenthive_body = apply_filters( 'fluenthive_issue_body', null, $fluenthive_post );
			if ( null === $fluenthive_body ) {
				the_content();
			} else {
				echo $fluenthive_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered content from the paid module.
			}
			?>
		</div>
		<?php Shell::share_bar( $fluenthive_post ); ?>
	</article>

	<?php if ( Settings::on( 'show_pager' ) && ( $fluenthive_neighbours['older'] || $fluenthive_neighbours['newer'] ) ) : ?>
	<nav class="fh-pager" aria-label="<?php esc_attr_e( 'More issues', 'fluenthive' ); ?>">
		<?php if ( $fluenthive_neighbours['older'] ) : ?>
			<a class="fh-pager__link" href="<?php echo esc_url( get_permalink( $fluenthive_neighbours['older'] ) ); ?>" rel="prev"><small><?php echo Icons::get( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Previous issue', 'fluenthive' ); ?></small><span><?php echo esc_html( get_the_title( $fluenthive_neighbours['older'] ) ); ?></span></a>
		<?php else : ?><span></span><?php endif; ?>
		<?php if ( $fluenthive_neighbours['newer'] ) : ?>
			<a class="fh-pager__link fh-pager__link--next" href="<?php echo esc_url( get_permalink( $fluenthive_neighbours['newer'] ) ); ?>" rel="next"><small><?php esc_html_e( 'Next issue', 'fluenthive' ); ?><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small><span><?php echo esc_html( get_the_title( $fluenthive_neighbours['newer'] ) ); ?></span></a>
		<?php endif; ?>
	</nav>
	<?php endif; ?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
