<?php
/**
 * Entries, export and uploads.
 *
 * The three places where getting it wrong costs somebody else something: reading
 * entries they should not see, exporting a CSV that executes on open, and
 * accepting an upload that executes on the server.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Entries, export, uploads and retention.
 *
 * @group allterrain-forms
 */
class ATF_Test_Entries extends WP_UnitTestCase {

	/**
	 * A form and one stored entry.
	 *
	 * @return array { form_id: int, entry_id: int }
	 */
	private function seed() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
					array(
						'id'    => 'f2',
						'type'  => 'email',
						'label' => 'Email',
					),
				),
			)
		);

		$schema = atf_get_form_schema( $form_id );

		$entry_id = atf_store_entry(
			$form_id,
			$schema,
			array(
				'f1' => 'Ada Lovelace',
				'f2' => 'ada@example.com',
			)
		);

		return compact( 'form_id', 'entry_id' );
	}

	/**
	 * Reading an entry requires the capability.
	 *
	 * @covers ::atf_prepare_entry
	 */
	public function test_reading_an_entry_requires_capability() {
		$seeded = $this->seed();

		wp_set_current_user( 0 );

		$this->assertSame( array(), atf_prepare_entry( $seeded['entry_id'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), atf_prepare_entry( $seeded['entry_id'] ) );

		// Capabilities first, *then* the user.
		//
		// `WP_User` computes `allcaps` when it is instantiated, so a capability
		// added to a role afterwards is invisible to the user already in
		// `$current_user`. Every other test in this file gets away with the
		// opposite order only because `$GLOBALS['wp_roles']` survives the
		// per-test transaction rollback — so by the time they run, some earlier
		// test has already granted the cap. This one runs first and had no such
		// help. On a real site the ordering is never in question: activation adds
		// the capabilities long before anybody logs in.
		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'Ada Lovelace', atf_prepare_entry( $seeded['entry_id'] )['values']['f1'] );
	}

	/**
	 * A query by somebody who may not read entries returns nothing.
	 *
	 * @covers ::atf_query_entries
	 */
	public function test_querying_requires_capability() {
		$seeded = $this->seed();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = atf_query_entries( array( 'form_id' => $seeded['form_id'] ) );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['entries'] );
	}

	/**
	 * An entry's answers come back formatted as well as raw.
	 *
	 * @covers ::atf_prepare_entry
	 */
	public function test_prepared_entry_shape() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded = $this->seed();
		$entry  = atf_prepare_entry( $seeded['entry_id'] );

		foreach ( array( 'id', 'formId', 'formTitle', 'status', 'date', 'values', 'fields', 'starred', 'canDelete' ) as $key ) {
			$this->assertArrayHasKey( $key, $entry, "A prepared entry is missing {$key}." );
		}

		$this->assertSame( 'Name', $entry['fields'][0]['label'] );
		$this->assertSame( 'Ada Lovelace', $entry['fields'][0]['formatted'] );
	}

	/**
	 * Opening an entry marks it read.
	 *
	 * @covers ::atf_set_entry_status
	 */
	public function test_status_changes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded = $this->seed();

		$this->assertSame( ATF_STATUS_UNREAD, get_post_status( $seeded['entry_id'] ) );

		atf_set_entry_status( $seeded['entry_id'], ATF_STATUS_READ );

		$this->assertSame( ATF_STATUS_READ, get_post_status( $seeded['entry_id'] ) );
	}

	/**
	 * A status that is not one an entry can have is refused.
	 *
	 * @covers ::atf_set_entry_status
	 */
	public function test_bad_status_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded = $this->seed();

		$this->assertWPError( atf_set_entry_status( $seeded['entry_id'], 'publish' ) );
	}

	/**
	 * Starring is only for entries.
	 *
	 * A post that is not an entry has no form id, and a missing form id reads
	 * as 0 -- "any form" -- which slipped past a per-form read filter and let
	 * the star meta land on arbitrary posts.
	 *
	 * @covers ::atf_star_entry
	 */
	public function test_starring_requires_an_entry() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded  = $this->seed();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertWPError( atf_star_entry( $page_id, true ), 'A page is not an entry.' );
		$this->assertSame( '', get_post_meta( $page_id, '_atf_starred', true ) );

		$this->assertWPError( atf_star_entry( 0, true ), 'Nothing is not an entry either.' );

		$this->assertTrue( atf_star_entry( $seeded['entry_id'], true ) );
		$this->assertSame( '1', get_post_meta( $seeded['entry_id'], '_atf_starred', true ) );
	}

	/**
	 * The analytics route's form id reaches the per-form read filter.
	 *
	 * The route names its parameter `id`, because it lives at
	 * `/forms/{id}/analytics` -- and the permission callback used to read only
	 * `form_id`, so the documented `atf_can_read_entries` seam was always
	 * asked about form 0 rather than the form whose numbers were being read.
	 *
	 * @covers ::atf_rest_can_read_entries
	 */
	public function test_analytics_permission_asks_about_the_right_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded = $this->seed();
		$asked  = array();

		$filter = static function ( $can, $form_id ) use ( &$asked, $seeded ) {
			$asked[] = $form_id;

			return $seeded['form_id'] === $form_id ? false : $can;
		};

		add_filter( 'atf_can_read_entries', $filter, 10, 2 );

		$request = new WP_REST_Request( 'GET', '/' . ATF_REST_NAMESPACE . '/forms/' . $seeded['form_id'] . '/analytics' );
		$request->set_param( 'id', $seeded['form_id'] );

		$result = atf_rest_can_read_entries( $request );

		// An entry route's `id` is an entry, not a form, and must stay out of
		// the per-form question.
		$entry_request = new WP_REST_Request( 'GET', '/' . ATF_REST_NAMESPACE . '/entries/' . $seeded['entry_id'] );
		$entry_request->set_param( 'id', $seeded['entry_id'] );

		$entry_result = atf_rest_can_read_entries( $entry_request );

		remove_filter( 'atf_can_read_entries', $filter );

		$this->assertWPError( $result, 'Denying the form must deny its analytics.' );
		$this->assertContains( $seeded['form_id'], $asked, 'The filter must be asked about the form being read.' );

		$this->assertTrue( $entry_result );
		$this->assertNotContains( $seeded['entry_id'], $asked, 'An entry id must never be mistaken for a form id.' );
	}

	/* ---------------------------------------------------------------- Export */

	/**
	 * A CSV export has a header row and the answers under it.
	 *
	 * @covers ::atf_export_entries_csv
	 */
	public function test_export_has_headers_and_rows() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		atf_add_capabilities();

		$seeded = $this->seed();
		$csv    = atf_export_entries_csv( $seeded['form_id'] );

		$this->assertIsString( $csv );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'A BOM-less UTF-8 CSV is mojibake in Excel.' );
		$this->assertStringContainsString( 'Name', $csv );
		$this->assertStringContainsString( 'Ada Lovelace', $csv );
	}

	/**
	 * A formula in an answer cannot execute when the CSV is opened.
	 *
	 * CSV injection turns "fill in this form" into "run this on the site owner's
	 * machine", and it is the reason exports need this at all.
	 *
	 * @dataProvider data_csv_attacks
	 * @covers ::atf_sanitize_csv_cell
	 *
	 * @param string $value A value that must be defused.
	 */
	public function test_csv_injection_is_defused( $value ) {
		$cell = atf_sanitize_csv_cell( $value );

		$this->assertStringStartsWith( "'", $cell, sprintf( '%s was not defused.', $value ) );
	}

	/**
	 * The shapes a spreadsheet executes.
	 *
	 * @return array[]
	 */
	public function data_csv_attacks() {
		$values = array(
			'=1+1',
			'=cmd|\' /C calc\'!A0',
			'+1+1',
			'-1+1',
			'@SUM(1:2)',
			"\t=1+1",
			"\r=1+1",
		);

		$cases = array();

		foreach ( $values as $value ) {
			$cases[ $value ] = array( $value );
		}

		return $cases;
	}

	/**
	 * An ordinary answer is not mangled.
	 *
	 * @covers ::atf_sanitize_csv_cell
	 */
	public function test_ordinary_cells_survive() {
		foreach ( array( 'Ada Lovelace', 'ada@example.com', '42', 'a, b, c', '' ) as $value ) {
			$this->assertSame( $value, atf_sanitize_csv_cell( $value ) );
		}
	}

	/**
	 * Exporting requires the capability.
	 *
	 * @covers ::atf_export_entries_csv
	 */
	public function test_export_requires_capability() {
		$seeded = $this->seed();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertWPError( atf_export_entries_csv( $seeded['form_id'] ) );
	}

	/**
	 * A password column never appears in an export.
	 *
	 * @covers ::atf_export_columns
	 */
	public function test_export_omits_passwords() {
		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'    => 'u',
						'type'  => 'text',
						'label' => 'Username',
					),
					array(
						'id'    => 'p',
						'type'  => 'password',
						'label' => 'Password',
					),
				),
			)
		);

		$columns = atf_export_columns( $schema );

		$this->assertArrayHasKey( 'field:u', $columns );
		$this->assertArrayNotHasKey( 'field:p', $columns );
	}

	/* --------------------------------------------------------------- Uploads */

	/**
	 * Nothing the web server might execute is ever an accepted extension.
	 *
	 * @dataProvider data_dangerous_extensions
	 * @covers ::ATF_FORBIDDEN_EXTENSIONS
	 *
	 * @param string $extension An extension that must be forbidden.
	 */
	public function test_executable_extensions_are_forbidden( $extension ) {
		$this->assertContains(
			$extension,
			ATF_FORBIDDEN_EXTENSIONS,
			sprintf( '".%s" must never be an accepted upload.', $extension )
		);
	}

	/**
	 * Extensions a compromise is built from.
	 *
	 * @return array[]
	 */
	public function data_dangerous_extensions() {
		$extensions = array( 'php', 'php5', 'phtml', 'phar', 'htaccess', 'js', 'html', 'svg', 'sh', 'exe', 'jsp', 'asp' );

		$cases = array();

		foreach ( $extensions as $extension ) {
			$cases[ $extension ] = array( $extension );
		}

		return $cases;
	}

	/**
	 * A file field refuses an executable even when the form lists one.
	 *
	 * @covers ::atf_store_uploaded_file
	 */
	public function test_upload_refuses_executables() {
		$field = atf_normalize_field(
			array(
				'id'        => 'f1',
				'type'      => 'file',
				// A form deliberately configured to accept PHP. The forbidden
				// list must win regardless.
				'filetypes' => array( 'php', 'jpg' ),
			)
		);

		$tmp = wp_tempnam( 'atf-test' );

		file_put_contents( $tmp, "<?php echo 'pwned'; ?>" );

		$result = atf_store_uploaded_file(
			array(
				'name'     => 'payload.php',
				'type'     => 'application/x-php',
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $tmp ),
			),
			$field,
			0
		);

		$this->assertWPError( $result );

		@unlink( $tmp );
	}

	/**
	 * A file whose bytes disagree with its name is refused.
	 *
	 * `payload.php` renamed to `photo.jpg` is the oldest upload attack there is.
	 *
	 * @covers ::atf_store_uploaded_file
	 */
	public function test_upload_refuses_a_disguised_file() {
		$field = atf_normalize_field(
			array(
				'id'        => 'f1',
				'type'      => 'file',
				'filetypes' => array( 'jpg', 'png' ),
			)
		);

		$tmp = wp_tempnam( 'atf-test' );

		file_put_contents( $tmp, "<?php echo 'pwned'; ?>" );

		$result = atf_store_uploaded_file(
			array(
				'name'     => 'photo.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $tmp ),
			),
			$field,
			0
		);

		$this->assertWPError( $result, 'A PHP file wearing a .jpg name was accepted.' );

		@unlink( $tmp );
	}

	/**
	 * A file over the field's size limit is refused.
	 *
	 * @covers ::atf_store_uploaded_file
	 */
	public function test_upload_size_limit() {
		$field = atf_normalize_field(
			array(
				'id'        => 'f1',
				'type'      => 'file',
				'filetypes' => array( 'txt' ),
				'maxsize'   => 1,
			)
		);

		$result = atf_store_uploaded_file(
			array(
				'name'     => 'big.txt',
				'type'     => 'text/plain',
				'tmp_name' => '/tmp/does-not-matter',
				'error'    => UPLOAD_ERR_OK,
				'size'     => 5 * MB_IN_BYTES,
			),
			$field,
			0
		);

		$this->assertWPError( $result );
		$this->assertSame( 'atf_file_too_big', $result->get_error_code() );
	}

	/**
	 * Uploaded files get an unguessable name on disk.
	 *
	 * The original name is attacker-controlled, and a guessable one undoes the
	 * directory protection for anyone who thinks to try.
	 *
	 * @covers ::atf_unique_upload_filename
	 */
	public function test_upload_filenames_are_unguessable() {
		// A stem made of characters the generator cannot produce.
		//
		// This used to pass `cv.pdf` and assert the result did not contain `cv`.
		// The generated name is 24 random alphanumerics, so `cv` turns up in one
		// run in about 170 by chance — and it did, which is how a green suite
		// produced a red one with nothing changed. An assertion that is a coin
		// flip does not test the property; it tests the seed.
		//
		// Accents, spaces and brackets are outside the generator's alphabet, so
		// "none of the original stem survives" becomes something that either
		// holds or does not.
		$name   = 'mi currículum (final) v2.pdf';
		$first  = atf_unique_upload_filename( '/tmp', $name, '.pdf' );
		$second = atf_unique_upload_filename( '/tmp', $name, '.pdf' );

		$this->assertNotSame( $first, $second, 'Two uploads of the same file must not collide.' );

		// Structural, and the real statement: the whole name is the generated
		// token plus the extension, with nothing of the original in between.
		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9]{24}\.pdf$/',
			$first,
			'A stored upload name must be a random token plus the extension, and nothing else.'
		);

		foreach ( array( 'currículum', 'final', 'v2', ' ', '(' ) as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$first,
				sprintf( '"%s" survived from the original filename into the stored one.', $fragment )
			);
		}
	}

	/**
	 * The uploads directory is protected against being served.
	 *
	 * @covers ::atf_protect_upload_directory
	 */
	public function test_upload_directory_is_protected() {
		$directory = get_temp_dir() . 'atf-protect-test-' . wp_rand( 1000, 9999 );

		atf_protect_upload_directory( $directory );

		$this->assertFileExists( $directory . '/.htaccess' );
		$this->assertFileExists( $directory . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $directory . '/.htaccess' ) );

		@unlink( $directory . '/.htaccess' );
		@unlink( $directory . '/index.php' );
		@rmdir( $directory );
	}

	/* ------------------------------------------------------------- Retention */

	/**
	 * Retention deletes entries past their form's policy, and nothing else.
	 *
	 * @covers ::atf_apply_retention
	 */
	public function test_retention_only_takes_the_old() {
		$form_id = atf_test_form(
			array(
				'fields'   => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
				'settings' => array(
					'storage' => array( 'retention' => 30 ),
				),
			)
		);

		$schema = atf_get_form_schema( $form_id );

		$old = atf_store_entry( $form_id, $schema, array( 'f1' => 'ancient' ) );
		$new = atf_store_entry( $form_id, $schema, array( 'f1' => 'recent' ) );

		wp_update_post(
			array(
				'ID'            => $old,
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
			)
		);

		atf_apply_retention();

		$this->assertNull( get_post( $old ), 'An entry past its retention period should be gone.' );
		$this->assertNotNull( get_post( $new ), 'A recent entry must survive.' );
	}

	/**
	 * A retention of zero keeps everything.
	 *
	 * A plugin must not start deleting somebody's data on a schedule they did
	 * not choose.
	 *
	 * @covers ::atf_apply_retention
	 */
	public function test_retention_defaults_to_forever() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$entry_id = atf_store_entry( $form_id, atf_get_form_schema( $form_id ), array( 'f1' => 'keep me' ) );

		wp_update_post(
			array(
				'ID'            => $entry_id,
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 3650 * DAY_IN_SECONDS ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 3650 * DAY_IN_SECONDS ) ),
			)
		);

		atf_apply_retention();

		$this->assertNotNull( get_post( $entry_id ) );
	}

	/**
	 * `'post_status' => 'any'` does not reach an entry, and the helper does.
	 *
	 * This is the trap behind two silent failures. Entry statuses are all
	 * registered `exclude_from_search`, deliberately, so that no theme, feed or
	 * site search can surface somebody's submission — and `'any'` means "every
	 * status *not* excluded from search". A query written as `'any'` therefore
	 * matches no entry at all: it finds nothing, throws nothing, and returns
	 * success.
	 *
	 * That is how the retention sweep deleted nothing on sites that had asked for
	 * a retention policy, and how a privacy export came back empty for somebody
	 * who had submitted a dozen forms. Both look exactly like working code.
	 *
	 * Asserting the *broken* behaviour alongside the fixed one is the point: the
	 * day `'any'` starts working, this test says so, and the helper can go.
	 *
	 * @covers ::atf_entry_statuses
	 */
	public function test_entry_queries_must_name_their_statuses() {
		$seeded = $this->seed();

		$with_any = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertNotContains(
			$seeded['entry_id'],
			$with_any,
			"If `'any'` has started matching entries, WordPress changed under us — "
				. 'check whether atf_entry_statuses() is still needed before trusting this.'
		);

		$named = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => atf_entry_statuses(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertContains( $seeded['entry_id'], $named, 'Naming the statuses must find the entry.' );
	}

	/**
	 * Every registered entry status is one the helper returns.
	 *
	 * A status added later and not added here would be invisible to the retention
	 * sweep and to privacy requests — the same silent failure, one status at a
	 * time.
	 *
	 * @covers ::atf_entry_statuses
	 */
	public function test_the_helper_covers_every_entry_status() {
		$missing = array_diff(
			array( ATF_STATUS_UNREAD, ATF_STATUS_READ, ATF_STATUS_SPAM, ATF_STATUS_PARTIAL ),
			atf_entry_statuses()
		);

		$this->assertSame( array(), $missing, 'Statuses missing from atf_entry_statuses(): ' . implode( ', ', $missing ) );
	}

	/**
	 * A privacy export finds the entries it is asked about.
	 *
	 * The export query carried the same `'any'` and returned nothing, which is
	 * indistinguishable from "this person has submitted nothing" — a wrong answer
	 * to a legal request.
	 *
	 * @covers ::atf_find_entries_for_email
	 */
	public function test_a_privacy_export_finds_an_entry() {
		$form_id = atf_test_form(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'email',
					),
				),
			)
		);

		$schema   = atf_get_form_schema( $form_id );
		$entry_id = atf_store_entry( $form_id, $schema, array( 'f1' => 'ada@example.com' ) );

		$found = atf_find_entries_for_email( 'ada@example.com' );

		$this->assertContains(
			$entry_id,
			wp_list_pluck( $found['entries'], 'ID' ),
			'A person asking what you hold about them must be told about their entries.'
		);
	}
}
