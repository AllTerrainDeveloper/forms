<?php
/**
 * Validation and sanitisation.
 *
 * The server is the authority on whether a submission is acceptable, so these
 * are the tests that matter most: everything the browser checks it checks as a
 * convenience, and everything here it checks for real.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Server-side validation.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Validation extends WP_UnitTestCase {

	/**
	 * Zero is an answer.
	 *
	 * The case every forms plugin gets wrong: a rating of zero, a quantity of
	 * zero and an NPS answer of zero are deliberate choices, and `empty()` calls
	 * every one of them missing — which rejects the people who gave the lowest
	 * score.
	 *
	 * @dataProvider data_emptiness
	 * @covers ::alltfo_value_is_empty
	 *
	 * @param mixed $value The value.
	 * @param bool  $empty Whether it counts as unanswered.
	 */
	public function test_emptiness( $value, $empty ) {
		$this->assertSame( $empty, alltfo_value_is_empty( $value ), var_export( $value, true ) );
	}

	/**
	 * Values and whether they are empty.
	 *
	 * @return array[]
	 */
	public function data_emptiness() {
		return array(
			'zero int'        => array( 0, false ),
			'zero string'     => array( '0', false ),
			'zero float'      => array( 0.0, false ),
			'empty string'    => array( '', true ),
			'null'            => array( null, true ),
			'false'           => array( false, true ),
			'true'            => array( true, false ),
			'empty array'     => array( array(), true ),
			'array of blanks' => array( array( '', '' ), true ),
			'array with one'  => array( array( '', 'x' ), false ),
			'composite blank' => array(
				array(
					'first' => '',
					'last'  => '',
				),
				true,
			),
			'composite full'  => array(
				array(
					'first' => 'Ada',
					'last'  => '',
				),
				false,
			),
			'whitespace'      => array( ' ', false ),
		);
	}

	/**
	 * A required field that was not answered is an error.
	 *
	 * @covers ::alltfo_validate_submission
	 */
	public function test_required_fields() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => 'Name',
						'required' => true,
					),
					array(
						'id'   => 'f2',
						'type' => 'text',
					),
				),
			)
		);

		$errors = alltfo_validate_submission(
			$schema,
			array(
				'f1' => '',
				'f2' => '',
			)
		);

		$this->assertArrayHasKey( 'f1', $errors );
		$this->assertArrayNotHasKey( 'f2', $errors, 'An optional empty field is not an error.' );

		$this->assertSame(
			array(),
			alltfo_validate_submission(
				$schema,
				array(
					'f1' => 'Ada',
					'f2' => '',
				)
			)
		);
	}

	/**
	 * A required field answered with zero passes.
	 *
	 * @covers ::alltfo_validate_submission
	 */
	public function test_required_field_accepts_zero() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'scale',
						'label'    => 'How likely?',
						'required' => true,
					),
				),
			)
		);

		$this->assertSame( array(), alltfo_validate_submission( $schema, array( 'f1' => 0 ) ) );
	}

	/**
	 * Email and URL fields check their format.
	 *
	 * @covers ::alltfo_validate_field
	 */
	public function test_format_validation() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'e',
						'type' => 'email',
					),
					array(
						'id'   => 'u',
						'type' => 'url',
					),
				),
			)
		);

		$errors = alltfo_validate_submission(
			$schema,
			array(
				'e' => 'not-an-email',
				'u' => 'not a url',
			)
		);

		$this->assertArrayHasKey( 'e', $errors );
		$this->assertArrayHasKey( 'u', $errors );

		$this->assertSame(
			array(),
			alltfo_validate_submission(
				$schema,
				array(
					'e' => 'ada@example.com',
					'u' => 'https://example.com',
				)
			)
		);
	}

	/**
	 * A value not on the choice list is refused.
	 *
	 * This is a security check, not a usability one: without it a "role"
	 * dropdown can be posted with any value at all.
	 *
	 * @covers ::alltfo_validate_bounds
	 */
	public function test_choices_are_a_whitelist() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'role',
						'type'    => 'select',
						'choices' => array(
							array(
								'label' => 'Reader',
								'value' => 'reader',
							),
							array(
								'label' => 'Writer',
								'value' => 'writer',
							),
						),
					),
				),
			)
		);

		$errors = alltfo_validate_submission( $schema, array( 'role' => 'administrator' ) );

		$this->assertArrayHasKey( 'role', $errors );
		$this->assertSame( array(), alltfo_validate_submission( $schema, array( 'role' => 'writer' ) ) );
	}

	/**
	 * A forged value in a multi-choice field is refused too.
	 *
	 * @covers ::alltfo_validate_bounds
	 */
	public function test_multi_choice_whitelist() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'extras',
						'type'    => 'checkboxes',
						'choices' => array(
							array(
								'label' => 'Wrap',
								'value' => 'wrap',
							),
						),
					),
				),
			)
		);

		$this->assertArrayHasKey(
			'extras',
			alltfo_validate_submission( $schema, array( 'extras' => array( 'wrap', 'free-everything' ) ) )
		);
	}

	/**
	 * A field with "Other" enabled accepts free text.
	 *
	 * The `__other__` marker is replaced by what the visitor typed *before*
	 * validation runs, so with "Other" on there is no whitelist to enforce —
	 * a list could never anticipate the answer. Without the exemption every
	 * legitimate "Other" answer was refused as a forged request.
	 *
	 * @covers ::alltfo_validate_bounds
	 */
	public function test_other_answers_pass_the_whitelist() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'colour',
						'type'    => 'radio',
						'other'   => true,
						'choices' => array(
							array(
								'label' => 'Red',
								'value' => 'red',
							),
						),
					),
				),
			)
		);

		// The full pipeline: the marker posted beside the typed text.
		$values = alltfo_apply_other_values(
			$schema,
			array( 'colour' => '__other__' ),
			array( 'alltfo_other' => array( 'colour' => 'Purple' ) )
		);

		$this->assertSame( 'Purple', $values['colour'] );
		$this->assertSame( array(), alltfo_validate_submission( $schema, $values ) );
	}

	/**
	 * Length and numeric bounds are enforced.
	 *
	 * @covers ::alltfo_validate_bounds
	 */
	public function test_bounds() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'        => 't',
						'type'      => 'text',
						'minlength' => '3',
						'maxlength' => '5',
					),
					array(
						'id'   => 'n',
						'type' => 'number',
						'min'  => '1',
						'max'  => '10',
					),
				),
			)
		);

		$this->assertArrayHasKey( 't', alltfo_validate_submission( $schema, array( 't' => 'ab' ) ) );
		$this->assertArrayHasKey( 't', alltfo_validate_submission( $schema, array( 't' => 'abcdef' ) ) );
		$this->assertArrayNotHasKey( 't', alltfo_validate_submission( $schema, array( 't' => 'abcd' ) ) );

		$this->assertArrayHasKey( 'n', alltfo_validate_submission( $schema, array( 'n' => 0 ) ) );
		$this->assertArrayHasKey( 'n', alltfo_validate_submission( $schema, array( 'n' => 11 ) ) );
		$this->assertArrayNotHasKey( 'n', alltfo_validate_submission( $schema, array( 'n' => 5 ) ) );
	}

	/**
	 * A malformed author-supplied pattern lets the field pass.
	 *
	 * The visitor did not write the pattern and cannot fix it, so punishing them
	 * for it is the wrong answer — and a PHP warning on every public submission
	 * is a worse one.
	 *
	 * @covers ::alltfo_validate_bounds
	 */
	public function test_broken_pattern_does_not_block_the_visitor() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'text',
						'pattern' => '([unclosed',
					),
				),
			)
		);

		$this->assertSame( array(), alltfo_validate_submission( $schema, array( 'f1' => 'anything' ) ) );
	}

	/**
	 * A consent field that is not ticked says so in its own terms.
	 *
	 * @covers ::alltfo_validate_field
	 */
	public function test_consent_has_its_own_message() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'       => 'c',
						'type'     => 'consent',
						'required' => true,
					),
				),
			)
		);

		$errors = alltfo_validate_submission( $schema, array( 'c' => false ) );

		$this->assertArrayHasKey( 'c', $errors );
		$this->assertStringContainsString( 'agreed to', $errors['c'] );
	}

	/**
	 * A field's own message overrides the default wording.
	 *
	 * @covers ::alltfo_field_message
	 */
	public function test_custom_messages() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'email',
						'required' => true,
						'messages' => array(
							'required' => 'We need your email to write back.',
							'invalid'  => 'That address has a typo in it.',
						),
					),
				),
			)
		);

		$this->assertSame(
			'We need your email to write back.',
			alltfo_validate_submission( $schema, array( 'f1' => '' ) )['f1']
		);

		$this->assertSame(
			'That address has a typo in it.',
			alltfo_validate_submission( $schema, array( 'f1' => 'nope' ) )['f1']
		);
	}

	/* ------------------------------------------------------------ Sanitising */

	/**
	 * Values are sanitised through their own field type.
	 *
	 * @covers ::alltfo_sanitize_field_value
	 */
	public function test_sanitising_by_type() {
		$this->assertSame(
			'Hello',
			alltfo_sanitize_field_value( '<b>Hello</b>', array( 'type' => 'text' ) )
		);

		$this->assertStringContainsString(
			"\n",
			alltfo_sanitize_field_value( "Line one\nLine two", array( 'type' => 'textarea' ) ),
			'A paragraph field must keep its line breaks.'
		);

		$this->assertSame(
			'ada@example.com',
			alltfo_sanitize_field_value( ' ada@example.com ', array( 'type' => 'email' ) )
		);

		$this->assertSame( 5, alltfo_sanitize_field_value( '5', array( 'type' => 'number' ) ) );

		$this->assertSame(
			'',
			alltfo_sanitize_field_value( '', array( 'type' => 'number' ) ),
			'An unanswered number stays empty rather than becoming zero.'
		);

		$this->assertSame(
			'',
			alltfo_sanitize_field_value( 'not a number', array( 'type' => 'number' ) )
		);
	}

	/**
	 * An array arriving where a string was expected is coerced, not passed on.
	 *
	 * That shape is exactly what a request-forgery probe looks like.
	 *
	 * @covers ::alltfo_sanitize_field_value
	 */
	public function test_wrong_shapes_are_coerced() {
		$this->assertSame( '', alltfo_sanitize_field_value( array( 'x' ), array( 'type' => 'text' ) ) );
		$this->assertSame( '', alltfo_sanitize_field_value( array( 1, 2 ), array( 'type' => 'number' ) ) );
		$this->assertSame( array( 'x' ), alltfo_sanitize_field_value( 'x', array( 'type' => 'checkboxes' ) ) );
		$this->assertSame( array(), alltfo_sanitize_field_value( 'x', array( 'type' => 'name' ) ) );
	}

	/**
	 * An image choice's shape follows its `multiple` flag.
	 *
	 * The renderer posts an array the moment the flag is on, and the generic
	 * string path coerces an array to '' -- which silently lost every
	 * selection a visitor made.
	 *
	 * @covers ::alltfo_sanitize_field_value
	 */
	public function test_image_choice_shape_follows_the_multiple_flag() {
		$single = array( 'type' => 'image_choice' );
		$multi  = array(
			'type'     => 'image_choice',
			'multiple' => true,
		);

		$this->assertSame( 'a', alltfo_sanitize_field_value( 'a', $single ) );
		$this->assertSame( '', alltfo_sanitize_field_value( array( 'a', 'b' ), $single ), 'An array where one value belongs is a forged request.' );

		$this->assertSame( array( 'a', 'b' ), alltfo_sanitize_field_value( array( 'a', 'b' ), $multi ) );
		$this->assertSame( array( 'a' ), alltfo_sanitize_field_value( 'a', $multi ), 'A lone value still stores as a list when multiple is on.' );
		$this->assertSame( array(), alltfo_sanitize_field_value( '', $multi ) );
	}

	/**
	 * A signature that is not an image data URI is discarded.
	 *
	 * @covers ::alltfo_register_builtin_field_types
	 */
	public function test_signature_must_be_an_image_data_uri() {
		$field = array( 'type' => 'signature' );

		$this->assertSame( '', alltfo_sanitize_field_value( 'javascript:alert(1)', $field ) );
		$this->assertSame( '', alltfo_sanitize_field_value( 'data:text/html;base64,PHNjcmlwdD4=', $field ) );

		$valid = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

		$this->assertSame( $valid, alltfo_sanitize_field_value( $valid, $field ) );
	}

	/**
	 * A file field's value never comes from the request body.
	 *
	 * Otherwise a forged body could claim an attachment id it does not own.
	 *
	 * @covers ::alltfo_sanitize_submission
	 */
	public function test_file_values_are_not_read_from_the_body() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'cv',
						'type' => 'file',
					),
				),
			)
		);

		$values = alltfo_sanitize_submission( $schema, array( 'cv' => array( 999999 ) ) );

		$this->assertArrayNotHasKey( 'cv', $values );
	}

	/**
	 * A key no field asked for is never read.
	 *
	 * @covers ::alltfo_sanitize_submission
	 */
	public function test_unknown_keys_are_ignored() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'   => 'f1',
						'type' => 'text',
					),
				),
			)
		);

		$values = alltfo_sanitize_submission(
			$schema,
			array(
				'f1'            => 'kept',
				'administrator' => 'yes please',
				'post_status'   => 'publish',
			)
		);

		$this->assertSame( array( 'f1' => 'kept' ), $values );
	}

	/**
	 * A repeater keeps only rows with something in them.
	 *
	 * @covers ::alltfo_sanitize_repeater_value
	 */
	public function test_repeater_drops_blank_rows() {
		$field = alltfo_normalize_field(
			array(
				'id'      => 'rep',
				'type'    => 'repeater',
				'maxRows' => 3,
				'fields'  => array(
					array(
						'id'   => 'role',
						'type' => 'text',
					),
				),
			)
		);

		$rows = alltfo_sanitize_field_value(
			array(
				array( 'role' => 'Engineer' ),
				array( 'role' => '' ),
				array( 'role' => 'Designer' ),
			),
			$field
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Engineer', $rows[0]['role'] );
		$this->assertSame( 'Designer', $rows[1]['role'] );
	}

	/**
	 * Rows beyond the maximum are dropped rather than rejected.
	 *
	 * A request carrying more rows than the form offers is forged, and there is
	 * nothing to tell the visitor.
	 *
	 * @covers ::alltfo_sanitize_repeater_value
	 */
	public function test_repeater_clamps_row_count() {
		$field = alltfo_normalize_field(
			array(
				'id'      => 'rep',
				'type'    => 'repeater',
				'maxRows' => 2,
				'fields'  => array(
					array(
						'id'   => 'role',
						'type' => 'text',
					),
				),
			)
		);

		$rows = alltfo_sanitize_field_value(
			array(
				array( 'role' => 'a' ),
				array( 'role' => 'b' ),
				array( 'role' => 'c' ),
				array( 'role' => 'd' ),
			),
			$field
		);

		$this->assertCount( 2, $rows );
	}

	/**
	 * A website field checks the shape of an address, not whether it exists.
	 *
	 * The bug this pins: validation used `wp_http_validate_url()`, which is built
	 * to decide whether *the server* may fetch a URL. It resolves the host through
	 * DNS on every submission, refuses hosts inside private ranges, and allows
	 * only three ports — so `https://example.com` was rejected on any install
	 * without outbound DNS, an intranet address a staff form legitimately collects
	 * was called invalid, and every submission asked a name server to resolve
	 * whatever hostname the visitor had typed.
	 *
	 * @dataProvider data_urls
	 * @covers ::alltfo_looks_like_a_url
	 *
	 * @param string $value    The submitted value.
	 * @param bool   $expected Whether it should be accepted.
	 * @param string $why      What the case is about.
	 */
	public function test_url_shape( $value, $expected, $why ) {
		$this->assertSame( $expected, alltfo_looks_like_a_url( $value ), $why );
	}

	/**
	 * Addresses a website field should and should not take.
	 *
	 * @return array[]
	 */
	public function data_urls() {
		return array(
			'an ordinary site'      => array( 'https://example.com', true, 'A plain https address must be accepted.' ),
			'with a path and query' => array( 'https://example.com/a/b?c=d#e', true, 'Paths and queries are part of an address.' ),
			'plain http'            => array( 'http://example.com', true, 'Not every site is https.' ),
			'a subdomain'           => array( 'https://shop.example.co.uk', true, 'Subdomains are addresses too.' ),
			'a port'                => array( 'http://example.com:8000/x', true, 'A port other than 80/443/8080 is still an address.' ),
			'localhost'             => array( 'http://localhost:8889/form', true, 'Development sites must not be rejected.' ),
			'a private host'        => array( 'https://intranet.local/hr', true, 'An intranet address is a legitimate answer.' ),
			'no scheme'             => array( 'example.com', false, 'A bare domain is not a URL.' ),
			'prose'                 => array( 'not a url', false, 'Free text is not an address.' ),
			'a dotless host'        => array( 'https://wat', false, 'A hostname with no dot is a typo.' ),
			// The two that matter most: both parse as perfectly valid URLs, and
			// the value ends up in an href in a notification email.
			'javascript'            => array( 'javascript:alert(1)', false, 'A javascript: URL must never be accepted.' ),
			'a data url'            => array( 'data:text/html;base64,PHN2Zz4=', false, 'A data: URL must never be accepted.' ),
			'ftp'                   => array( 'ftp://example.com/file', false, 'Only http and https by default.' ),
			'empty'                 => array( '', false, 'Emptiness is handled by `required`, not by the format check.' ),
		);
	}

	/**
	 * A required sub-field inside a repeater rejects the row that skipped it.
	 *
	 * @covers ::alltfo_validate_repeater_rows
	 */
	public function test_repeater_required_subfield_names_the_row() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'        => 'att',
						'type'      => 'repeater',
						'label'     => 'Attendees',
						'itemLabel' => 'Attendee',
						'fields'    => array(
							array(
								'id'    => 'name',
								'type'  => 'text',
								'label' => 'Name',
							),
							array(
								'id'       => 'age',
								'type'     => 'number',
								'label'    => 'Age',
								'required' => true,
							),
						),
					),
				),
			)
		);

		$errors = alltfo_validate_submission(
			$schema,
			array(
				'att' => array(
					array(
						'name' => 'Ana',
						'age'  => '30',
					),
					array(
						'name' => 'Luz',
						'age'  => '',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'att', $errors );
		$this->assertStringContainsString( 'Attendee 2', $errors['att'] );
		$this->assertStringContainsString( 'Age is required.', $errors['att'] );
	}

	/**
	 * Alongside the summary, the failure names the exact control.
	 *
	 * `att.1.age` is what lets the client mark the one box that failed rather
	 * than every box in every row — the bug this pins was a repeater error
	 * painting the whole card red with no way to see which answer it meant.
	 *
	 * @covers ::alltfo_validate_repeater_sub_errors
	 */
	public function test_repeater_failure_names_the_exact_control() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'        => 'att',
						'type'      => 'repeater',
						'label'     => 'Attendees',
						'itemLabel' => 'Attendee',
						'fields'    => array(
							array(
								'id'    => 'name',
								'type'  => 'text',
								'label' => 'Name',
							),
							array(
								'id'       => 'age',
								'type'     => 'number',
								'label'    => 'Age',
								'required' => true,
							),
						),
					),
				),
			)
		);

		$errors = alltfo_validate_submission(
			$schema,
			array(
				'att' => array(
					array(
						'name' => 'Ana',
						'age'  => '30',
					),
					array(
						'name' => 'Luz',
						'age'  => '',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'att.1.age', $errors );
		$this->assertSame( 'Age is required.', $errors['att.1.age'] );
		$this->assertArrayNotHasKey( 'att.0.age', $errors, 'The row that passed is not marked.' );
		$this->assertArrayNotHasKey( 'att.1.name', $errors, 'The control that passed is not marked.' );

		// The subs arrive before the summary, so a client walking the map in
		// order reaches the exact box first.
		$this->assertSame( 'att.1.age', array_keys( $errors )[0] );
	}

	/**
	 * Two failing controls are both named, each under its own key.
	 *
	 * @covers ::alltfo_validate_repeater_sub_errors
	 */
	public function test_repeater_marks_every_failing_control() {
		$field = array(
			'id'     => 'att',
			'type'   => 'repeater',
			'fields' => array(
				array(
					'id'       => 'name',
					'type'     => 'text',
					'label'    => 'Name',
					'required' => true,
				),
				array(
					'id'       => 'age',
					'type'     => 'number',
					'label'    => 'Age',
					'required' => true,
				),
			),
		);

		$schema = alltfo_normalize_schema( array( 'fields' => array( $field ) ) );

		$errors = alltfo_validate_repeater_sub_errors(
			$schema['fields'][0],
			array(
				array(
					'name' => '',
					'age'  => '',
				),
			),
			$schema,
			array()
		);

		$this->assertSame( array( 'att.0.name', 'att.0.age' ), array_keys( $errors ) );
	}

	/**
	 * A row that passes its sub-fields' bounds passes, and one that does not
	 * fails with the bound's own message.
	 *
	 * @covers ::alltfo_validate_repeater_rows
	 */
	public function test_repeater_rows_enforce_bounds() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'     => 'att',
						'type'   => 'repeater',
						'label'  => 'Attendees',
						'fields' => array(
							array(
								'id'    => 'age',
								'type'  => 'number',
								'label' => 'Age',
								'max'   => '120',
							),
						),
					),
				),
			)
		);

		$fine = alltfo_validate_submission( $schema, array( 'att' => array( array( 'age' => '30' ) ) ) );
		$this->assertArrayNotHasKey( 'att', $fine );

		$errors = alltfo_validate_submission( $schema, array( 'att' => array( array( 'age' => '200' ) ) ) );
		$this->assertArrayHasKey( 'att', $errors );
	}

	/**
	 * Fewer rows than `minRows` is an error the visitor can act on.
	 *
	 * @covers ::alltfo_validate_repeater_rows
	 */
	public function test_repeater_enforces_min_rows() {
		$schema = alltfo_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'att',
						'type'    => 'repeater',
						'label'   => 'Attendees',
						'minRows' => 2,
						'fields'  => array(
							array(
								'id'   => 'name',
								'type' => 'text',
							),
						),
					),
				),
			)
		);

		$errors = alltfo_validate_submission( $schema, array( 'att' => array( array( 'name' => 'Ana' ) ) ) );

		$this->assertArrayHasKey( 'att', $errors );
		$this->assertStringContainsString( 'at least 2', $errors['att'] );
	}
}
