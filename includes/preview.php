<?php
/**
 * The standalone preview page.
 *
 * A real, front-end URL that renders one form and nothing else. It exists so the
 * builder's eye button has somewhere to point: OpenStation's title-bar preview
 * convention opens a *paired window* beside the editor, and a window needs a URL
 * rather than a blob of HTML.
 *
 * That it is a URL rather than an overlay pays for itself twice. The preview is
 * the real front-end render — the same `alltfo_render_form()`, the same stylesheet,
 * the same bundle, the same theme resolution — so what the builder shows is what
 * a visitor gets, rather than an approximation maintained separately. And the
 * link can be sent to somebody for a look before the form goes live.
 *
 * Locked to users who can edit forms, and signed, because an unpublished form is
 * not public and a preview URL is a way around that if it is not.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/** The query flag that turns a request into a preview render. */
const ALLTFO_PREVIEW_QUERY = 'alltfo_preview_form';

add_action( 'init', 'alltfo_register_preview_query' );
add_action( 'template_redirect', 'alltfo_maybe_render_preview' );

/**
 * Registers the query variable.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_register_preview_query() {
	add_filter(
		'query_vars',
		static function ( $vars ) {
			$vars[] = ALLTFO_PREVIEW_QUERY;

			return $vars;
		}
	);
}

/**
 * The URL that previews one form.
 *
 * Nonced as well as capability-checked. The capability check is what actually
 * protects the form; the nonce stops the URL being useful if it is copied out of
 * somebody's history into a context where they are still logged in.
 *
 * @since 0.1.0
 *
 * @param int    $form_id The form.
 * @param string $theme   Optional. A theme to preview instead of the form's own.
 * @return string
 */
function alltfo_form_preview_url( $form_id, $theme = '' ) {
	$args = array(
		ALLTFO_PREVIEW_QUERY => absint( $form_id ),
		'alltfo_nonce'       => wp_create_nonce( 'alltfo_preview_' . absint( $form_id ) ),
	);

	if ( '' !== $theme ) {
		$args['alltfo_theme'] = sanitize_key( $theme );
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Renders the preview page, when this is one.
 *
 * On `template_redirect` so it can take over the request entirely rather than
 * rendering inside whatever template the query happened to resolve to. A preview
 * wrapped in the site's header, footer, cookie banner and sidebar tells you very
 * little about the form.
 *
 * @since 0.1.0
 *
 * @return void
 */
function alltfo_maybe_render_preview() {
	$form_id = absint( get_query_var( ALLTFO_PREVIEW_QUERY ) );

	// `get_query_var()` only sees registered vars, and a plain `?alltfo_preview_form=`
	// on a static front page can bypass the parse. Reading the raw request as a
	// fallback keeps the link working on every permalink setup.
	if ( ! $form_id && isset( $_GET[ ALLTFO_PREVIEW_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified immediately below.
		$form_id = absint( wp_unslash( $_GET[ ALLTFO_PREVIEW_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
	}

	if ( ! $form_id ) {
		return;
	}

	$nonce = isset( $_GET['alltfo_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['alltfo_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This *is* the verification.

	if ( ! wp_verify_nonce( $nonce, 'alltfo_preview_' . $form_id ) || ! alltfo_can_edit_forms() ) {
		wp_die(
			esc_html__( 'You cannot preview this form.', 'allterrain-forms' ),
			esc_html__( 'Preview unavailable', 'allterrain-forms' ),
			array( 'response' => 403 )
		);
	}

	$theme = isset( $_GET['alltfo_theme'] ) ? sanitize_key( wp_unslash( $_GET['alltfo_theme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.

	alltfo_enqueue_form_assets();

	// The page's own dress — the light document around the form — rides
	// `wp_head()` like any other stylesheet instead of being printed by hand.
	wp_enqueue_style( 'allterrain-forms-preview' );

	// `noindex` because this is a working document, not a page. Search engines
	// finding a preview URL would be odd; them indexing an unpublished form
	// would be worse.
	add_filter( 'wp_robots', 'wp_robots_no_robots' );

	status_header( 200 );
	nocache_headers();

	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( get_the_title( $form_id ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="atf-preview-page">
	<div class="atf-preview-page__inner">
		<p class="atf-preview-page__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the form's title. */
					__( 'Preview of “%s”. Submissions made here are not stored.', 'allterrain-forms' ),
					get_the_title( $form_id )
				)
			);
			?>
		</p>
		<?php
		// `preview => true` is what makes this honest: the submission runs the
		// whole pipeline, including validation, and then stops short of storing
		// an entry or sending an email.
		echo alltfo_render_form( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled and escaped by the renderer.
			$form_id,
			array(
				'theme'   => $theme,
				'preview' => true,
				'title'   => 'show',
			)
		);
		?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php

	exit;
}
