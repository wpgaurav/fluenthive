<?php
/**
 * The newsletter page (Settings → General → Newsletter page) in the FluentHive design:
 * a signup card, recent issues, then the page's own content as an editable prose card.
 */

defined( 'ABSPATH' ) || exit;

use FluentHive\Icons;
use FluentHive\Issues;
use FluentHive\Settings;
use FluentHive\Shell;

Shell::head();
the_post();
$fluenthive_form   = Settings::form_shortcode();
$fluenthive_facts  = Settings::lines( 'subscribe_facts' );
$fluenthive_byline = (string) Settings::get( 'byline' );
$fluenthive_limit  = (int) Settings::get( 'landing_recent' );
// 0 hides the section. get_posts() would read 0 as "use the site's default count".
$fluenthive_recent = $fluenthive_limit < 1 ? array() : get_posts(
	array(
		'post_type'      => Issues::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => $fluenthive_limit,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
$fluenthive_count = (int) wp_count_posts( Issues::TYPE )->publish;
?>
<main id="fh-main" class="fh-main fh-landing">
	<section class="fh-mail fh-hero" aria-labelledby="fh-hero-h">
		<header class="fh-mail__from">
			<?php echo Shell::avatar( 76 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $fluenthive_byline ) : ?><p class="fh-mail__sender"><?php echo esc_html( $fluenthive_byline ); ?></p><?php endif; ?>
			<p class="fh-mail__meta">
				<?php if ( $fluenthive_count ) : ?>
					<span><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php /* translators: %d: number of issues */ echo esc_html( sprintf( _n( '%d issue', '%d issues', $fluenthive_count, 'fluenthive' ), $fluenthive_count ) ); ?></span>
				<?php endif; ?>
				<span><?php echo Icons::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Free by email', 'fluenthive' ); ?></span>
			</p>
		</header>
		<div class="fh-hero__body">
			<h1 class="fh-mail__subject" id="fh-hero-h"><?php echo esc_html( Settings::brand() ); ?></h1>
			<?php if ( Settings::tagline() ) : ?><p class="fh-mail__dek"><?php echo esc_html( Settings::tagline() ); ?></p><?php endif; ?>
			<?php if ( $fluenthive_form ) : ?>
				<div class="fh-sub__form" id="fh-subscribe"><?php echo do_shortcode( $fluenthive_form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
			<?php endif; ?>
			<?php if ( $fluenthive_facts ) : ?>
				<ul class="fh-facts" role="list"><?php foreach ( $fluenthive_facts as $fluenthive_fact ) : ?><li><?php echo Icons::get( 'circle-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $fluenthive_fact ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $fluenthive_recent ) : ?>
				<p class="fh-hero__alt"><a href="#fh-recent"><?php esc_html_e( 'Read a past issue first', 'fluenthive' ); ?><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
			<?php endif; ?>
		</div>
	</section>

	<?php do_action( 'fluenthive_landing_after_hero' ); ?>

	<?php if ( $fluenthive_recent ) : ?>
	<section class="fh-year fh-recent" id="fh-recent" aria-labelledby="fh-recent-h">
		<h2 id="fh-recent-h"><?php echo Icons::get( 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Recent issues', 'fluenthive' ); ?></h2>
		<ol class="fh-list" role="list">
			<?php foreach ( $fluenthive_recent as $fluenthive_issue ) : ?>
			<li>
				<a class="fh-row" href="<?php echo esc_url( get_permalink( $fluenthive_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $fluenthive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $fluenthive_issue ) ); ?></time>
					<span class="fh-row__main"><span class="fh-row__t"><?php echo esc_html( get_the_title( $fluenthive_issue ) ); ?><?php echo apply_filters( 'fluenthive_issue_badge', '', $fluenthive_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter callback. ?></span><span class="fh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $fluenthive_issue ), 24 ) ); ?></span></span>
					<span class="fh-row__go"><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			</li>
			<?php endforeach; ?>
		</ol>
		<p class="fh-recent__all"><a href="<?php echo esc_url( get_post_type_archive_link( Issues::TYPE ) ); ?>"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'See every issue', 'fluenthive' ); ?></a></p>
	</section>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
	<article class="fh-mail fh-mail--page fh-landing__content">
		<div class="fh-mail__body fh-prose">
			<?php the_content(); ?>
		</div>
	</article>
	<?php endif; ?>

	<?php if ( $fluenthive_form ) : ?>
	<p class="fh-landing__cta"><a class="fh-btn" href="#fh-subscribe"><?php echo Icons::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( Settings::subscribe_heading() ); ?></span></a></p>
	<?php endif; ?>
</main>
<?php
Shell::foot();
