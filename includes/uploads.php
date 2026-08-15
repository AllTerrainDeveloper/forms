<?php
/**
 * File uploads.
 *
 * A form that accepts uploads is a form that lets a stranger put a file on your
 * server, which makes this the most dangerous file in the plugin. Four things
 * stand between that and a compromised site, and all four are here.
 *
 * **The extension whitelist is per field and re-checked here.** What the browser
 * sent in `accept` is a hint to the file picker and nothing more.
 *
 * **The MIME type must agree with the extension.** `wp_check_filetype_and_ext()`
 * reads the file's actual bytes, so `payload.php` renamed to `photo.jpg` is
 * refused rather than stored.
 *
 * **Executable extensions are refused unconditionally**, even if a form somehow
 * lists one. There is no legitimate form that needs to accept `.php`, and the
 * cost of getting this wrong is the whole site.
 *
 * **Files land in a directory with no index and a deny rule**, so even a file
 * that got past everything above is not reachable by URL.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extensions that are never accepted, whatever a form says.
 *
 * Anything the web server might execute, plus the wrappers that commonly smuggle
 * it. This list is not filterable, deliberately: every site that has ever been
 * compromised through an upload form had somebody who thought their case was the
 * exception.
 *
 * @since 0.1.0
 */
const ATF_FORBIDDEN_EXTENSIONS = array(
	'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
	'pl', 'py', 'rb', 'cgi', 'sh', 'bash', 'zsh',
	'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'dll', 'so',
	'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx',
	'htaccess', 'htpasswd', 'ini', 'conf',
	'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'swf', 'jar',
);

/**
 * Handles every uploaded file in a submission.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $files   The `$_FILES` array.
 * @param int   $form_id The form.
 * @return array { values: array<string, int[]>, errors: array<string, string> }
 */
function atf_handle_uploads( $schema, $files, $form_id ) {
	$values = array();
	$errors = array();

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( 'file' !== $field['type'] ) {
			continue;
		}

		$key = 'atf_file_' . $field['id'];

		if ( ! isset( $files[ $key ] ) ) {
			continue;
		}

		$result = atf_handle_field_upload( $field, $files[ $key ], $form_id );

		if ( is_wp_error( $result ) ) {
			$errors[ $field['id'] ] = $result->get_error_message();
			continue;
		}

		if ( $result ) {
			$values[ $field['id'] ] = $result;
		}
	}

	return array(
		'values' => $values,
		'errors' => $errors,
	);
}

/**
 * Handles the uploads for one field.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param array $entry   That field's slice of `$_FILES`.
 * @param int   $form_id The form.
 * @return int[]|WP_Error Attachment ids, or the first failure.
 */
function atf_handle_field_upload( $field, $entry, $form_id ) {
	$files = atf_normalize_files_entry( $entry );
	$max   = isset( $field['maxfiles'] ) ? max( 1, absint( $field['maxfiles'] ) ) : 1;
	$ids   = array();

	if ( count( $files ) > $max ) {
		return new WP_Error(
			'atf_too_many_files',
			sprintf(
				/* translators: %d: maximum number of files. */
				_n( 'Attach at most %d file.', 'Attach at most %d files.', $max, 'allterrain-forms' ),
				$max
			)
		);
	}

	foreach ( $files as $file ) {
		if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
			continue;
		}

		$result = atf_store_uploaded_file( $file, $field, $form_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ids[] = $result;
	}

	return $ids;
}

/**
 * Turns PHP's two `$_FILES` shapes into one list of files.
 *
 * A single input gives `{ name: 'a.jpg', … }`; a `multiple` input gives
 * `{ name: [ 'a.jpg', 'b.jpg' ], … }`. Every caller wants the second shape.
 *
 * @since 0.1.0
 *
 * @param array $entry One field's `$_FILES` entry.
 * @return array[] One array per file.
 */
function atf_normalize_files_entry( $entry ) {
	if ( ! isset( $entry['name'] ) ) {
		return array();
	}

	if ( ! is_array( $entry['name'] ) ) {
		return array( $entry );
	}

	$files = array();
	$count = count( $entry['name'] );

	for ( $i = 0; $i < $count; $i++ ) {
		$files[] = array(
			'name'     => $entry['name'][ $i ],
			'type'     => isset( $entry['type'][ $i ] ) ? $entry['type'][ $i ] : '',
			'tmp_name' => isset( $entry['tmp_name'][ $i ] ) ? $entry['tmp_name'][ $i ] : '',
			'error'    => isset( $entry['error'][ $i ] ) ? $entry['error'][ $i ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $entry['size'][ $i ] ) ? $entry['size'][ $i ] : 0,
		);
	}

	return $files;
}

/**
 * Validates and stores one uploaded file.
 *
 * @since 0.1.0
 *
 * @param array $file    One entry from `$_FILES`.
 * @param array $field   The field it was uploaded for.
 * @param int   $form_id The form.
 * @return int|WP_Error The attachment id, or why it was refused.
 */
function atf_store_uploaded_file( $file, $field, $form_id ) {
	if ( UPLOAD_ERR_OK !== $file['error'] ) {
		return new WP_Error( 'atf_upload_failed', atf_upload_error_message( $file['error'] ) );
	}

	$max_bytes = ( isset( $field['maxsize'] ) ? absint( $field['maxsize'] ) : 10 ) * MB_IN_BYTES;

	if ( $file['size'] > $max_bytes ) {
		return new WP_Error(
			'atf_file_too_big',
			sprintf(
				/* translators: %s: maximum file size, already formatted. */
				__( 'That file is too big. The limit is %s.', 'allterrain-forms' ),
				size_format( $max_bytes )
			)
		);
	}

	$name      = sanitize_file_name( $file['name'] );
	$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

	if ( '' === $extension || in_array( $extension, ATF_FORBIDDEN_EXTENSIONS, true ) ) {
		return new WP_Error( 'atf_file_type', __( 'That kind of file cannot be uploaded.', 'allterrain-forms' ) );
	}

	$allowed = isset( $field['filetypes'] ) && is_array( $field['filetypes'] ) ? $field['filetypes'] : array();
	$allowed = array_map(
		static function ( $type ) {
			return strtolower( ltrim( trim( (string) $type ), '.' ) );
		},
		$allowed
	);

	if ( $allowed && ! in_array( $extension, $allowed, true ) ) {
		return new WP_Error(
			'atf_file_type',
			sprintf(
				/* translators: %s: comma-separated list of accepted extensions. */
				__( 'That kind of file is not accepted here. Try: %s.', 'allterrain-forms' ),
				implode( ', ', $allowed )
			)
		);
	}

	// The bytes have to agree with the name. `wp_check_filetype_and_ext()` reads
	// the file itself, which is what catches an executable wearing a `.jpg`.
	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $name );

	if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
		return new WP_Error( 'atf_file_type', __( 'That file does not look like the kind of file it claims to be.', 'allterrain-forms' ) );
	}

	if ( strtolower( $checked['ext'] ) !== $extension ) {
		return new WP_Error( 'atf_file_type', __( 'That file does not look like the kind of file it claims to be.', 'allterrain-forms' ) );
	}

	$overrides = array(
		'test_form' => false,
		'unique_filename_callback' => 'atf_unique_upload_filename',
		// `mimes` is scoped to what this field allows, so `wp_handle_upload()`
		// refuses anything outside it even if the checks above were somehow
		// bypassed by a filter.
		'mimes'     => atf_allowed_mimes_for( $allowed ),
	);

	add_filter( 'upload_dir', 'atf_upload_directory' );

	$moved = wp_handle_upload( $file, $overrides );

	remove_filter( 'upload_dir', 'atf_upload_directory' );

	if ( isset( $moved['error'] ) ) {
		return new WP_Error( 'atf_upload_failed', (string) $moved['error'] );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			// Not `inherit`. An attachment in `inherit` status is public, and
			// these are somebody's CV or their proof of address. Private keeps
			// them out of the media library's public queries and out of search.
			'post_status'    => 'private',
		),
		$moved['file']
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $moved['file'] );

		return $attachment_id;
	}

	// Marked so the retention sweep and the privacy eraser can find every file
	// that arrived through a form without walking every entry.
	update_post_meta( $attachment_id, '_atf_upload', 1 );
	update_post_meta( $attachment_id, ATF_META_FORM, absint( $form_id ) );

	require_once ABSPATH . 'wp-admin/includes/image.php';

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

	/**
	 * Fires after a form upload is stored.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $attachment_id The attachment.
	 * @param array $field         The field it came from.
	 * @param int   $form_id       The form.
	 */
	do_action( 'atf_file_uploaded', $attachment_id, $field, $form_id );

	return $attachment_id;
}

/**
 * The MIME map an upload is allowed to match.
 *
 * Narrowed to the field's own extension list where it has one, so a field that
 * accepts images cannot be used to store a zip.
 *
 * @since 0.1.0
 *
 * @param string[] $extensions Allowed extensions, without dots.
 * @return array<string, string> Extension pattern => MIME type.
 */
function atf_allowed_mimes_for( $extensions ) {
	$all = get_allowed_mime_types();

	if ( ! $extensions ) {
		return $all;
	}

	$mimes = array();

	foreach ( $all as $pattern => $mime ) {
		foreach ( explode( '|', $pattern ) as $extension ) {
			if ( in_array( $extension, $extensions, true ) ) {
				$mimes[ $pattern ] = $mime;
				break;
			}
		}
	}

	return $mimes;
}

/**
 * Where form uploads are stored.
 *
 * A dated directory under `uploads/allterrain-forms/`, kept apart from the media
 * library so a retention sweep can delete a year of submissions without going
 * anywhere near the site's own images.
 *
 * @since 0.1.0
 *
 * @param array $dirs The upload directory array.
 * @return array
 */
function atf_upload_directory( $dirs ) {
	$sub = '/allterrain-forms/' . gmdate( 'Y/m' );

	$dirs['subdir'] = $sub;
	$dirs['path']   = $dirs['basedir'] . $sub;
	$dirs['url']    = $dirs['baseurl'] . $sub;

	atf_protect_upload_directory( $dirs['basedir'] . '/allterrain-forms' );

	return $dirs;
}

/**
 * Puts a deny rule and an index file in the uploads directory.
 *
 * Belt and braces on top of the `private` attachment status. The `.htaccess`
 * covers Apache; the empty `index.php` stops directory listing anywhere; nginx
 * needs a server-level rule, which the readme documents because a plugin cannot
 * write one.
 *
 * @since 0.1.0
 *
 * @param string $directory Absolute path.
 * @return void
 */
function atf_protect_upload_directory( $directory ) {
	if ( ! wp_mkdir_p( $directory ) ) {
		return;
	}

	$htaccess = trailingslashit( $directory ) . '.htaccess';

	if ( ! file_exists( $htaccess ) ) {
		$rules = "# Added by AllTerrain Forms.\n"
			. "# Uploads submitted through a form are served by PHP after a capability check,\n"
			. "# never directly. Nothing in here should be reachable by URL.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

		file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not initialised on a front-end submission and this must not prompt for credentials.
	}

	$index = trailingslashit( $directory ) . 'index.php';

	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- As above.
	}
}

/**
 * Gives an uploaded file a name that reveals nothing.
 *
 * The original name is kept as the attachment's title, so the entry still shows
 * "Jane's CV.pdf"; the file on disk is a hash. Two reasons: the original name is
 * attacker-controlled, and a guessable filename undoes the directory protection
 * for anyone who thinks to try.
 *
 * @since 0.1.0
 *
 * @param string $directory Directory the file is going into.
 * @param string $name      The sanitised name.
 * @param string $extension The extension, with its dot.
 * @return string
 */
function atf_unique_upload_filename( $directory, $name, $extension ) {
	return wp_generate_password( 24, false, false ) . $extension;
}

/**
 * A readable message for a PHP upload error code.
 *
 * @since 0.1.0
 *
 * @param int $code One of the `UPLOAD_ERR_*` constants.
 * @return string
 */
function atf_upload_error_message( $code ) {
	switch ( $code ) {
		case UPLOAD_ERR_INI_SIZE:
		case UPLOAD_ERR_FORM_SIZE:
			return __( 'That file is bigger than this site accepts.', 'allterrain-forms' );

		case UPLOAD_ERR_PARTIAL:
			return __( 'That file only partly uploaded. Please try again.', 'allterrain-forms' );

		case UPLOAD_ERR_NO_TMP_DIR:
		case UPLOAD_ERR_CANT_WRITE:
			return __( 'The file could not be saved. Please tell the site owner.', 'allterrain-forms' );

		case UPLOAD_ERR_EXTENSION:
			return __( 'That file was rejected by the server.', 'allterrain-forms' );

		default:
			return __( 'That file could not be uploaded.', 'allterrain-forms' );
	}
}
