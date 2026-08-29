<?php
/**
 * Upload security.
 *
 * The file that lets a stranger put bytes on the server is the one whose
 * failures make headlines, so its guarantees are pinned here rather than left
 * to the integration tests to imply. Two of them answer directly to the
 * Forminator arbitrary-upload class of bug (CVE-2026-15748):
 *
 * 1. What a file is allowed to be is decided by the **stored form**, never by
 *    anything the submission carries. That plugin trusted upload-field
 *    configuration injected through the request; this one reads it from the
 *    schema the form owner saved.
 *
 * 2. The dangerous-extension blocklist matches a single real extension against
 *    a flat set. That plugin matched against pipe-joined MIME keys with exact
 *    comparison, which a crafted `ext|ext` string slipped past.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The upload gate's guarantees.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Uploads extends WP_UnitTestCase {

	/**
	 * Lets `wp_handle_upload()` accept a file the CLI created.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter(
			'alltfo_upload_overrides',
			static function ( $overrides ) {
				$overrides['action'] = 'wp_handle_sideload';

				return $overrides;
			}
		);
	}

	/**
	 * A `$_FILES` entry for a file the test wrote.
	 *
	 * @param string $name     The filename.
	 * @param string $contents The bytes.
	 * @return array
	 */
	private function fake_upload( $name, $contents ) {
		$tmp = tempnam( get_temp_dir(), 'atf' );

		file_put_contents( $tmp, $contents );

		return array(
			'name'     => $name,
			'type'     => '',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $contents ),
		);
	}

	/**
	 * Every executable extension is refused, whatever the field allows.
	 *
	 * The blocklist runs before the per-field allow-list and before the byte
	 * check, and it is not filterable — so even a field that named `.php` in
	 * its own accepted types could not store one. Each variant here is a real
	 * way to spell "run this on the server"; a gap in this list is the whole
	 * site.
	 *
	 * @covers ::alltfo_store_uploaded_file
	 */
	public function test_executable_extensions_are_always_refused() {
		$dangerous = array(
			'shell.php',
			'shell.php5',
			'shell.php6',
			'shell.php7',
			'shell.pht',
			'shell.phtm',
			'shell.phtml',
			'shell.phar',
			'shell.phps',
			'shell.shtml',
			'shell.htaccess',
			'shell.svg',
			'shell.exe',
			'shell.jsp',
			'shell.js',
		);

		// A field that names the dangerous extension itself, to prove the
		// blocklist overrides the allow-list rather than deferring to it.
		$field = array(
			'id'        => 'f1',
			'type'      => 'file',
			'label'     => 'Attachment',
			'filetypes' => array( 'php', 'pht', 'phtml', 'svg', 'exe', 'jsp', 'js', 'phar', 'shtml' ),
		);

		foreach ( $dangerous as $name ) {
			$result = alltfo_store_uploaded_file(
				$this->fake_upload( $name, "<?php echo 'pwned'; ?>" ),
				$field,
				0
			);

			$this->assertWPError( $result, "{$name} must be refused." );
			$this->assertSame( 'alltfo_file_type', $result->get_error_code(), "{$name} is refused as a bad type." );
		}
	}

	/**
	 * A double extension does not smuggle a script past the gate.
	 *
	 * `evil.php.jpg` has a final extension of `jpg`; the byte check then finds
	 * the contents are not an image and refuses it, so the name trick buys
	 * nothing.
	 *
	 * @covers ::alltfo_store_uploaded_file
	 */
	public function test_double_extension_is_caught_by_the_byte_check() {
		$field = array(
			'id'        => 'f1',
			'type'      => 'file',
			'label'     => 'Attachment',
			'filetypes' => array( 'jpg', 'jpeg', 'png' ),
		);

		$result = alltfo_store_uploaded_file(
			$this->fake_upload( 'evil.php.jpg', "<?php echo 'pwned'; ?>" ),
			$field,
			0
		);

		$this->assertWPError( $result );
		$this->assertSame( 'alltfo_file_type', $result->get_error_code() );
	}

	/**
	 * What a file may be is the stored form's call, not the request's.
	 *
	 * This is the Forminator lesson in one assertion: the accepted types come
	 * from the field the form owner saved. A submission that ships its own
	 * `filetypes` alongside the file changes nothing — the handler never reads
	 * upload configuration from the request, only the bytes.
	 *
	 * @covers ::alltfo_handle_uploads
	 */
	public function test_accepted_types_come_from_the_schema_not_the_request() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'        => 'cv',
						'type'      => 'file',
						'label'     => 'Your CV',
						'filetypes' => array( 'pdf' ),
					),
				),
			)
		);

		// The attacker sends a PHP file for the field *and* a forged config
		// claiming php is allowed. Only the schema is consulted.
		$files = array(
			'alltfo_file_cv' => $this->fake_upload( 'shell.php', "<?php echo 'pwned'; ?>" ),
		);

		$_POST['cv']        = array( 'filetypes' => array( 'php' ) );
		$_POST['filetypes'] = array( 'php' );

		$result = alltfo_handle_uploads( $schema, $files, 0 );

		unset( $_POST['cv'], $_POST['filetypes'] );

		$this->assertArrayHasKey( 'cv', $result['errors'], 'The forged config did not widen what the field accepts.' );
		$this->assertSame( array(), $result['values'], 'Nothing was stored.' );
	}

	/**
	 * A file that matches the stored allow-list and its own bytes is stored.
	 *
	 * The gate refuses the dangerous and accepts the ordinary; a gate that only
	 * ever says no is not being tested, it is broken.
	 *
	 * @covers ::alltfo_store_uploaded_file
	 */
	public function test_a_legitimate_file_is_accepted() {
		$field = array(
			'id'        => 'f1',
			'type'      => 'file',
			'label'     => 'Attachment',
			'filetypes' => array( 'txt' ),
		);

		$result = alltfo_store_uploaded_file(
			$this->fake_upload( 'notes.txt', 'Just some plain text.' ),
			$field,
			0
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 'private', get_post_status( $result ), 'Form uploads are private, never public in the media library.' );

		wp_delete_attachment( $result, true );
	}
}
