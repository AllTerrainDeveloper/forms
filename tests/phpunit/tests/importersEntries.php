<?php
/**
 * Migrating stored submissions, not just the forms that collected them.
 *
 * The records here are built exactly as Flamingo writes them — per-field
 * `_field_<name>` rows, a channel term, spam under Flamingo's own post status —
 * because that storage is the only contract available once Contact Form 7 and
 * Flamingo are switched off, which is precisely when somebody migrates.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Entry migration: the registry contract, the CF7/Flamingo reader, and the rules
 * about dates, spam and repeat runs.
 *
 * @group allterrain-forms
 */
class ATF_Test_Importers_Entries extends WP_UnitTestCase {

	/**
	 * A CF7 template with one field of each shape the messages below use.
	 *
	 * @var string
	 */
	const TEMPLATE = '<label> Your name
    [text* your-name] </label>

<label> Email
    [email* your-email] </label>

<label> Funding
    [select bid-funding "Cash buyer" "Mortgage approved in principle"] </label>

<label> Message
    [textarea your-message] </label>

[submit "Send"]';

	/**
	 * Every status an imported entry can land in.
	 *
	 * Spelled out because `'any'` would find none of them: entry statuses are
	 * registered `exclude_from_search`, and `'any'` means "every status not
	 * excluded from search". It is the same blind spot that hides Flamingo's
	 * spam status from a query on the other side of this migration.
	 *
	 * @var string[]
	 */
	const ENTRY_STATUSES = array( 'atf-unread', 'atf-read', 'atf-spam' );

	/**
	 * The CF7 form post id.
	 *
	 * @var int
	 */
	protected $cf7_id = 0;

	/**
	 * The Flamingo channel term id.
	 *
	 * @var int
	 */
	protected $channel_id = 0;

	/**
	 * Registers what Flamingo and CF7 would have registered, then builds a form.
	 */
	public function set_up() {
		parent::set_up();

		// Both post types belong to plugins that are not loaded in the test
		// suite. Registering them here is not a convenience: the importer is
		// specifically built to read this data with those plugins gone, and the
		// posts and terms are what survive them.
		register_post_type( 'wpcf7_contact_form', array( 'public' => false ) );
		register_post_type( 'flamingo_inbound', array( 'public' => false ) );
		register_post_status( 'flamingo-spam', array( 'exclude_from_search' => true ) );
		register_taxonomy( 'flamingo_inbound_channel', 'flamingo_inbound' );

		$this->cf7_id = self::factory()->post->create(
			array(
				'post_type'  => 'wpcf7_contact_form',
				'post_title' => 'Property Enquiry',
				'post_name'  => 'property-enquiry',
			)
		);

		update_post_meta( $this->cf7_id, '_form', self::TEMPLATE );

		$term             = wp_insert_term( 'Property Enquiry', 'flamingo_inbound_channel', array( 'slug' => 'property-enquiry' ) );
		$this->channel_id = (int) $term['term_id'];

		update_post_meta( $this->cf7_id, '_flamingo', array( 'channel' => $this->channel_id ) );

		// The plugin's capabilities are granted on activation, which does not
		// run in the suite -- an administrator has none of them until this does.
		atf_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Writes one stored message the way Flamingo does.
	 *
	 * @param array  $fields Field name => value.
	 * @param string $date   GMT date, `Y-m-d H:i:s`.
	 * @param bool   $spam   Whether it is spam.
	 * @return int The message post id.
	 */
	protected function add_message( $fields, $date, $spam = false ) {
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'flamingo_inbound',
				'post_status'   => $spam ? 'flamingo-spam' : 'publish',
				'post_title'    => 'Message',
				'post_date_gmt' => $date,
				'post_date'     => $date,
			)
		);

		foreach ( $fields as $name => $value ) {
			update_post_meta( $id, sanitize_key( '_field_' . $name ), $value );
		}

		// Flamingo nulls the values inside `_fields` as it writes the per-field
		// rows. Reproduced because an importer that read this array instead
		// would find names with nothing under them and import blank entries.
		update_post_meta( $id, '_fields', array_map( '__return_null', $fields ) );
		update_post_meta(
			$id,
			'_meta',
			array(
				'remote_ip'  => '203.0.113.7',
				'user_agent' => 'Mozilla/5.0 (Test)',
			)
		);

		wp_set_object_terms( $id, array( $this->channel_id ), 'flamingo_inbound_channel' );

		return $id;
	}

	/**
	 * Imports the CF7 form and returns the new form's id.
	 *
	 * @return int
	 */
	protected function import_form() {
		return atf_import_source_form( 'contact-form-7', (string) $this->cf7_id );
	}

	/**
	 * An importer offering only one half of the entry contract gets neither.
	 *
	 * A count with no import behind it would put a number on the screen that no
	 * button could act on.
	 *
	 * @covers ::atf_importers
	 */
	public function test_half_an_entry_contract_is_dropped() {
		$filter = static function ( $importers ) {
			$importers['halfway'] = array(
				'label'     => 'Halfway',
				'available' => '__return_true',
				'forms'     => '__return_empty_array',
				'import'    => '__return_zero',
				'entries'   => '__return_zero',
			);

			return $importers;
		};

		add_filter( 'atf_importers', $filter );
		$importers = atf_importers();
		remove_filter( 'atf_importers', $filter );

		$this->assertArrayHasKey( 'halfway', $importers, 'The importer itself is still valid.' );
		$this->assertArrayNotHasKey( 'entries', $importers['halfway'] );
		$this->assertArrayNotHasKey( 'import_entries', $importers['halfway'] );
	}

	/**
	 * Importing a form records where it came from and how its fields map.
	 *
	 * Without both, its stored submissions are unreadable later.
	 *
	 * @covers ::atf_create_imported_form
	 * @covers ::atf_form_import_source
	 * @covers ::atf_form_import_map
	 */
	public function test_import_records_source_and_map() {
		$form_id = $this->import_form();

		$this->assertNotWPError( $form_id );

		$source = atf_form_import_source( $form_id );

		$this->assertSame( 'contact-form-7', $source['importer'] );
		$this->assertSame( (string) $this->cf7_id, $source['source'] );

		$map = atf_form_import_map( $form_id );

		$this->assertArrayHasKey( 'your-name', $map );
		$this->assertArrayHasKey( 'bid-funding', $map );
	}

	/**
	 * Stored messages become entries, with their values under the new field ids.
	 *
	 * @covers ::atf_cf7_import_entries
	 * @covers ::atf_import_entry
	 */
	public function test_messages_become_entries() {
		$this->add_message(
			array(
				'your-name'    => 'Elena Ruiz',
				'your-email'   => 'elena.ruiz@example.com',
				'bid-funding'  => 'Cash buyer',
				'your-message' => 'Is the terrace south facing?',
			),
			'2025-03-04 09:15:00'
		);

		$form_id = $this->import_form();
		$result  = atf_import_form_entries( $form_id );

		$this->assertSame( 1, $result['imported'] );
		$this->assertTrue( $result['done'] );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => self::ENTRY_STATUSES,
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$this->assertCount( 1, $entries );

		$map    = atf_form_import_map( $form_id );
		$values = json_decode( get_post_meta( $entries[0]->ID, ATF_META_VALUES, true ), true );

		$this->assertSame( 'Elena Ruiz', $values[ $map['your-name'] ] );
		$this->assertSame( 'Is the terrace south facing?', $values[ $map['your-message'] ] );
	}

	/**
	 * An entry keeps the date it was submitted, not the date it was migrated.
	 *
	 * A history that all arrives today is not a history.
	 *
	 * @covers ::atf_import_entry
	 */
	public function test_original_date_is_kept() {
		$this->add_message( array( 'your-name' => 'Marcus Hale' ), '2024-11-19 14:02:00' );

		$form_id = $this->import_form();
		atf_import_form_entries( $form_id );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => self::ENTRY_STATUSES,
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$this->assertSame( '2024-11-19 14:02:00', $entries[0]->post_date_gmt );

		$context = json_decode( get_post_meta( $entries[0]->ID, ATF_META_CONTEXT, true ), true );

		$this->assertSame( '203.0.113.7', $context['ip'] );
		$this->assertSame( 'contact-form-7', $context['imported'] );
	}

	/**
	 * Spam arrives as spam rather than landing in the unread pile.
	 *
	 * Flamingo files spam under a status registered `exclude_from_search`, which
	 * is exactly the status a `post_status => 'any'` query silently omits — so
	 * this also pins that the reader does not use one.
	 *
	 * @covers ::atf_cf7_import_entries
	 */
	public function test_spam_is_imported_as_spam() {
		$this->add_message( array( 'your-name' => 'SEO Growth Team' ), '2025-01-02 08:00:00', true );
		$this->add_message( array( 'your-name' => 'Priya Nandra' ), '2025-01-03 08:00:00' );

		$form_id = $this->import_form();
		$result  = atf_import_form_entries( $form_id );

		$this->assertSame( 2, $result['imported'], 'The spam message was found as well as the published one.' );

		$spam = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => ATF_STATUS_SPAM,
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$this->assertCount( 1, $spam );
	}

	/**
	 * Running it twice does not import anything twice.
	 *
	 * The natural response to a migration that looks incomplete is to run it
	 * again, so it has to be safe to.
	 *
	 * @covers ::atf_cf7_import_entries
	 */
	public function test_second_run_imports_nothing_new() {
		$this->add_message( array( 'your-name' => 'Tomas Berg' ), '2025-02-01 10:00:00' );
		$this->add_message( array( 'your-name' => 'Sofia Marchetti' ), '2025-02-02 10:00:00' );

		$form_id = $this->import_form();

		$first = atf_import_form_entries( $form_id );
		$this->assertSame( 2, $first['imported'] );

		$second = atf_import_form_entries( $form_id );
		$this->assertSame( 0, $second['imported'] );
		$this->assertSame( 2, $second['skipped'] );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => self::ENTRY_STATUSES,
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$this->assertCount( 2, $entries );
	}

	/**
	 * A pass stops at the limit and says how much is left.
	 *
	 * @covers ::atf_cf7_import_entries
	 */
	public function test_import_is_chunked() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->add_message( array( 'your-name' => 'Person ' . $i ), sprintf( '2025-04-0%d 09:00:00', $i + 1 ) );
		}

		$form_id = $this->import_form();
		$result  = atf_import_form_entries( $form_id, 2 );

		$this->assertSame( 2, $result['imported'] );
		$this->assertFalse( $result['done'] );
		$this->assertSame( 3, $result['remaining'] );
	}

	/**
	 * The count reports what is still waiting, and reaches zero when done.
	 *
	 * @covers ::atf_cf7_entry_count
	 * @covers ::atf_forms_with_importable_entries
	 */
	public function test_count_falls_to_zero() {
		$this->add_message( array( 'your-name' => 'Ruth Ellery' ), '2025-05-01 09:00:00' );

		$form_id = $this->import_form();

		$this->assertSame( 1, atf_cf7_entry_count( (string) $this->cf7_id, $form_id ) );
		$this->assertArrayHasKey( $form_id, atf_forms_with_importable_entries() );

		atf_import_form_entries( $form_id );

		$this->assertSame( 0, atf_cf7_entry_count( (string) $this->cf7_id, $form_id ) );
		$this->assertArrayNotHasKey( $form_id, atf_forms_with_importable_entries() );
	}

	/**
	 * An answer the form no longer offers is kept, not thrown away.
	 *
	 * Sanitising runs; validation deliberately does not. A choice retired since
	 * the message was sent is still what that person said.
	 *
	 * @covers ::atf_import_entry
	 */
	public function test_retired_choice_survives() {
		$this->add_message(
			array(
				'your-name'   => 'Nils Andersen',
				'bid-funding' => 'Paying in gold bars',
			),
			'2025-06-01 09:00:00'
		);

		$form_id = $this->import_form();
		atf_import_form_entries( $form_id );

		$entries = get_posts(
			array(
				'post_type'      => ATF_ENTRY_TYPE,
				'post_status'    => self::ENTRY_STATUSES,
				'posts_per_page' => -1,
				'meta_key'       => ATF_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$map    = atf_form_import_map( $form_id );
		$values = json_decode( get_post_meta( $entries[0]->ID, ATF_META_VALUES, true ), true );

		$this->assertSame( 'Paying in gold bars', $values[ $map['bid-funding'] ] );
	}

	/**
	 * A form that was never imported has nothing to bring across.
	 *
	 * @covers ::atf_import_form_entries
	 */
	public function test_native_form_is_rejected() {
		$form_id = self::factory()->post->create( array( 'post_type' => ATF_FORM_TYPE ) );

		$result = atf_import_form_entries( $form_id );

		$this->assertWPError( $result );
		$this->assertSame( 'atf_not_imported', $result->get_error_code() );
	}

	/**
	 * The source's own records are never touched.
	 *
	 * @covers ::atf_cf7_import_entries
	 */
	public function test_source_is_left_alone() {
		$message_id = $this->add_message( array( 'your-name' => 'Aoife Brennan' ), '2025-07-01 09:00:00' );

		$form_id = $this->import_form();
		atf_import_form_entries( $form_id );

		$message = get_post( $message_id );

		$this->assertInstanceOf( 'WP_Post', $message );
		$this->assertSame( 'publish', $message->post_status );
		$this->assertSame( 'Aoife Brennan', get_post_meta( $message_id, '_field_your-name', true ) );
	}
}
