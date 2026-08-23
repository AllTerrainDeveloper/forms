<?php
/**
 * Migrating WPForms' stored entries.
 *
 * The records here are built exactly as WPForms Pro writes them — one
 * `wpforms_entries` row per submission, the answers as one JSON document in the
 * `fields` column, composites keeping their parts in sibling keys and choice
 * groups newline-joining their picks. That shape is the only contract
 * available once WPForms is switched off, which is precisely when somebody
 * migrates.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Entry migration from WPForms: the recomputed field map, the fields-JSON
 * reader, and the statuses that come across or stay behind.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Importers_Entries_Wpforms extends WP_UnitTestCase {

	/**
	 * Every status an imported entry can land in.
	 *
	 * @var string[]
	 */
	const ENTRY_STATUSES = array( 'alltfo-unread', 'alltfo-read', 'alltfo-spam' );

	/**
	 * The WPForms form post id.
	 *
	 * @var int
	 */
	protected $wpf_id = 0;

	/**
	 * Builds the entries table and one WPForms form post.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Creating another plugin's table is the fixture.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}wpforms_entries ( entry_id bigint(20) NOT NULL auto_increment, form_id bigint(20) NOT NULL, status varchar(30) NOT NULL default '', fields longtext, date datetime NOT NULL, ip_address varchar(128) NOT NULL default '', user_agent varchar(256) NOT NULL default '', PRIMARY KEY (entry_id) )" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->wpf_id = self::factory()->post->create(
			array(
				'post_type'    => 'wpforms',
				'post_title'   => 'Booking Request',
				'post_content' => wp_slash( wp_json_encode( $this->document() ) ),
			)
		);

		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Drops the fixture table.
	 */
	public function tear_down() {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpforms_entries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dropping the fixture.

		parent::tear_down();
	}

	/**
	 * A WPForms document with one field of each shape the entries below use.
	 *
	 * The captcha in the middle matters: it converts to nothing, so the map
	 * must show a gap exactly where the conversion left one.
	 *
	 * @return array
	 */
	protected function document() {
		return array(
			'id'       => 7,
			'fields'   => array(
				array(
					'id'     => 0,
					'type'   => 'name',
					'format' => 'first-last',
					'label'  => 'Name',
				),
				array(
					'id'    => 1,
					'type'  => 'email',
					'label' => 'Email',
				),
				array(
					'id'      => 2,
					'type'    => 'checkbox',
					'label'   => 'Rooms',
					'choices' => array(
						array( 'label' => 'Garden room' ),
						array( 'label' => 'Sea view' ),
					),
				),
				array(
					'id'    => 3,
					'type'  => 'address',
					'label' => 'Address',
				),
				array(
					'id'   => 4,
					'type' => 'captcha',
				),
				array(
					'id'    => 5,
					'type'  => 'textarea',
					'label' => 'Requests',
				),
			),
			'settings' => array( 'form_title' => 'Booking Request' ),
		);
	}

	/**
	 * Writes one stored entry the way WPForms Pro does.
	 *
	 * @param array  $fields Field id => stored field object.
	 * @param string $date   GMT date, `Y-m-d H:i:s`.
	 * @param string $status WPForms status: '' for ordinary, spam, trash, partial, abandoned.
	 * @return int The entry id.
	 */
	protected function add_entry( $fields, $date, $status = '' ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Writing the fixture.
		$wpdb->insert(
			$wpdb->prefix . 'wpforms_entries',
			array(
				'form_id'    => $this->wpf_id,
				'status'     => $status,
				'fields'     => wp_json_encode( $fields ),
				'date'       => $date,
				'ip_address' => '203.0.113.11',
				'user_agent' => 'Mozilla/5.0 (Test)',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Imports the WPForms form and returns the new form's id.
	 *
	 * @return int
	 */
	protected function import_form() {
		return alltfo_import_source_form( 'wpforms', (string) $this->wpf_id );
	}

	/**
	 * The entries stored against a form.
	 *
	 * @param int $form_id The form.
	 * @return WP_Post[]
	 */
	protected function entries( $form_id ) {
		return get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => self::ENTRY_STATUSES,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => ALLTFO_META_FORM,
				'meta_value'     => $form_id,
			)
		);
	}

	/**
	 * The recomputed map mints the same ids the conversion did — gaps included.
	 *
	 * @covers ::alltfo_wpforms_map
	 */
	public function test_map_matches_conversion() {
		$map = alltfo_wpforms_map( $this->document() );

		$this->assertSame( 'f1', $map['0'] );
		$this->assertSame( 'f4', $map['3'] );
		$this->assertSame( 'f6', $map['5'], 'Ids keep counting past the dropped captcha.' );
		$this->assertArrayNotHasKey( '4', $map, 'A captcha converts to nothing, so it maps to nothing.' );
	}

	/**
	 * Every stored shape arrives as the shape its new field stores.
	 *
	 * @covers ::alltfo_wpforms_import_entries
	 * @covers ::alltfo_wpforms_entry_values
	 * @covers ::alltfo_wpforms_entry_value
	 */
	public function test_shapes_survive_the_trip() {
		$this->add_entry(
			array(
				'0' => array(
					'id'    => 0,
					'type'  => 'name',
					'value' => 'Elena Ruiz',
					'first' => 'Elena',
					'last'  => 'Ruiz',
				),
				'1' => array(
					'id'    => 1,
					'type'  => 'email',
					'value' => 'elena.ruiz@example.com',
				),
				'2' => array(
					'id'    => 2,
					'type'  => 'checkbox',
					'value' => "Garden room\nSea view",
				),
				'3' => array(
					'id'       => 3,
					'type'     => 'address',
					'value'    => "12 High Street\nLondon, SW1A 1AA",
					'address1' => '12 High Street',
					'address2' => '',
					'city'     => 'London',
					'state'    => '',
					'postal'   => 'SW1A 1AA',
					'country'  => 'GB',
				),
				'5' => array(
					'id'    => 5,
					'type'  => 'textarea',
					'value' => "A cot, please.\nAnd a late checkout.",
				),
			),
			'2025-03-04 09:15:00'
		);

		$form_id = $this->import_form();
		$result  = alltfo_import_form_entries( $form_id );

		$this->assertSame( 1, $result['imported'] );

		$entries = $this->entries( $form_id );

		$this->assertCount( 1, $entries );

		$map    = alltfo_form_import_map( $form_id );
		$values = json_decode( get_post_meta( $entries[0]->ID, ALLTFO_META_VALUES, true ), true );

		$this->assertSame(
			array(
				'first' => 'Elena',
				'last'  => 'Ruiz',
			),
			$values[ $map['0'] ]
		);
		$this->assertSame( 'elena.ruiz@example.com', $values[ $map['1'] ] );
		$this->assertSame( array( 'Garden room', 'Sea view' ), $values[ $map['2'] ] );
		$this->assertSame(
			array(
				'line1'    => '12 High Street',
				'city'     => 'London',
				'postcode' => 'SW1A 1AA',
				'country'  => 'GB',
			),
			$values[ $map['3'] ]
		);
		$this->assertSame( "A cot, please.\nAnd a late checkout.", $values[ $map['5'] ] );

		$this->assertSame( '2025-03-04 09:15:00', $entries[0]->post_date_gmt );

		$context = json_decode( get_post_meta( $entries[0]->ID, ALLTFO_META_CONTEXT, true ), true );

		$this->assertSame( '203.0.113.11', $context['ip'] );
		$this->assertSame( 'wpforms', $context['imported'] );
	}

	/**
	 * Spam comes across as spam; trash and half-typed forms stay behind.
	 *
	 * `partial` and `abandoned` are forms nobody ever sent — importing them
	 * would put entries on the screen that no visitor believes they submitted.
	 *
	 * @covers ::alltfo_wpforms_import_entries
	 * @covers ::alltfo_wpforms_entry_count
	 */
	public function test_statuses_sort_themselves() {
		$answer = static function ( $email ) {
			return array(
				'1' => array(
					'id'    => 1,
					'type'  => 'email',
					'value' => $email,
				),
			);
		};

		$this->add_entry( $answer( 'seo.team@example.net' ), '2025-01-02 08:00:00', 'spam' );
		$this->add_entry( $answer( 'priya@example.com' ), '2025-01-03 08:00:00' );
		$this->add_entry( $answer( 'paid@example.com' ), '2025-01-04 08:00:00', 'completed' );
		$this->add_entry( $answer( 'binned@example.com' ), '2025-01-05 08:00:00', 'trash' );
		$this->add_entry( $answer( 'halfway@example.com' ), '2025-01-06 08:00:00', 'partial' );
		$this->add_entry( $answer( 'gone@example.com' ), '2025-01-07 08:00:00', 'abandoned' );

		$form_id = $this->import_form();

		$this->assertSame( 3, alltfo_wpforms_entry_count( (string) $this->wpf_id, $form_id ) );

		$result = alltfo_import_form_entries( $form_id );

		$this->assertSame( 3, $result['imported'] );
		$this->assertTrue( $result['done'] );

		$spam = get_posts(
			array(
				'post_type'      => ALLTFO_ENTRY_TYPE,
				'post_status'    => ALLTFO_STATUS_SPAM,
				'posts_per_page' => -1,
				'meta_key'       => ALLTFO_META_FORM,
				'meta_value'     => $form_id,
			)
		);

		$this->assertCount( 1, $spam, 'A payment entry is an ordinary entry, not spam.' );
	}

	/**
	 * Running it twice imports nothing twice.
	 *
	 * @covers ::alltfo_wpforms_import_entries
	 */
	public function test_second_run_imports_nothing_new() {
		$this->add_entry(
			array(
				'1' => array(
					'id'    => 1,
					'type'  => 'email',
					'value' => 'tomas@example.com',
				),
			),
			'2025-02-01 10:00:00'
		);

		$form_id = $this->import_form();

		$first = alltfo_import_form_entries( $form_id );
		$this->assertSame( 1, $first['imported'] );

		$second = alltfo_import_form_entries( $form_id );
		$this->assertSame( 0, $second['imported'] );
		$this->assertSame( 1, $second['skipped'] );

		$this->assertCount( 1, $this->entries( $form_id ) );
	}

	/**
	 * A Lite site — no entries table at all — is simply done, not broken.
	 *
	 * @covers ::alltfo_wpforms_import_entries
	 * @covers ::alltfo_wpforms_entry_count
	 */
	public function test_lite_site_has_nothing_to_bring() {
		global $wpdb;

		$form_id = $this->import_form();

		$wpdb->query( "DROP TABLE {$wpdb->prefix}wpforms_entries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removing the fixture is the scenario.

		$this->assertSame( 0, alltfo_wpforms_entry_count( (string) $this->wpf_id, $form_id ) );

		$result = alltfo_import_form_entries( $form_id );

		$this->assertSame( 0, $result['imported'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The source table is never touched.
	 *
	 * @covers ::alltfo_wpforms_import_entries
	 */
	public function test_source_is_left_alone() {
		global $wpdb;

		$entry_id = $this->add_entry(
			array(
				'1' => array(
					'id'    => 1,
					'type'  => 'email',
					'value' => 'aoife@example.com',
				),
			),
			'2025-07-01 09:00:00'
		);

		$form_id = $this->import_form();
		alltfo_import_form_entries( $form_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Asserting against the fixture.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, fields FROM {$wpdb->prefix}wpforms_entries WHERE entry_id = %d", $entry_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->assertSame( '', $row->status );
		$this->assertStringContainsString( 'aoife@example.com', $row->fields );
	}
}
