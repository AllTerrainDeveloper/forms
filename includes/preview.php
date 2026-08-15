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
 * the real front-end render — the same `atf_render_form()`, the same stylesheet,
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
const ATF_PREVIEW_QUERY = 'atf_preview_form';

add_action( 'init', 'atf_register_preview_query' );
add_action( 'template_redirect', 'atf_maybe_render_preview' );

/**
 * Registers the query variable.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_preview_query() {
	add_filter(
		'query_vars',
		static function ( $vars ) {
			$vars[] = ATF_PREVIEW_QUERY;

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
function atf_form_preview_url( $form_id, $theme = '' ) {
	$args = array(
		ATF_PREVIEW_QUERY => absint( $form_id ),
		'atf_nonce'       => wp_create_nonce( 'atf_preview_' . absint( $form_id ) ),
	);

	if ( '' !== $theme ) {
		$args['atf_theme'] = sanitize_key( $theme );
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
function atf_maybe_render_preview() {
	$form_id = absint( get_query_var( ATF_PREVIEW_QUERY ) );

	// `get_query_var()` only sees registered vars, and a plain `?atf_preview_form=`
	// on a static front page can bypass the parse. Reading the raw request as a
	// fallback keeps the link working on every permalink setup.
	if ( ! $form_id && isset( $_GET[ ATF_PREVIEW_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified immediately below.
		$form_id = absint( wp_unslash( $_GET[ ATF_PREVIEW_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
	}

	if ( ! $form_id ) {
		return;
	}

	$nonce = isset( $_GET['atf_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['atf_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This *is* the verification.

	if ( ! wp_verify_nonce( $nonce, 'atf_preview_' . $form_id ) || ! atf_can_edit_forms() ) {
		wp_die(
			esc_html__( 'You cannot preview this form.', 'allterrain-forms' ),
			esc_html__( 'Preview unavailable', 'allterrain-forms' ),
			array( 'response' => 403 )
		);
	}

	$theme = isset( $_GET['atf_theme'] ) ? sanitize_key( wp_unslash( $_GET['atf_theme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.

	atf_enqueue_form_assets();

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
	<style>
		/* A page, not an admin screen.
		 *
		 * This used to follow `prefers-color-scheme` and paint itself dark for a
		 * viewer whose system is dark. That is right for a tool and wrong for
		 * this: the *form* decides its own colours from its theme, and a light
		 * theme like Clean leaves `--atf-bg` transparent and sets near-black text.
		 * Put that on a dark page and the labels vanish — dark grey on dark grey —
		 * while every swatch in the builder insists the theme is light. Two
		 * independent colour decisions, one surface.
		 *
		 * So the preview commits to a light page, the way an ordinary WordPress
		 * page renders on the overwhelming majority of sites. A dark theme still
		 * looks dark here, because a dark theme paints its own card — which is
		 * also exactly how it would look on a real light-themed site. What you see
		 * is what a visitor gets, which is the whole point of previewing on a URL
		 * rather than in an overlay.
		 */
		body.atf-preview-page {
			margin: 0;
			padding: 48px 20px;
			background: #f6f7f7;
			color: #1e1e1e;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
			min-height: 100vh;
			box-sizing: border-box;
			/* The viewer's browser must not restyle this page for dark mode either
				— `color-scheme: light` is what stops form controls the theme has not
				claimed from being painted with dark system chrome. */
			color-scheme: light;
		}

		/* The content column of a page or post: white, centred, with the same kind
			of measure a theme would give an article. */
		.atf-preview-page__inner {
			max-width: 760px;
			margin: 0 auto;
			padding: 40px;
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba( 0, 0, 0, 0.08 );
			box-sizing: border-box;
		}

		.atf-preview-page__note {
			margin: 0 0 28px;
			padding: 10px 14px;
			border-radius: 6px;
			background: #f6f7f7;
			border: 1px solid #dcdcde;
			color: #50575e;
			font-size: 13px;
			line-height: 1.5;
		}

		@media ( max-width: 600px ) {
			body.atf-preview-page {
				padding: 20px 12px;
			}

			.atf-preview-page__inner {
				padding: 24px 20px;
			}
		}
	</style>
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
		echo atf_render_form( // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Assembled and escaped by the renderer.
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
