<?php
/**
 * The template library.
 *
 * A template is just a schema. Starting a form from one is
 * `atf_save_form_schema( $new_form, atf_get_template( 'contact' ) )` -- there is
 * no template engine, no separate format, and no import path that differs from
 * the ordinary one. That is deliberate: a template that could express something
 * a hand-built form could not would be a second schema language to maintain.
 *
 * Which also means a site can turn any form it has into a template by exporting
 * its schema, and register it through `atf_form_templates`.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every available template.
 *
 * @since 0.1.0
 *
 * @return array<string, array> Slug => { label, description, icon, schema }.
 */
function atf_form_templates() {
	$templates = array(

		'blank'        => array(
			'label'       => __( 'Blank', 'allterrain-forms' ),
			'description' => __( 'Start from nothing.', 'allterrain-forms' ),
			'icon'        => 'dashicons-plus-alt2',
			'schema'      => array( 'fields' => array() ),
		),

		'contact'      => array(
			'label'       => __( 'Contact', 'allterrain-forms' ),
			'description' => __( 'Name, email, subject, message. The one every site needs.', 'allterrain-forms' ),
			'icon'        => 'dashicons-email-alt',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'name',
						'label'    => __( 'Your name', 'allterrain-forms' ),
						'required' => true,
						'parts'    => array( 'first', 'last' ),
					),
					array(
						'id'       => 'f2',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
						'hint'     => __( 'We will only use this to reply.', 'allterrain-forms' ),
					),
					array(
						'id'    => 'f3',
						'type'  => 'text',
						'label' => __( 'Subject', 'allterrain-forms' ),
					),
					array(
						'id'       => 'f4',
						'type'     => 'textarea',
						'label'    => __( 'Message', 'allterrain-forms' ),
						'required' => true,
						'rows'     => 6,
					),
					array(
						'id'          => 'f5',
						'type'        => 'consent',
						'label'       => __( 'Consent', 'allterrain-forms' ),
						'required'    => true,
						'consentText' => __( 'I am happy for you to store this message so you can reply to it.', 'allterrain-forms' ),
					),
				),
			),
		),

		'rsvp'         => array(
			'label'       => __( 'RSVP', 'allterrain-forms' ),
			'description' => __( 'Attending, how many, dietary needs. Conditional on the answer.', 'allterrain-forms' ),
			'icon'        => 'dashicons-tickets-alt',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => __( 'Your name', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f2',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f3',
						'type'     => 'radio',
						'label'    => __( 'Can you make it?', 'allterrain-forms' ),
						'required' => true,
						'inline'   => true,
						'choices'  => array(
							array(
								'label' => __( 'Yes, I will be there', 'allterrain-forms' ),
								'value' => 'yes',
							),
							array(
								'label' => __( 'Sorry, I cannot', 'allterrain-forms' ),
								'value' => 'no',
							),
						),
					),
					// The three fields below only exist once somebody says yes.
					// This is the template that shows conditional logic working
					// without anybody having to build it first.
					array(
						'id'    => 'f4',
						'type'  => 'number',
						'label' => __( 'How many of you?', 'allterrain-forms' ),
						'min'   => '1',
						'max'   => '10',
						'width' => 'half',
						'logic' => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'f3',
									'operator' => 'is',
									'value'    => 'yes',
								),
							),
						),
					),
					array(
						'id'      => 'f5',
						'type'    => 'checkboxes',
						'label'   => __( 'Any dietary requirements?', 'allterrain-forms' ),
						'other'   => true,
						'choices' => array(
							array(
								'label' => __( 'Vegetarian', 'allterrain-forms' ),
								'value' => 'vegetarian',
							),
							array(
								'label' => __( 'Vegan', 'allterrain-forms' ),
								'value' => 'vegan',
							),
							array(
								'label' => __( 'Gluten free', 'allterrain-forms' ),
								'value' => 'gluten-free',
							),
							array(
								'label' => __( 'Nut allergy', 'allterrain-forms' ),
								'value' => 'nut-allergy',
							),
						),
						'logic'   => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'f3',
									'operator' => 'is',
									'value'    => 'yes',
								),
							),
						),
					),
					array(
						'id'    => 'f6',
						'type'  => 'textarea',
						'label' => __( 'Anything else?', 'allterrain-forms' ),
						'rows'  => 3,
					),
				),
			),
		),

		'application'  => array(
			'label'       => __( 'Job application', 'allterrain-forms' ),
			'description' => __( 'Three steps, a CV upload, and a consent box.', 'allterrain-forms' ),
			'icon'        => 'dashicons-portfolio',
			'schema'      => array(
				'fields'   => array(
					array(
						'id'    => 'f1',
						'type'  => 'heading',
						'label' => __( 'About you', 'allterrain-forms' ),
					),
					array(
						'id'       => 'f2',
						'type'     => 'name',
						'label'    => __( 'Name', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f3',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
						'width'    => 'half',
						'unique'   => true,
						'messages' => array(
							'unique' => __( 'We already have an application from that address.', 'allterrain-forms' ),
						),
					),
					array(
						'id'    => 'f4',
						'type'  => 'tel',
						'label' => __( 'Phone', 'allterrain-forms' ),
						'width' => 'half',
					),
					array(
						'id'        => 'f5',
						'type'      => 'page_break',
						'label'     => __( 'Experience', 'allterrain-forms' ),
						'nextLabel' => __( 'Continue', 'allterrain-forms' ),
					),
					array(
						'id'      => 'f6',
						'type'    => 'select',
						'label'   => __( 'Years of experience', 'allterrain-forms' ),
						'choices' => array( '0–1', '2–4', '5–9', '10+' ),
					),
					array(
						'id'       => 'f7',
						'type'     => 'repeater',
						'label'    => __( 'Previous roles', 'allterrain-forms' ),
						'minRows'  => 1,
						'maxRows'  => 6,
						'addLabel' => __( 'Add another role', 'allterrain-forms' ),
						'fields'   => array(
							array(
								'id'    => 'role',
								'type'  => 'text',
								'label' => __( 'Job title', 'allterrain-forms' ),
								'width' => 'half',
							),
							array(
								'id'    => 'company',
								'type'  => 'text',
								'label' => __( 'Company', 'allterrain-forms' ),
								'width' => 'half',
							),
							array(
								'id'    => 'years',
								'type'  => 'text',
								'label' => __( 'Years', 'allterrain-forms' ),
								'width' => 'half',
							),
						),
					),
					array(
						'id'    => 'f8',
						'type'  => 'page_break',
						'label' => __( 'Finish', 'allterrain-forms' ),
					),
					array(
						'id'        => 'f9',
						'type'      => 'file',
						'label'     => __( 'Your CV', 'allterrain-forms' ),
						'required'  => true,
						'filetypes' => array( 'pdf', 'doc', 'docx' ),
						'maxsize'   => 8,
					),
					array(
						'id'    => 'f10',
						'type'  => 'textarea',
						'label' => __( 'Why this role?', 'allterrain-forms' ),
						'rows'  => 6,
					),
					array(
						'id'          => 'f11',
						'type'        => 'consent',
						'required'    => true,
						'label'       => __( 'Consent', 'allterrain-forms' ),
						'consentText' => __( 'I agree to my details being kept for the length of this recruitment process.', 'allterrain-forms' ),
					),
				),
				'settings' => array(
					'storage' => array( 'retention' => 365 ),
				),
			),
		),

		'survey'       => array(
			'label'       => __( 'Survey', 'allterrain-forms' ),
			'description' => __( 'A Likert matrix, an opinion scale and a free-text question.', 'allterrain-forms' ),
			'icon'        => 'dashicons-chart-bar',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'likert',
						'label'    => __( 'How much do you agree?', 'allterrain-forms' ),
						'required' => true,
						'choices'  => array(
							array(
								'label' => __( 'Strongly disagree', 'allterrain-forms' ),
								'value' => '1',
							),
							array(
								'label' => __( 'Disagree', 'allterrain-forms' ),
								'value' => '2',
							),
							array(
								'label' => __( 'Neutral', 'allterrain-forms' ),
								'value' => '3',
							),
							array(
								'label' => __( 'Agree', 'allterrain-forms' ),
								'value' => '4',
							),
							array(
								'label' => __( 'Strongly agree', 'allterrain-forms' ),
								'value' => '5',
							),
						),
						'rows'     => array(
							array(
								'key'   => 'easy',
								'label' => __( 'The site was easy to use', 'allterrain-forms' ),
							),
							array(
								'key'   => 'found',
								'label' => __( 'I found what I came for', 'allterrain-forms' ),
							),
							array(
								'key'   => 'again',
								'label' => __( 'I would come back', 'allterrain-forms' ),
							),
						),
					),
					array(
						'id'       => 'f2',
						'type'     => 'scale',
						'label'    => __( 'How likely are you to recommend us?', 'allterrain-forms' ),
						'min'      => 0,
						'max'      => 10,
						'minLabel' => __( 'Not at all likely', 'allterrain-forms' ),
						'maxLabel' => __( 'Extremely likely', 'allterrain-forms' ),
					),
					array(
						'id'    => 'f3',
						'type'  => 'textarea',
						'label' => __( 'What would you change?', 'allterrain-forms' ),
						'rows'  => 4,
					),
				),
			),
		),

		'quiz'         => array(
			'label'       => __( 'Quiz', 'allterrain-forms' ),
			'description' => __( 'Scored questions with a pass mark and the score in the confirmation.', 'allterrain-forms' ),
			'icon'        => 'dashicons-awards',
			'schema'      => array(
				'fields'        => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => __( 'Your name', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f2',
						'type'     => 'quiz',
						'label'    => __( 'What does HTML stand for?', 'allterrain-forms' ),
						'required' => true,
						'correct'  => 'markup',
						'points'   => 1,
						'choices'  => array(
							array(
								'label' => __( 'Hypertext Markup Language', 'allterrain-forms' ),
								'value' => 'markup',
							),
							array(
								'label' => __( 'Hyperlink Machine Language', 'allterrain-forms' ),
								'value' => 'machine',
							),
							array(
								'label' => __( 'Home Tool Markup Language', 'allterrain-forms' ),
								'value' => 'home',
							),
						),
					),
					array(
						'id'       => 'f3',
						'type'     => 'quiz',
						'label'    => __( 'Which of these is a CSS unit?', 'allterrain-forms' ),
						'required' => true,
						'correct'  => 'rem',
						'points'   => 1,
						'choices'  => array(
							array(
								'label' => 'rem',
								'value' => 'rem',
							),
							array(
								'label' => 'kbm',
								'value' => 'kbm',
							),
							array(
								'label' => 'pxl',
								'value' => 'pxl',
							),
						),
					),
				),
				'settings'      => array(
					'quiz' => array(
						'enabled'   => true,
						'passMark'  => 50,
						'showScore' => true,
					),
				),
				'confirmations' => array(
					array(
						'id'      => 'c1',
						'type'    => 'message',
						'name'    => __( 'Score', 'allterrain-forms' ),
						'message' => __( 'You scored {quiz:score} out of {quiz:total} — {quiz:passed}.', 'allterrain-forms' ),
					),
				),
			),
		),

		'order'        => array(
			'label'       => __( 'Order', 'allterrain-forms' ),
			'description' => __( 'Priced choices, a quantity, and a total that adds itself up.', 'allterrain-forms' ),
			'icon'        => 'dashicons-cart',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'select',
						'label'    => __( 'Product', 'allterrain-forms' ),
						'required' => true,
						'choices'  => array(
							array(
								'label' => __( 'Small — £20', 'allterrain-forms' ),
								'value' => 'small',
								'price' => 20,
							),
							array(
								'label' => __( 'Medium — £35', 'allterrain-forms' ),
								'value' => 'medium',
								'price' => 35,
							),
							array(
								'label' => __( 'Large — £50', 'allterrain-forms' ),
								'value' => 'large',
								'price' => 50,
							),
						),
					),
					array(
						'id'       => 'f2',
						'type'     => 'number',
						'label'    => __( 'Quantity', 'allterrain-forms' ),
						'required' => true,
						'default'  => 1,
						'min'      => '1',
						'max'      => '99',
						'width'    => 'half',
					),
					array(
						'id'      => 'f3',
						'type'    => 'checkboxes',
						'label'   => __( 'Extras', 'allterrain-forms' ),
						'choices' => array(
							array(
								'label' => __( 'Gift wrap — £3', 'allterrain-forms' ),
								'value' => 'wrap',
								'price' => 3,
							),
							array(
								'label' => __( 'Next-day delivery — £6', 'allterrain-forms' ),
								'value' => 'express',
								'price' => 6,
							),
						),
					),
					array(
						'id'       => 'f4',
						'type'     => 'total',
						'label'    => __( 'Total', 'allterrain-forms' ),
						'formula'  => '{f1} * {f2} + {f3}',
						'currency' => '£',
						'decimals' => 2,
					),
					array(
						'id'       => 'f5',
						'type'     => 'address',
						'label'    => __( 'Delivery address', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f6',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
					),
				),
			),
		),

		'registration' => array(
			'label'       => __( 'User registration', 'allterrain-forms' ),
			'description' => __( 'Creates a WordPress account on submit.', 'allterrain-forms' ),
			'icon'        => 'dashicons-admin-users',
			'schema'      => array(
				'fields'  => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => __( 'Username', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f2',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
						'unique'   => true,
					),
					array(
						'id'        => 'f3',
						'type'      => 'password',
						'label'     => __( 'Password', 'allterrain-forms' ),
						'required'  => true,
						'minlength' => '12',
						'hint'      => __( 'Twelve characters or more.', 'allterrain-forms' ),
					),
					array(
						'id'    => 'f4',
						'type'  => 'text',
						'label' => __( 'First name', 'allterrain-forms' ),
						'width' => 'half',
					),
					array(
						'id'    => 'f5',
						'type'  => 'text',
						'label' => __( 'Last name', 'allterrain-forms' ),
						'width' => 'half',
					),
				),
				'actions' => array(
					array(
						'id'       => 'a1',
						'type'     => 'register_user',
						'enabled'  => true,
						'settings' => array(
							'login'         => '{field:f1}',
							'email'         => '{field:f2}',
							'passwordField' => 'f3',
							'firstName'     => '{field:f4}',
							'lastName'      => '{field:f5}',
							'notify'        => true,
						),
					),
				),
			),
		),

		'feedback'     => array(
			'label'       => __( 'Feedback', 'allterrain-forms' ),
			'description' => __( 'A star rating and a comment. Two fields, no friction.', 'allterrain-forms' ),
			'icon'        => 'dashicons-star-filled',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'rating',
						'label'    => __( 'How did we do?', 'allterrain-forms' ),
						'required' => true,
						'max'      => 5,
					),
					array(
						'id'    => 'f2',
						'type'  => 'textarea',
						'label' => __( 'Tell us more', 'allterrain-forms' ),
						'rows'  => 4,
						// Only asked for when the rating is poor, which is the
						// only time the answer is worth the friction.
						'logic' => array(
							'enabled' => true,
							'action'  => 'show',
							'match'   => 'all',
							'rules'   => array(
								array(
									'field'    => 'f1',
									'operator' => 'less_equal',
									'value'    => '3',
								),
							),
						),
					),
					array(
						'id'    => 'f3',
						'type'  => 'email',
						'label' => __( 'Email, if you would like a reply', 'allterrain-forms' ),
					),
				),
			),
		),

		'booking'      => array(
			'label'       => __( 'Booking', 'allterrain-forms' ),
			'description' => __( 'A date, a time, a party size — with the past ruled out.', 'allterrain-forms' ),
			'icon'        => 'dashicons-calendar-alt',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => __( 'Name', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f2',
						'type'     => 'tel',
						'label'    => __( 'Phone', 'allterrain-forms' ),
						'required' => true,
						'width'    => 'half',
					),
					array(
						'id'       => 'f3',
						'type'     => 'email',
						'label'    => __( 'Email', 'allterrain-forms' ),
						'required' => true,
						'width'    => 'half',
					),
					array(
						'id'       => 'f4',
						'type'     => 'date',
						'label'    => __( 'Date', 'allterrain-forms' ),
						'required' => true,
						'width'    => 'half',
						'prefill'  => 'date:today',
					),
					array(
						'id'       => 'f5',
						'type'     => 'time',
						'label'    => __( 'Time', 'allterrain-forms' ),
						'required' => true,
						'width'    => 'half',
					),
					array(
						'id'       => 'f6',
						'type'     => 'number',
						'label'    => __( 'How many people?', 'allterrain-forms' ),
						'required' => true,
						'min'      => '1',
						'max'      => '20',
						'default'  => 2,
					),
					array(
						'id'    => 'f7',
						'type'  => 'textarea',
						'label' => __( 'Anything we should know?', 'allterrain-forms' ),
						'rows'  => 3,
					),
				),
			),
		),

		'bug'          => array(
			'label'       => __( 'Bug report', 'allterrain-forms' ),
			'description' => __( 'Steps, severity, a screenshot, and the page it happened on.', 'allterrain-forms' ),
			'icon'        => 'dashicons-warning',
			'schema'      => array(
				'fields' => array(
					array(
						'id'       => 'f1',
						'type'     => 'text',
						'label'    => __( 'What went wrong?', 'allterrain-forms' ),
						'required' => true,
					),
					array(
						'id'       => 'f2',
						'type'     => 'select',
						'label'    => __( 'How bad is it?', 'allterrain-forms' ),
						'required' => true,
						'choices'  => array(
							array(
								'label' => __( 'It is unusable', 'allterrain-forms' ),
								'value' => 'blocker',
							),
							array(
								'label' => __( 'It is a real problem', 'allterrain-forms' ),
								'value' => 'major',
							),
							array(
								'label' => __( 'It is annoying', 'allterrain-forms' ),
								'value' => 'minor',
							),
						),
					),
					array(
						'id'          => 'f3',
						'type'        => 'textarea',
						'label'       => __( 'Steps to reproduce', 'allterrain-forms' ),
						'required'    => true,
						'rows'        => 6,
						'placeholder' => __( "1. Go to…\n2. Click…\n3. See…", 'allterrain-forms' ),
					),
					array(
						'id'        => 'f4',
						'type'      => 'file',
						'label'     => __( 'Screenshot', 'allterrain-forms' ),
						'filetypes' => array( 'png', 'jpg', 'jpeg', 'gif', 'webp' ),
						'maxfiles'  => 3,
					),
					// Filled in from the URL, so the report always says which
					// page it came from without anybody having to type it.
					array(
						'id'      => 'f5',
						'type'    => 'hidden',
						'label'   => __( 'Page', 'allterrain-forms' ),
						'prefill' => 'query:page',
					),
				),
			),
		),
	);

	/**
	 * Filters the template library.
	 *
	 * A site turns one of its own forms into a template by exporting the schema
	 * and adding it here.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array> $templates Slug => { label, description, icon, schema }.
	 */
	return apply_filters( 'atf_form_templates', $templates );
}

/**
 * One template's schema, normalised.
 *
 * @since 0.1.0
 *
 * @param string $slug Template slug.
 * @return array A complete schema. An unknown slug returns an empty form.
 */
function atf_get_template( $slug ) {
	$templates = atf_form_templates();
	$slug      = sanitize_key( (string) $slug );

	if ( ! isset( $templates[ $slug ] ) ) {
		return atf_normalize_schema( array() );
	}

	return atf_normalize_schema( $templates[ $slug ]['schema'] );
}

/**
 * Creates a form from a template.
 *
 * @since 0.1.0
 *
 * @param string $slug  Template slug.
 * @param string $title The new form's title.
 * @return int|WP_Error The new form's id.
 */
function atf_create_form_from_template( $slug, $title = '' ) {
	if ( ! atf_can_edit_forms() ) {
		return new WP_Error( 'atf_forbidden', __( 'You cannot create forms.', 'allterrain-forms' ), array( 'status' => 403 ) );
	}

	$templates = atf_form_templates();
	$slug      = sanitize_key( (string) $slug );

	if ( '' === $title ) {
		$title = isset( $templates[ $slug ] )
			? $templates[ $slug ]['label']
			: __( 'Untitled form', 'allterrain-forms' );
	}

	$form_id = wp_insert_post(
		array(
			'post_type'   => ATF_FORM_TYPE,
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}

	atf_save_form_schema( $form_id, atf_get_template( $slug ) );

	/**
	 * Fires after a form is created from a template.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $form_id The new form.
	 * @param string $slug    The template it came from.
	 */
	do_action( 'atf_form_created', $form_id, $slug );

	return $form_id;
}
