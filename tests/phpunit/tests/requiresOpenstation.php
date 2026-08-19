<?php
/**
 * OpenStation is a dependency, not a suggestion.
 *
 * The `Requires Plugins` header is the real gate — WordPress 6.5+ refuses to
 * activate this plugin without the shell and refuses to deactivate the shell
 * underneath it. What is testable here is the header's presence and the notice
 * that covers the installs the header cannot reach.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The dependency header and the missing-shell notice.
 *
 * @group allterrain-forms
 */
class ATF_Test_Requires_Openstation extends WP_UnitTestCase {

	/**
	 * The plugin declares the dependency WordPress enforces.
	 *
	 * A test on a file's header rather than a function, because the header is
	 * the feature: activation-time enforcement belongs to WordPress, and all
	 * this plugin has to do is say the words where core reads them.
	 */
	public function test_header_declares_the_dependency() {
		$header = file_get_contents( ATF_FILE, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading our own plugin header in a test.

		$this->assertMatchesRegularExpression( '/^ \* Requires Plugins:\s+desktop-mode$/m', $header );
	}

	/**
	 * An admin on a shell-less site is told what is missing.
	 *
	 * The suite runs without OpenStation, which is exactly the scenario the
	 * notice exists for.
	 *
	 * @covers ::atf_shell_missing_notice
	 */
	public function test_notice_shows_without_the_shell() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		atf_shell_missing_notice();
		$notice = ob_get_clean();

		$this->assertStringContainsString( 'OpenStation', $notice );
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'plugin-install.php', $notice, 'The notice links somewhere the fix can actually happen.' );
	}

	/**
	 * Somebody who cannot install plugins is not nagged about one.
	 *
	 * @covers ::atf_shell_missing_notice
	 */
	public function test_notice_is_silent_for_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		ob_start();
		atf_shell_missing_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The notice is wired to `admin_notices`, not just defined.
	 *
	 * @covers ::atf_shell_missing_notice
	 */
	public function test_notice_is_hooked() {
		$this->assertSame( 10, has_action( 'admin_notices', 'atf_shell_missing_notice' ) );
	}
}
