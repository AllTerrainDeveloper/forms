<?php
/**
 * Validated, local form definitions for a window-scoped assistant.
 * YAML stays in the client. These routes accept decoded data and enforce the
 * same schema and capabilities as the form builder, without exposing entries.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes a validation error actionable for a model as well as a person.
 *
 * @param WP_Error $error Existing error.
 * @return WP_Error The error with structured diagnostics.
 */
function alltfo_assistant_error( $error ) {
	$data    = $error->get_error_data();
	$data    = is_array( $data ) ? $data : array();
	$message = $error->get_error_message();
	$parts   = explode( ': ', $message, 2 );
	$path    = count( $parts ) > 1 ? $parts[0] : 'document';
	$hint    = 'Correct this value and validate the complete YAML again. Keep unrelated fields and their IDs unchanged.';
	if ( false !== strpos( $message, 'expected boolean' ) ) {
		$hint = 'Use true or false without quotes; for example required: true.';
	} elseif ( false !== strpos( $message, 'required' ) ) {
		$hint = 'Add the missing property at this path. Read forms.md for the document structure.';
	} elseif ( false !== strpos( $message, 'unsupported property' ) || false !== strpos( $message, 'unknown property' ) ) {
		$hint = 'Check the property spelling and the selected field type with list_form_options. Do not silently remove unrelated settings.';
	} elseif ( false !== strpos( $message, 'duplicate' ) ) {
		$hint = 'Give each item a unique stable ID and update the references to the renamed item.';
	} elseif ( false !== strpos( $message, 'missing logic field' ) ) {
		$hint = 'Use the controlling field ID, not its label. Add that field or correct the rule reference; see conditions.md.';
	} elseif ( 0 === strpos( $path, 'theme' ) || false !== strpos( $path, 'themeOverrides' ) ) {
		$hint = 'Read themes.md and list_form_options. Use a registered token name and a safe CSS value such as "8px" or "#2255cc".';
	}
	$data['status']    = isset( $data['status'] ) ? $data['status'] : 400;
	$data['errors']    = array(
		array(
			'code'       => $error->get_error_code(),
			'path'       => $path,
			'message'    => $message,
			'suggestion' => $hint,
		),
	);
	$data['retryable'] = 400 === $data['status'];
	$error->add_data( $data );
	return $error;
}

/**
 * Validates a local {title, schema} document; missing optional keys get defaults.
 * Local theme and attachment IDs are retained, never imported or duplicated.
 *
 * @param mixed $draft Decoded editor document.
 * @return array|WP_Error Normalized definition.
 */
function alltfo_validate_assistant_draft( $draft ) {
	$json = wp_json_encode( $draft );
	if ( false === $json || strlen( $json ) > 60000 ) {
		return new WP_Error( 'alltfo_assistant_too_large', 'The editor document exceeds the 60 KB assistant limit or is not JSON-compatible. Use file import for larger forms.', array( 'status' => 413 ) );
	}
	$contract = alltfo_package_schema();
	$shape    = $contract['properties']['form'];
	$result   = alltfo_package_validate_node( $draft, $shape, $contract, 'form' );
	if ( is_wp_error( $result ) ) {
		return alltfo_assistant_error( $result );
	}
	$draft  = json_decode( $json, true );
	$schema = alltfo_normalize_schema( $draft['schema'] );
	$result = alltfo_package_check_preserved(
		$draft,
		array(
			'title'  => sanitize_text_field( $draft['title'] ),
			'schema' => $schema,
		),
		'form'
	);
	if ( is_wp_error( $result ) ) {
		return alltfo_assistant_error( $result );
	}
	$result = alltfo_package_validate_fields( $schema['fields'] );
	if ( is_wp_error( $result ) ) {
		return alltfo_assistant_error( $result );
	}
	$themes = alltfo_get_themes();
	if ( ! isset( $themes[ $schema['settings']['theme'] ] ) ) {
		return alltfo_assistant_error( new WP_Error( 'alltfo_assistant_invalid', 'form.schema.settings.theme: unknown theme; call list_form_options.', array( 'status' => 400 ) ) );
	}
	$result = alltfo_package_check_preserved( $schema['settings']['themeOverrides'], alltfo_sanitize_tokens( $schema['settings']['themeOverrides'] ), 'form.schema.settings.themeOverrides' );
	if ( is_wp_error( $result ) ) {
		return alltfo_assistant_error( $result );
	}
	foreach ( array( 'notifications', 'confirmations', 'actions' ) as $group ) {
		$ids = array_column( $schema[ $group ], 'id' );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return alltfo_assistant_error( new WP_Error( 'alltfo_assistant_invalid', 'form.schema.' . $group . ': duplicate IDs.', array( 'status' => 400 ) ) );
		}
		foreach ( $schema[ $group ] as $index => $item ) {
			foreach ( $item['logic']['rules'] as $rule ) {
				if ( ! in_array( $rule['field'], array_column( $schema['fields'], 'id' ), true ) ) {
					return alltfo_assistant_error( new WP_Error( 'alltfo_assistant_invalid', 'form.schema.' . $group . '.' . $index . '.logic: missing logic field ' . $rule['field'], array( 'status' => 400 ) ) );
				}
			}
		}
	}
	// Check image IDs without embedding media or sending file contents to AI.
	$invalid = null;
	alltfo_package_walk_fields(
		$schema['fields'],
		static function ( $field ) use ( &$invalid ) {
			foreach ( $field['choices'] as $index => $choice ) {
				if ( ! empty( $choice['image'] ) && ! wp_attachment_is_image( $choice['image'] ) ) {
					$invalid = 'form.schema.fields.' . $field['id'] . '.choices.' . $index . '.image';
				}
			}
		}
	);
	if ( $invalid ) {
		return alltfo_assistant_error( new WP_Error( 'alltfo_assistant_invalid', $invalid . ': choose an existing image attachment; do not invent IDs.', array( 'status' => 400 ) ) );
	}
	foreach ( $schema['confirmations'] as $index => $confirmation ) {
		if ( 'page' === $confirmation['type'] && 'page' !== get_post_type( $confirmation['pageId'] ) ) {
			return alltfo_assistant_error( new WP_Error( 'alltfo_assistant_invalid', 'form.schema.confirmations.' . $index . '.pageId: choose an existing page.', array( 'status' => 400 ) ) );
		}
	}
	/**
	 * Filters a validated assistant definition; may reject extension dependencies.
	 *
	 * @param array|WP_Error $result Normalized definition or failure.
	 * @param array          $draft  Original definition.
	 */
	return apply_filters(
		'alltfo_assistant_draft_validated',
		array(
			'title'  => $draft['title'],
			'schema' => $schema,
		),
		$draft
	);
}

/**
 * Fingerprints stored form content for a stale-write check.
 *
 * @param int $id Form ID.
 * @return string Revision token.
 */
function alltfo_assistant_revision( $id ) {
	$post = get_post( $id );
	return hash( 'sha256', wp_json_encode( array( $post ? $post->post_title : '', $post ? $post->post_status : '', get_post_meta( $id, ALLTFO_META_SCHEMA, true ) ) ) );
}

/** Register private assistant routes behind the normal editor permission gate. */
function alltfo_register_assistant_routes() {
	foreach ( array( 'validate', 'apply' ) as $action ) {
		register_rest_route(
			ALLTFO_REST_NAMESPACE,
			'/assistant/' . $action,
			array(
				'args'                => array(
					'operationKey' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 120,
						'pattern'   => '^[a-zA-Z0-9:_-]+$',
					),
					'draft'        => array(
						'type'     => 'object',
						'required' => true,
					),
					'formId'       => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => 'apply' === $action,
					),
					'revision'     => array(
						'type'     => 'string',
						'required' => 'apply' === $action,
					),
				),
				'methods'             => 'POST',
				'permission_callback' => 'alltfo_rest_can_edit',
				'callback'            => 'alltfo_rest_assistant_' . $action,
			)
		);
	}
	register_rest_route(
		ALLTFO_REST_NAMESPACE,
		'/assistant/forms/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'alltfo_rest_can_edit',
			'callback'            => 'alltfo_rest_assistant_read',
		)
	);
	register_rest_route(
		ALLTFO_REST_NAMESPACE,
		'/assistant/operations/(?P<key>[a-zA-Z0-9:_-]{1,120})',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'alltfo_rest_can_edit',
			'callback'            => 'alltfo_rest_assistant_operation',
		)
	);
}
add_action( 'rest_api_init', 'alltfo_register_assistant_routes' );

/**
 * Reads a stored revision without sending entries or private submission data.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function alltfo_rest_assistant_read( $request ) {
	$id = absint( $request['id'] );
	if ( ALLTFO_FORM_TYPE !== get_post_type( $id ) ) {
		return new WP_Error( 'alltfo_form_missing', 'Form not found.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'revision' => alltfo_assistant_revision( $id ) ) );
}

/**
 * Dry-run: validates without creating or changing a form.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function alltfo_rest_assistant_validate( $request ) {
	$result = alltfo_validate_assistant_draft( $request->get_param( 'draft' ) );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'valid' => true ) );
}

/**
 * An operation key is scoped to its acting user; IDs alone grant no access.
 *
 * @param string $key Client operation key.
 * @return string Non-autoloaded option name.
 */
function alltfo_assistant_operation_name( $key ) {
	return '_alltfo_mio_' . hash( 'sha256', get_current_user_id() . ':' . $key );
}

/**
 * Reads an operation without re-executing a write.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function alltfo_rest_assistant_operation( $request ) {
	$record = get_option( alltfo_assistant_operation_name( $request['key'] ) );
	$result = array(
		'effect' => 'write',
		'status' => 'unknown',
	);
	if ( is_array( $record ) && 'confirmed' === $record['status'] ) {
		$result = array(
			'effect'  => 'write',
			'status'  => 'confirmed',
			'receipt' => $record['receipt'],
			'data'    => array(
				'formId'   => $record['formId'],
				'revision' => $record['revision'],
			),
		);
	} elseif ( is_array( $record ) && 'rejected' === $record['status'] ) {
		$result = array(
			'effect'    => 'none',
			'status'    => 'rejected',
			'retryable' => false,
			'errors'    => array(
				array(
					'code'    => 'alltfo_assistant_conflict',
					'path'    => '$.revision',
					'message' => 'The stored form changed. This operation did not write.',
				),
			),
		);
	}
	return rest_ensure_response( $result );
}

/**
 * Removes expired operation metadata; deduplication is retained for seven days.
 *
 * @param string $name Option name minted by this plugin.
 * @return void
 */
function alltfo_expire_assistant_operation( $name ) {
	if ( is_string( $name ) && preg_match( '/^_alltfo_mio_[a-f0-9]{64}$/', $name ) ) {
		delete_option( $name );
	}
}
add_action( 'alltfo_expire_assistant_operation', 'alltfo_expire_assistant_operation' );

/**
 * Applies a validated definition; a new form is always a draft.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function alltfo_rest_assistant_apply( $request ) {
	$draft = alltfo_validate_assistant_draft( $request->get_param( 'draft' ) );
	if ( is_wp_error( $draft ) ) {
		return $draft;
	}
	$id       = absint( $request->get_param( 'formId' ) );
	$revision = $request->get_param( 'revision' );
	$key      = $request->get_param( 'operationKey' );
	$name     = $key ? alltfo_assistant_operation_name( $key ) : '';
	$record   = array();
	if ( $name ) {
		$payload = hash( 'sha256', wp_json_encode( array( $id, $revision, $draft ) ) );
		$record  = array(
			'status'  => 'running',
			'payload' => $payload,
			'created' => time(),
		);
		// The unique option_name index makes claiming one logical operation atomic.
		if ( ! add_option( $name, $record, '', false ) ) {
			$existing = get_option( $name );
			if ( is_array( $existing ) && hash_equals( $existing['payload'], $payload ) && 'confirmed' === $existing['status'] && hash_equals( $existing['revision'], alltfo_assistant_revision( $existing['formId'] ) ) ) {
				$response          = alltfo_rest_form_response( $existing['formId'] );
				$data              = $response->get_data();
				$data['operation'] = array(
					'key'      => $key,
					'receipt'  => $existing['receipt'],
					'revision' => $existing['revision'],
				);
				$response->set_data( $data );
				return $response;
			}
			return new WP_Error(
				'alltfo_assistant_operation_exists',
				'This operation key was already used. Inspect its status; do not replay the write.',
				array(
					'status'    => 409,
					'retryable' => false,
				)
			);
		}
		wp_schedule_single_event( time() + 7 * DAY_IN_SECONDS, 'alltfo_expire_assistant_operation', array( $name ) );
	}
	if ( $id && ( ALLTFO_FORM_TYPE !== get_post_type( $id ) || ! is_string( $revision ) || ! hash_equals( alltfo_assistant_revision( $id ), $revision ) ) ) {
		if ( $name ) {
			$record['status'] = 'rejected';
			update_option( $name, $record, false );
		}
		return new WP_Error(
			'alltfo_assistant_conflict',
			'The stored form changed. Read the current form and prepare a new edit; this edit was not applied.',
			array(
				'status'    => 409,
				'retryable' => false,
			)
		);
	}
	$args = array(
		'post_type'  => ALLTFO_FORM_TYPE,
		'post_title' => $draft['title'],
	);
	if ( $id ) {
		$args['ID']          = $id;
		$args['post_status'] = get_post_status( $id );
	} else {
		$args['post_status'] = 'draft';
	}
	$saved = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	alltfo_save_form_schema( $saved, $draft['schema'] );
	if ( alltfo_get_form_schema( $saved ) !== $draft['schema'] || get_post( $saved )->post_title !== $draft['title'] ) {
		return new WP_Error(
			'alltfo_assistant_save',
			'The save could not be verified. Reload the form before trying another write.',
			array(
				'status'    => 500,
				'retryable' => false,
			)
		);
	}
	$response = alltfo_rest_form_response( $saved );
	if ( $name ) {
		$record['status']   = 'confirmed';
		$record['formId']   = $saved;
		$record['revision'] = alltfo_assistant_revision( $saved );
		$record['receipt']  = wp_generate_uuid4();
		if ( ! update_option( $name, $record, false ) ) {
			return new WP_Error(
				'alltfo_assistant_receipt',
				'The form was saved, but its receipt could not be stored. Inspect the form before another write.',
				array(
					'status'    => 500,
					'retryable' => false,
				)
			);
		}
		$data              = $response->get_data();
		$data['operation'] = array(
			'key'      => $key,
			'receipt'  => $record['receipt'],
			'revision' => $record['revision'],
		);
		$response->set_data( $data );
	}
	return $response;
}
