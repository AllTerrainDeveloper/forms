<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress test library and hooks this plugin in as a must-use plugin
 * so it is active for every test without needing an activation step.
 *
 * `WP_TESTS_DIR` names a WordPress test library; without one there is nothing to
 * test against and failing loudly here beats a hundred confusing failures later.
 *
 * @package AllTerrain_Forms
 */

$atf_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $atf_tests_dir ) {
	$atf_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $atf_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$atf_tests_dir}.\n";
	echo "Set WP_TESTS_DIR, or install it with:\n";
	echo "  bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";

	exit( 1 );
}

require_once $atf_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 *
 * `muplugins_loaded` rather than `plugins_loaded`, so the plugin's own
 * `plugins_loaded` handlers still fire at their proper priority.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/allterrain-forms.php';
	}
);

require $atf_tests_dir . '/includes/bootstrap.php';

/**
 * Reads a shared conformance fixture.
 *
 * The same JSON the Vitest suite reads, so the PHP and TypeScript twins are
 * tested against one table rather than two that can drift.
 *
 * @param string $name Fixture file name, without the extension.
 * @return array The decoded fixture.
 */
function atf_test_fixture( $name ) {
	$path = dirname( __DIR__ ) . '/fixtures/' . $name . '.json';

	if ( ! file_exists( $path ) ) {
		throw new RuntimeException( "Missing fixture: {$path}" );
	}

	$decoded = json_decode( file_get_contents( $path ), true );

	if ( ! is_array( $decoded ) ) {
		throw new RuntimeException( "Unreadable fixture: {$path}" );
	}

	return $decoded;
}

/**
 * Creates a form with a schema, for a test.
 *
 * @param array  $schema A partial schema; normalised on the way in.
 * @param string $title  The form's title.
 * @return int The form id.
 */
function atf_test_form( $schema = array(), $title = 'Test form' ) {
	$form_id = wp_insert_post(
		array(
			'post_type'   => ATF_FORM_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		)
	);

	atf_save_form_schema( $form_id, $schema );

	return $form_id;
}
