<?php
/**
 * Privacy.
 *
 * A forms plugin holds more personal data than almost anything else on a site,
 * and it holds it because a person typed it in. WordPress already has an
 * exporter and an eraser that a site owner is obliged to be able to answer a
 * request with; this file plugs entries into both, so "export everything you
 * hold about me" and "delete it" reach form submissions without anybody having
 * to remember that forms are a separate place.
 *
 * This is the part of GDPR compliance a plugin can actually deliver. The
 * retention policy in `entries.php` is the other half.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_privacy_personal_data_exporters', 'atf_register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'atf_register_eraser' );
add_action( 'admin_init', 'atf_register_privacy_policy_text' );

/**
 * Registers the entries exporter.
 *
 * @since 0.1.0
 *
 * @param array $exporters Registered exporters.
 * @return array
 */
function atf_register_exporter( $exporters ) {
	$exporters['allterrain-forms'] = array(
		'exporter_friendly_name' => __( 'Form submissions', 'allterrain-forms' ),
		'callback'               => 'atf_export_personal_data',
	);

	return $exporters;
}

/**
 * Registers the entries eraser.
 *
 * @since 0.1.0
 *
 * @param array $erasers Registered erasers.
 * @return array
 */
function atf_register_eraser( $erasers ) {
	$erasers['allterrain-forms'] = array(
		'eraser_friendly_name' => __( 'Form submissions', 'allterrain-forms' ),
		'callback'             => 'atf_erase_personal_data',
	);

	return $erasers;
}

/**
 * Finds the entries belonging to an email address.
 *
 * Two ways somebody's entries are theirs: they were logged in when they
 * submitted, or they typed the address into an email field. Both are checked,
 * because most form submissions are from logged-out visitors and matching only
 * on author would find almost nothing.
 *
 * @since 0.1.0
 *
 * @param string $email The address.
 * @param int    $page  One-based page.
 * @return array { entries: WP_Post[], done: bool }
 */
function atf_find_entries_for_email( $email, $page = 1 ) {
	$per_page = 50;
	$user     = get_user_by( 'email', $email );

	$args = array(
		'post_type'      => ATF_ENTRY_TYPE,
		// Named, not `'any'`: entry statuses are all `exclude_from_search`, which
		// `'any'` skips. An export that returns nothing and an erasure that erases
		// nothing both look like success.
		'post_status'    => atf_entry_statuses(),
		'posts_per_page' => $per_page,
		'paged'          => max( 1, (int) $page ),
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);

	// A `LIKE` over the values blob, because e-mail addresses live inside the
	// JSON rather than in a column. Narrow enough in practice -- a privacy
	// request is rare and runs in the background.
	$meta = array(
		array(
			'key'     => ATF_META_VALUES,
			'value'   => $email,
			'compare' => 'LIKE',
		),
	);

	if ( $user ) {
		$meta['relation'] = 'OR';
		$meta[]           = array(
			'key'     => ATF_META_CONTEXT,
			'value'   => '"userId":' . $user->ID . ',',
			'compare' => 'LIKE',
		);
	}

	$args['meta_query'] = $meta;

	$query = new WP_Query( $args );

	return array(
		'entries' => $query->posts,
		'done'    => count( $query->posts ) < $per_page,
	);
}

/**
 * Exports a person's form submissions.
 *
 * @since 0.1.0
 *
 * @param string $email The address the request is about.
 * @param int    $page  One-based page.
 * @return array The exporter's response.
 */
function atf_export_personal_data( $email, $page = 1 ) {
	$found = atf_find_entries_for_email( $email, $page );
	$items = array();

	foreach ( $found['entries'] as $post ) {
		$form_id = (int) get_post_meta( $post->ID, ATF_META_FORM, true );
		$schema  = atf_get_form_schema( $form_id );
		$values  = json_decode( (string) get_post_meta( $post->ID, ATF_META_VALUES, true ), true );
		$context = json_decode( (string) get_post_meta( $post->ID, ATF_META_CONTEXT, true ), true );

		$values  = is_array( $values ) ? $values : array();
		$context = is_array( $context ) ? $context : array();

		$data = array(
			array(
				'name'  => __( 'Form', 'allterrain-forms' ),
				'value' => get_the_title( $form_id ),
			),
			array(
				'name'  => __( 'Submitted', 'allterrain-forms' ),
				'value' => $post->post_date_gmt,
			),
		);

		foreach ( atf_input_fields( $schema ) as $field ) {
			if ( 'password' === $field['type'] ) {
				continue;
			}

			$value = isset( $values[ $field['id'] ] ) ? $values[ $field['id'] ] : '';
			$text  = atf_format_field_value( $value, $field, 'email' );

			if ( '' === trim( $text ) ) {
				continue;
			}

			$data[] = array(
				'name'  => '' !== $field['label'] ? $field['label'] : $field['id'],
				'value' => $text,
			);
		}

		if ( ! empty( $context['ip'] ) ) {
			$data[] = array(
				'name'  => __( 'IP address', 'allterrain-forms' ),
				'value' => $context['ip'],
			);
		}

		$items[] = array(
			'group_id'    => 'allterrain-forms-entries',
			'group_label' => __( 'Form submissions', 'allterrain-forms' ),
			'item_id'     => 'atf-entry-' . $post->ID,
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => $found['done'],
	);
}

/**
 * Erases a person's form submissions.
 *
 * Deletes the entry outright rather than anonymising it. An anonymised
 * submission still holds every answer the person gave -- which for a form is
 * usually the personal data itself, not merely metadata attached to it. There is
 * no version of "the message they typed" that survives anonymisation and is
 * still anonymous.
 *
 * @since 0.1.0
 *
 * @param string $email The address the request is about.
 * @param int    $page  One-based page.
 * @return array The eraser's response.
 */
function atf_erase_personal_data( $email, $page = 1 ) {
	// Always page 1: each pass deletes what it finds, so the next pass finds the
	// next batch at the same offset. Paging forward would skip half of them.
	$found   = atf_find_entries_for_email( $email, 1 );
	$removed = 0;

	foreach ( $found['entries'] as $post ) {
		atf_delete_entry_completely( $post->ID );
		++$removed;
	}

	return array(
		'items_removed'  => $removed > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => 0 === $removed,
	);
}

/**
 * Adds suggested privacy-policy text.
 *
 * Deliberately specific about what is stored and for how long, because the
 * generic text most plugins add ("we may collect data") helps nobody write a
 * real policy.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_register_privacy_policy_text() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = '<p>' . __( 'When you fill in a form on this site, we store the answers you give, along with the date and time you submitted them and — unless we have switched it off — the IP address you submitted from and your browser\'s user-agent string.', 'allterrain-forms' ) . '</p>'
		. '<p>' . __( 'Files you upload through a form are stored in a protected directory that is not reachable by URL, and are only accessible to site administrators.', 'allterrain-forms' ) . '</p>'
		. '<p>' . __( 'Submissions are kept until they are deleted, unless a retention period has been set on the form, in which case they are deleted automatically once that period has passed. You can ask for a copy of everything we hold about you, or ask us to delete it, and both requests are handled through this site\'s privacy tools.', 'allterrain-forms' ) . '</p>';

	wp_add_privacy_policy_content( 'AllTerrain Forms', wp_kses_post( wpautop( $content, false ) ) );
}
