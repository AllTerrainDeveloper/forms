<?php
/**
 * The Support link on the Plugins screen.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The row meta added to this plugin, and only to this plugin.
 *
 * @group allterrain-forms
 * @covers ::alltfo_plugin_row_meta
 */
class ALLTFO_Test_Plugin_Links extends WP_UnitTestCase {

	/**
	 * Removes the filter a test added.
	 */
	public function tear_down() {
		remove_all_filters( 'alltfo_support_url' );

		parent::tear_down();
	}

	/**
	 * This plugin's row gains a link to the WordPress.org support forum.
	 */
	public function test_adds_support_link_to_own_row() {
		$links = alltfo_plugin_row_meta( array( 'Version 1.4.0' ), plugin_basename( ALLTFO_FILE ) );

		$this->assertCount( 2, $links );
		$this->assertSame( 'Version 1.4.0', $links[0] );
		$this->assertStringContainsString( 'href="https://wordpress.org/support/plugin/allterrain-forms/"', $links[1] );
		$this->assertStringContainsString( '>Support</a>', $links[1] );
	}

	/**
	 * Every other plugin's row is returned untouched.
	 */
	public function test_leaves_other_rows_alone() {
		$links = array( 'Version 2.0' );

		$this->assertSame( $links, alltfo_plugin_row_meta( $links, 'akismet/akismet.php' ) );
	}

	/**
	 * The destination can be changed, and an empty one removes the link.
	 */
	public function test_support_url_is_filterable() {
		add_filter(
			'alltfo_support_url',
			static function () {
				return 'https://example.com/help/';
			}
		);
		$links = alltfo_plugin_row_meta( array(), plugin_basename( ALLTFO_FILE ) );
		$this->assertStringContainsString( 'href="https://example.com/help/"', $links[0] );

		add_filter( 'alltfo_support_url', '__return_empty_string', 20 );
		$this->assertSame( array(), alltfo_plugin_row_meta( array(), plugin_basename( ALLTFO_FILE ) ) );
	}
}
