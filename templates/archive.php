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
$fluenthive_q     = Settings::on( 'archive_search' ) ? Issues::search_term() : '';
$fluenthive_nums  = Settings::on( 'show_issue_number' );
?>
<main id="fh-main" class="fh-main">
	<header class="fh-archive__head">
		<span class="fh-archive__icon"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h1><?php echo esc_html( $fluenthive_title ); ?></h1>
		<?php if ( $fluenthive_intro ) : ?><p><?php echo esc_html( $fluenthive_intro ); ?></p><?php endif; ?>
		<?php if ( Settings::on( 'archive_search' ) ) : ?>
			<form class="fh-search" role="search" method="get" action="<?php echo esc_url( (string) get_post_type_archive_link( Issues::TYPE ) ); ?>">
				<label class="fh-sr" for="fh-q"><?php esc_html_e( 'Search issues', 'fluenthive' ); ?></label>
				<?php echo Icons::get( 'search', 'fh-search__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="search" id="fh-q" name="q" value="<?php echo esc_attr( $fluenthive_q ); ?>" placeholder="<?php esc_attr_e( 'Search issues', 'fluenthive' ); ?>" maxlength="100" autocomplete="off">
				<button type="submit" class="fh-btn fh-btn--sm"><?php esc_html_e( 'Search', 'fluenthive' ); ?></button>
			</form>
		<?php endif; ?>
	</header>

	<?php if ( '' !== $fluenthive_q ) : ?>
		<p class="fh-search__status" role="status">
			<?php
			global $wp_query;
			/* translators: 1: number of results, 2: search term */
			echo esc_html( sprintf( _n( '%1$d issue matches "%2$s".', '%1$d issues match "%2$s".', (int) $wp_query->found_posts, 'fluenthive' ), (int) $wp_query->found_posts, $fluenthive_q ) );
			?>
			<a href="<?php echo esc_url( (string) get_post_type_archive_link( Issues::TYPE ) ); ?>"><?php esc_html_e( 'Show every issue', 'fluenthive' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( ! have_posts() ) : ?>
		<p class="fh-archive__empty"><?php echo '' !== $fluenthive_q ? esc_html__( 'Try a shorter word or a different topic.', 'fluenthive' ) : esc_html__( 'The first issue is on its way.', 'fluenthive' ); ?></p>
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
					<span class="fh-row__when"><time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $fluenthive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $fluenthive_issue ) ); ?></time><?php if ( $fluenthive_nums && Issues::number( $fluenthive_issue ) ) : ?><small>#<?php echo (int) Issues::number( $fluenthive_issue ); ?></small><?php endif; ?></span>
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
