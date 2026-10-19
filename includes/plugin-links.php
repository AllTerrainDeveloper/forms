<?php
/**
 * The links under the plugin's name on the Plugins screen.
 *
 * Somebody stuck on a form looks for help where they installed the plugin, so
 * the row points at the WordPress.org support forum: the place where a
 * question gets an answer the next person with the same question can find.
 * Bug reports with a reproduction still belong on GitHub, which the plugin URI
 * already links to.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/** The WordPress.org support forum. */
define( 'ALLTFO_SUPPORT_URL', 'https://wordpress.org/support/plugin/allterrain-forms/' );

add_filter( 'plugin_row_meta', 'alltfo_plugin_row_meta', 10, 2 );

/**
 * Adds a Support link to this plugin's row.
 *
 * @since 1.4.1
 *
 * @param string[] $links The row's meta links.
 * @param string   $file  Plugin file path relative to the plugins directory.
 * @return string[]
 */
function alltfo_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( ALLTFO_FILE ) !== $file ) {
		return $links;
	}

	/**
	 * Filters where the Support link on the Plugins screen points.
	 *
	 * @since 1.4.1
	 *
	 * @param string $url The WordPress.org support forum.
	 */
	$url = apply_filters( 'alltfo_support_url', ALLTFO_SUPPORT_URL );

	if ( $url ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Support', 'allterrain-forms' )
		);
	}

	return $links;
}
