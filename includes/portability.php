<?php
/**
 * Versioned portable form packages. YAML is a transport; this boundary accepts
 * the decoded JSON model and validates it again before writing anything.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

const ALLTFO_PACKAGE_VERSION   = 1;
const ALLTFO_PACKAGE_MAX_BYTES = 16777216;

/**
 * Reads the public JSON Schema shared with the builder and CLI validator.
 *
 * @return array JSON Schema.
 */
function alltfo_package_schema() {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled local contract, never a URL.
	return json_decode( file_get_contents( ALLTFO_DIR . 'schemas/form-package-v1.schema.json' ), true );
}

/**
 * Validates a node against our JSON Schema subset without coercing values.
 *
 * WordPress's REST validator deliberately accepts numeric strings. A portable
 * document must distinguish false from "false" and preserve numeric IDs.
 *
 * @param mixed  $value Value to check.
 * @param array  $shape Node schema.
 * @param array  $root  Root schema for local references.
 * @param string $path  Human-readable error path.
 * @param int    $depth Nesting depth.
 * @return true|WP_Error
 */
function alltfo_package_validate_node( $value, $shape, $root, $path = 'package', $depth = 0 ) {
	if ( $depth > 32 ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': document is nested too deeply.', array( 'status' => 400 ) );
	}
	if ( isset( $shape['$ref'] ) ) {
		$shape = $root['definitions'][ basename( $shape['$ref'] ) ];
	}
	$type = isset( $shape['type'] ) ? $shape['type'] : '';
	$list = is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
	$ok   = '' === $type ||
		( 'object' === $type && ( is_object( $value ) || ( is_array( $value ) && ( ! $list || array() === $value ) ) ) ) ||
		( 'array' === $type && $list ) ||
		( 'string' === $type && is_string( $value ) ) ||
		( 'boolean' === $type && is_bool( $value ) ) ||
		( 'integer' === $type && ( is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value ) ) ) ||
		( 'number' === $type && ( is_int( $value ) || is_float( $value ) ) );
	if ( ! $ok ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': expected ' . $type . '.', array( 'status' => 400 ) );
	}
	if ( isset( $shape['enum'] ) && ! in_array( $value, $shape['enum'], true ) ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': unsupported value or version.', array( 'status' => 400 ) );
	}
	if ( is_string( $value ) && ( ( isset( $shape['minLength'] ) && strlen( $value ) < $shape['minLength'] ) || ( isset( $shape['maxLength'] ) && strlen( $value ) > $shape['maxLength'] ) || ( isset( $shape['pattern'] ) && ! preg_match( '~' . $shape['pattern'] . '~u', $value ) ) ) ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': invalid string.', array( 'status' => 400 ) );
	}
	if ( ( is_int( $value ) || is_float( $value ) ) && ( ! is_finite( (float) $value ) || abs( $value ) > 9007199254740991 || ( isset( $shape['minimum'] ) && $value < $shape['minimum'] ) ) ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': number is out of range.', array( 'status' => 400 ) );
	}
	if ( ! is_array( $value ) && ! is_object( $value ) ) {
		return true;
	}
	$value = (array) $value;
	foreach ( isset( $shape['required'] ) ? $shape['required'] : array() as $key ) {
		if ( ! array_key_exists( $key, $value ) ) {
			return new WP_Error( 'alltfo_package_invalid', $path . '.' . $key . ': required.', array( 'status' => 400 ) );
		}
	}
	foreach ( $value as $key => $child ) {
		if ( in_array( (string) $key, array( '__proto__', 'constructor', 'prototype', '<<' ), true ) ) {
			return new WP_Error( 'alltfo_package_invalid', $path . ': unsupported mapping key.', array( 'status' => 400 ) );
		}
		$properties = isset( $shape['properties'] ) ? $shape['properties'] : array();
		$additional = isset( $shape['additionalProperties'] ) ? $shape['additionalProperties'] : true;
		if ( 'object' === $type && ! isset( $properties[ $key ] ) && false === $additional ) {
			return new WP_Error( 'alltfo_package_invalid', $path . '.' . $key . ': unknown property.', array( 'status' => 400 ) );
		}
		$child_shape = 'array' === $type && isset( $shape['items'] ) ? $shape['items'] : ( isset( $properties[ $key ] ) ? $properties[ $key ] : ( is_array( $additional ) ? $additional : array() ) );
		$result      = alltfo_package_validate_node( $child, $child_shape, $root, $path . '.' . $key, $depth + 1 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}

/**
 * Checks supplied values survive normalization, instead of silently fixing typos.
 *
 * @param mixed  $raw   Supplied node.
 * @param mixed  $clean Normalized node.
 * @param string $path  Error path.
 * @return true|WP_Error
 */
function alltfo_package_check_preserved( $raw, $clean, $path ) {
	if ( is_array( $raw ) ) {
		foreach ( $raw as $key => $value ) {
			// The normalizer stores optional field flags only when enabled.
			// Explicit false and an absent flag have the same meaning.
			if ( isset( $raw['id'], $raw['type'] ) && false === $value && in_array( $key, array( 'unique', 'confirm', 'other', 'inline', 'multiple', 'searchable', 'counter' ), true ) && is_array( $clean ) && ! array_key_exists( $key, $clean ) ) {
				continue;
			}
			if ( ! is_array( $clean ) || ! array_key_exists( $key, $clean ) ) {
				return new WP_Error( 'alltfo_package_invalid', $path . '.' . $key . ': unsupported property on this site.', array( 'status' => 400 ) );
			}
			$result = alltfo_package_check_preserved( $value, $clean[ $key ], $path . '.' . $key );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
	// Bounds and numeric field defaults are stored as strings by some types.
	if ( $raw !== $clean && ! ( is_numeric( $raw ) && is_numeric( $clean ) && (string) $raw === (string) $clean ) ) {
		return new WP_Error( 'alltfo_package_invalid', $path . ': value would be changed by sanitization; correct it before importing.', array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Walks nested fields in-place, preserving their order and IDs.
 *
 * @param array    $fields   Fields.
 * @param callable $callback Callback receiving a field by reference.
 * @return void
 */
function alltfo_package_walk_fields( &$fields, $callback ) {
	foreach ( $fields as &$field ) {
		$callback( $field );
		if ( isset( $field['fields'] ) ) {
			alltfo_package_walk_fields( $field['fields'], $callback );
		}
	}
}

/**
 * Validates references within a field scope (including enclosing repeater scope).
 *
 * @param array $fields Fields in this scope.
 * @param array $outer  Enclosing field IDs.
 * @return true|WP_Error
 */
function alltfo_package_validate_fields( $fields, $outer = array() ) {
	$ids = array_column( $fields, 'id' );
	if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
		return new WP_Error( 'alltfo_package_invalid', 'form.schema.fields: duplicate field IDs.', array( 'status' => 400 ) );
	}
	$scope = array_merge( $outer, $ids );
	foreach ( $fields as $field ) {
		if ( ! alltfo_get_field_type( $field['type'] ) ) {
			return new WP_Error( 'alltfo_package_invalid', 'form.schema.fields.' . $field['id'] . ': field type is not installed: ' . $field['type'], array( 'status' => 400 ) );
		}
		foreach ( isset( $field['logic']['rules'] ) ? $field['logic']['rules'] : array() as $rule ) {
			if ( ! in_array( $rule['field'], $scope, true ) ) {
				return new WP_Error( 'alltfo_package_invalid', 'form.schema.fields.' . $field['id'] . ': missing logic field ' . $rule['field'], array( 'status' => 400 ) );
			}
		}
		if ( isset( $field['fields'] ) ) {
			$result = alltfo_package_validate_fields( $field['fields'], $scope );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}
	return true;
}

/**
 * Validates a whole package without writing posts, themes or files.
 *
 * @param mixed $package Decoded package.
 * @return array|WP_Error Normalized package and warnings, or a path-specific error.
 */
function alltfo_validate_form_package( $package ) {
	$json = wp_json_encode( $package );
	if ( false === $json || strlen( $json ) > ALLTFO_PACKAGE_MAX_BYTES ) {
		return new WP_Error( 'alltfo_package_invalid', 'The form package exceeds the 16 MiB limit or is not JSON-compatible.', array( 'status' => 400 ) );
	}
	$contract = alltfo_package_schema();
	$result   = alltfo_package_validate_node( $package, $contract, $contract );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$package = json_decode( $json, true );
	$schema  = $package['form']['schema'];
	foreach ( array(
		'form'  => array( 'title' ),
		'theme' => array( 'label', 'description' ),
	) as $group => $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $package[ $group ][ $key ] ) ) {
				$valid = alltfo_package_check_preserved( $package[ $group ][ $key ], sanitize_text_field( $package[ $group ][ $key ] ), $group . '.' . $key );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
		}
	}
	$result = alltfo_package_validate_fields( $schema['fields'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$clean  = alltfo_normalize_schema( $schema );
	$result = alltfo_package_check_preserved( $schema, $clean, 'form.schema' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	foreach ( array( 'notifications', 'confirmations', 'actions' ) as $group ) {
		$ids = array_column( $clean[ $group ], 'id' );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return new WP_Error( 'alltfo_package_invalid', 'form.schema.' . $group . ': duplicate IDs.', array( 'status' => 400 ) );
		}
		foreach ( $clean[ $group ] as $item ) {
			foreach ( $item['logic']['rules'] as $rule ) {
				if ( ! in_array( $rule['field'], array_column( $clean['fields'], 'id' ), true ) ) {
					return new WP_Error( 'alltfo_package_invalid', 'form.schema.' . $group . ': missing logic field ' . $rule['field'], array( 'status' => 400 ) );
				}
			}
		}
	}
	foreach ( array( $package['theme']['tokens'], $clean['settings']['themeOverrides'] ) as $tokens ) {
		$result = alltfo_package_check_preserved( $tokens, alltfo_sanitize_tokens( $tokens ), 'theme.tokens' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	if ( $package['theme']['slug'] !== $clean['settings']['theme'] || '' === trim( $package['theme']['label'] ) || '' === sanitize_text_field( $package['form']['title'] ) ) {
		return new WP_Error( 'alltfo_package_invalid', 'The theme slug must match form.schema.settings.theme, and form and theme names cannot be empty.', array( 'status' => 400 ) );
	}
	$builtins = alltfo_builtin_themes();
	if ( ! isset( $builtins[ $package['theme']['base'] ] ) ) {
		return new WP_Error( 'alltfo_package_invalid', 'theme.base: this built-in theme is not available on this site.', array( 'status' => 400 ) );
	}
	$assets = array();
	foreach ( $package['assets'] as $asset ) {
		$bytes = base64_decode( $asset['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validate embedded image bytes.
		$info  = false !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed images are reported as validation errors.
		$file  = wp_check_filetype( $asset['filename'], get_allowed_mime_types() );
		if ( isset( $assets[ $asset['id'] ] ) || ! $info || $info['mime'] !== $asset['mime'] || $file['type'] !== $asset['mime'] ) {
			return new WP_Error( 'alltfo_package_invalid', 'assets.' . $asset['id'] . ': duplicate ID, invalid image bytes, or unsupported MIME/extension.', array( 'status' => 400 ) );
		}
		$assets[ $asset['id'] ] = true;
	}
	$missing = array();
	alltfo_package_walk_fields(
		$clean['fields'],
		static function ( $field ) use ( $assets, &$missing ) {
			foreach ( $field['choices'] as $choice ) {
				if ( ! empty( $choice['image'] ) && ! isset( $assets[ $choice['image'] ] ) ) {
					$missing[] = $choice['image'];
				}
			}
		}
	);
	if ( $missing ) {
		return new WP_Error( 'alltfo_package_invalid', 'assets: missing image attachments ' . implode( ', ', $missing ), array( 'status' => 400 ) );
	}
	$pages = array_column( $package['pages'], 'url', 'id' );
	if ( count( $pages ) !== count( $package['pages'] ) ) {
		return new WP_Error( 'alltfo_package_invalid', 'pages: duplicate page IDs.', array( 'status' => 400 ) );
	}
	foreach ( $clean['confirmations'] as $confirmation ) {
		if ( 'page' === $confirmation['type'] && ( empty( $pages[ $confirmation['pageId'] ] ) || ! in_array( wp_parse_url( $pages[ $confirmation['pageId'] ], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
			return new WP_Error( 'alltfo_package_invalid', 'pages: a page confirmation needs its original page URL.', array( 'status' => 400 ) );
		}
	}
	$warnings = array();
	if ( isset( $package['source']['pluginVersion'] ) && ALLTFO_VERSION !== $package['source']['pluginVersion'] ) {
		$warnings[] = 'The source plugin version differs. Built-in theme defaults may have changed; preview the imported form.';
	}
	if ( $clean['actions'] ) {
		$warnings[] = 'Action settings are preserved. Check destination-site integrations, list IDs, roles and webhook credentials before publishing.';
	}
	if ( $package['pages'] ) {
		$warnings[] = 'Page confirmations are mapped by URL; pages unavailable on this site become redirects to their original URLs.';
	}
	$package['form']['schema'] = $clean;
	/**
	 * Filters validation output. Extensions may reject dependencies with WP_Error.
	 *
	 * @param array|WP_Error $result  Package and warnings.
	 * @param array          $package Validated package.
	 */
	return apply_filters(
		'alltfo_form_package_validated',
		array(
			'package'  => $package,
			'warnings' => $warnings,
		),
		$package
	);
}

/**
 * Describes a theme as a built-in base and only the tokens changed from it.
 * Custom themes predate base tracking, so choose the closest built-in by value.
 *
 * @param array $theme Source theme record.
 * @return array Portable theme.
 */
function alltfo_package_theme( $theme ) {
	$resolved = alltfo_resolve_tokens( $theme['slug'] );
	$defaults = alltfo_theme_token_defaults();
	$builtins = alltfo_builtin_themes();
	$base     = isset( $builtins[ $theme['slug'] ] ) ? $theme['slug'] : 'clean';
	$delta    = array_diff_assoc( $resolved, array_merge( $defaults, $builtins[ $base ]['tokens'] ) );
	if ( ! isset( $builtins[ $theme['slug'] ] ) ) {
		foreach ( $builtins as $slug => $builtin ) {
			$candidate = array_diff_assoc( $resolved, array_merge( $defaults, $builtin['tokens'] ) );
			if ( count( $candidate ) < count( $delta ) ) {
				$base  = $slug;
				$delta = $candidate;
			}
		}
	}
	return array(
		'slug'        => $theme['slug'],
		'base'        => $base,
		'label'       => $theme['label'],
		'description' => isset( $theme['description'] ) ? $theme['description'] : '',
		'dark'        => ! empty( $theme['dark'] ),
		'tokens'      => $delta,
	);
}

/**
 * Exports a saved form, or a current builder snapshot, with its dependencies.
 *
 * @param int         $form_id Form ID.
 * @param array|null  $schema  Unsaved schema, or null for storage.
 * @param string|null $title  Unsaved title, or null for storage.
 * @return array|WP_Error Package.
 */
function alltfo_export_form_package( $form_id, $schema = null, $title = null ) {
	if ( ! alltfo_can_edit_forms() ) {
		return new WP_Error( 'alltfo_forbidden', 'You cannot export forms.', array( 'status' => 403 ) );
	}
	$form = get_post( $form_id );
	if ( ! $form || ALLTFO_FORM_TYPE !== $form->post_type ) {
		return new WP_Error( 'alltfo_not_found', 'Form not found.', array( 'status' => 404 ) );
	}
	if ( null !== $schema && ! is_array( $schema ) ) {
		return new WP_Error( 'alltfo_package_invalid', 'schema must be an object.', array( 'status' => 400 ) );
	}
	if ( null !== $schema ) {
		$contract = alltfo_package_schema();
		$valid    = alltfo_package_validate_node( $schema, $contract['definitions']['formSchema'], $contract, 'form.schema' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}
	$schema = null === $schema ? alltfo_get_form_schema( $form_id ) : alltfo_normalize_schema( $schema );
	$theme  = alltfo_get_theme( $schema['settings']['theme'] );
	// A missing theme already renders as Clean. Snapshot what the user sees.
	$schema['settings']['theme']          = $theme['slug'];
	$schema['settings']['themeOverrides'] = array_diff_assoc( $schema['settings']['themeOverrides'], alltfo_resolve_tokens( $theme['slug'] ) );
	$package                              = array(
		'format'        => 'allterrain-forms',
		'formatVersion' => ALLTFO_PACKAGE_VERSION,
		'source'        => array(
			'pluginVersion' => ALLTFO_VERSION,
			'siteUrl'       => home_url( '/' ),
		),
		'form'          => array(
			'title'  => null === $title ? $form->post_title : sanitize_text_field( $title ),
			'schema' => $schema,
		),
		'theme'         => alltfo_package_theme( $theme ),
		'assets'        => array(),
		'pages'         => array(),
	);
	$ids                                  = array();
	alltfo_package_walk_fields(
		$schema['fields'],
		static function ( $field ) use ( &$ids ) {
			foreach ( $field['choices'] as $choice ) {
				if ( ! empty( $choice['image'] ) ) {
					$ids[] = $choice['image'];
				}
			}
		}
	);
	foreach ( array_unique( $ids ) as $id ) {
		$path = get_attached_file( $id );
		if ( ! $path || ! is_readable( $path ) || filesize( $path ) > ALLTFO_PACKAGE_MAX_BYTES / 2 ) {
			return new WP_Error( 'alltfo_package_asset', 'Image ' . $id . ' is missing locally or too large to embed.', array( 'status' => 400 ) );
		}
		$package['assets'][] = array(
			'id'       => $id,
			'filename' => wp_basename( $path ),
			'mime'     => get_post_mime_type( $id ),
			'title'    => get_the_title( $id ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'url'      => wp_get_attachment_url( $id ),
			'data'     => base64_encode( file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Explicit encoding of a local attachment.
		);
		if ( strlen( wp_json_encode( $package ) ) > ALLTFO_PACKAGE_MAX_BYTES ) {
			return new WP_Error( 'alltfo_package_asset', 'Embedded images exceed the 16 MiB package limit.', array( 'status' => 400 ) );
		}
	}
	$pages = array();
	foreach ( $schema['confirmations'] as $confirmation ) {
		if ( 'page' === $confirmation['type'] ) {
			$id           = $confirmation['pageId'];
			$pages[ $id ] = array(
				'id'  => $id,
				'url' => get_permalink( $id ),
			);
		}
	}
	$package['pages'] = array_values( $pages );
	/**
	 * Filters the exported document before final validation.
	 *
	 * @param array $package Portable document.
	 * @param int   $form_id Source form.
	 */
	$package = apply_filters( 'alltfo_form_package_export', $package, $form_id );
	$result  = alltfo_validate_form_package( $package );
	return is_wp_error( $result ) ? $result : $result['package'];
}

/**
 * Imports a validated document as a new draft and a separate copy of its theme.
 * Rolls back newly created resources if any dependent write fails.
 *
 * @param mixed $package Decoded document.
 * @return array|WP_Error New form ID and warnings.
 */
function alltfo_import_form_package( $package ) {
	if ( ! alltfo_can_edit_forms() ) {
		return new WP_Error( 'alltfo_forbidden', 'You cannot import forms.', array( 'status' => 403 ) );
	}
	$result = alltfo_validate_form_package( $package );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$package = $result['package'];
	if ( $package['assets'] && ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'alltfo_forbidden', 'Importing embedded images requires permission to upload files.', array( 'status' => 403 ) );
	}
	$schema   = $package['form']['schema'];
	$media    = array();
	$theme_id = 0;
	$form_id  = 0;
	$success  = false;
	try {
		if ( $package['assets'] ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		foreach ( $package['assets'] as $asset ) {
			$upload = wp_upload_bits( sanitize_file_name( $asset['filename'] ), null, base64_decode( $asset['data'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Image bytes already validated.
			if ( $upload['error'] ) {
				return new WP_Error( 'alltfo_package_asset', $upload['error'], array( 'status' => 500 ) );
			}
			$id = wp_insert_attachment(
				wp_slash(
					array(
						'post_title'     => sanitize_text_field( $asset['title'] ),
						'post_mime_type' => $asset['mime'],
						'post_status'    => 'inherit',
					)
				),
				$upload['file'],
				0,
				true
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $upload['file'] );
				return $id;
			}
			$media[ $asset['id'] ] = $id;
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $asset['alt'] ) ) );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		}
		alltfo_package_walk_fields(
			$schema['fields'],
			static function ( &$field ) use ( $media ) {
				foreach ( $field['choices'] as &$choice ) {
					if ( ! empty( $choice['image'] ) ) {
						$choice['image'] = $media[ $choice['image'] ];
					}
				}
			}
		);
		$pages = array_column( $package['pages'], 'url', 'id' );
		foreach ( $schema['confirmations'] as &$confirmation ) {
			if ( 'page' === $confirmation['type'] ) {
				$url = $pages[ $confirmation['pageId'] ];
				$id  = url_to_postid( $url );
				if ( $id && 'page' === get_post_type( $id ) ) {
					$confirmation['pageId'] = $id;
				} else {
					$confirmation['type']   = 'redirect';
					$confirmation['pageId'] = 0;
					$confirmation['url']    = esc_url_raw( $url );
				}
			}
		}
		unset( $confirmation );
		$theme           = $package['theme'];
		$builtins        = alltfo_builtin_themes();
		$theme['tokens'] = array_merge( $builtins[ $theme['base'] ]['tokens'], $theme['tokens'] );
		// A fresh slug avoids shadowing both saved and plugin-registered themes.
		$theme['slug'] = 'imported-' . wp_generate_uuid4();
		$saved         = alltfo_save_theme( $theme );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$theme_id                    = $saved['id'];
		$schema['settings']['theme'] = $saved['slug'];
		$form_id                     = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => ALLTFO_FORM_TYPE,
					'post_title'  => sanitize_text_field( $package['form']['title'] ),
					'post_status' => 'draft',
				)
			),
			true
		);
		if ( is_wp_error( $form_id ) ) {
			$error   = $form_id;
			$form_id = 0;
			return $error;
		}
		alltfo_save_form_schema( $form_id, $schema );
		if ( alltfo_get_form_schema( $form_id ) !== alltfo_normalize_schema( $schema ) ) {
			return new WP_Error( 'alltfo_package_save', 'Could not save the imported form.', array( 'status' => 500 ) );
		}
		$success = true;
		/**
		 * Fires after the form, theme and attachments have all been imported.
		 *
		 * @param int   $form_id New draft form.
		 * @param array $package Original validated package (source IDs).
		 * @param array $media   Source attachment ID => destination attachment ID.
		 */
		do_action( 'alltfo_form_package_imported', $form_id, $package, $media );
		return array(
			'formId'   => $form_id,
			'warnings' => $result['warnings'],
		);
	} finally {
		if ( ! $success ) {
			if ( $form_id ) {
				wp_delete_post( $form_id, true );
			}
			if ( $theme_id ) {
				wp_delete_post( $theme_id, true );
			}
			foreach ( $media as $id ) {
				wp_delete_attachment( $id, true );
			}
		}
	}
}

/**
 * Encodes schema-declared maps as objects on the wire, including empty maps.
 *
 * @param mixed      $value Value.
 * @param array|null $shape Current node schema, null for the root.
 * @param array|null $root  Root contract.
 * @return mixed Wire value.
 */
function alltfo_package_object_maps( $value, $shape = null, $root = null ) {
	$root  = null === $root ? alltfo_package_schema() : $root;
	$shape = null === $shape ? $root : $shape;
	if ( isset( $shape['$ref'] ) ) {
		$shape = $root['definitions'][ basename( $shape['$ref'] ) ];
	}
	if ( ! is_array( $value ) ) {
		return $value;
	}
	foreach ( $value as $key => $child ) {
		$child_shape   = isset( $shape['properties'][ $key ] ) ? $shape['properties'][ $key ] : ( isset( $shape['items'] ) ? $shape['items'] : array() );
		$value[ $key ] = alltfo_package_object_maps( $child, $child_shape, $root );
	}
	return isset( $shape['type'] ) && 'object' === $shape['type'] ? (object) $value : $value;
}
