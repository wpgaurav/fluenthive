<?php
/**
 * Every published issue, newest first, grouped by year.
 */

defined( 'ABSPATH' ) || exit;

use FluentHive\Icons;
use FluentHive\Issues;
use FluentHive\Settings;
use FluentHive\Shell;

Shell::head();
/* translators: %s: newsletter name */
$fluenthive_title = (string) Settings::get( 'archive_title' ) ?: sprintf( __( '%s: every issue', 'fluenthive' ), Settings::brand() );
$fluenthive_intro = (string) Settings::get( 'archive_intro' ) ?: Settings::tagline();
?>
<main id="fh-main" class="fh-main">
	<header class="fh-archive__head">
		<span class="fh-archive__icon"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h1><?php echo esc_html( $fluenthive_title ); ?></h1>
		<?php if ( $fluenthive_intro ) : ?><p><?php echo esc_html( $fluenthive_intro ); ?></p><?php endif; ?>
	</header>

	<?php if ( ! have_posts() ) : ?>
		<p class="fh-archive__empty"><?php esc_html_e( 'The first issue is on its way.', 'fluenthive' ); ?></p>
	<?php else : ?>
		<?php $fluenthive_year = ''; ?>
		<?php while ( have_posts() ) : ?>
			<?php
			the_post();
			$fluenthive_issue     = get_post();
			$fluenthive_this_year = get_the_date( 'Y', $fluenthive_issue );
			if ( $fluenthive_this_year !== $fluenthive_year ) :
				if ( '' !== $fluenthive_year ) :
					?>
		</ol>
	</section>
					<?php
				endif;
				$fluenthive_year = $fluenthive_this_year;
				?>
	<section class="fh-year" aria-labelledby="fh-y-<?php echo esc_attr( $fluenthive_year ); ?>">
		<h2 id="fh-y-<?php echo esc_attr( $fluenthive_year ); ?>"><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $fluenthive_year ); ?></h2>
		<ol class="fh-list" role="list">
				<?php
			endif;
			?>
			<li>
				<a class="fh-row" href="<?php echo esc_url( get_permalink( $fluenthive_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $fluenthive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $fluenthive_issue ) ); ?></time>
					<span class="fh-row__main"><span class="fh-row__t"><?php echo esc_html( get_the_title( $fluenthive_issue ) ); ?><?php echo apply_filters( 'fluenthive_issue_badge', '', $fluenthive_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter callback. ?></span><span class="fh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $fluenthive_issue ), 24 ) ); ?></span></span>
					<span class="fh-row__go"><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			</li>
		<?php endwhile; ?>
		<?php if ( '' !== $fluenthive_year ) : ?>
		</ol>
	</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	$fluenthive_pages = paginate_links(
		array(
			'type'      => 'list',
			'mid_size'  => 1,
			'prev_text' => __( 'Newer', 'fluenthive' ),
			'next_text' => __( 'Older', 'fluenthive' ),
		)
	);
	if ( $fluenthive_pages ) :
		?>
	<nav class="fh-pager" aria-label="<?php esc_attr_e( 'Issue pages', 'fluenthive' ); ?>"><?php echo wp_kses_post( $fluenthive_pages ); ?></nav>
		<?php
	endif;
	?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
