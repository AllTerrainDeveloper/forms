<?php
/**
 * Migrating Gravity Forms' stored entries.
 *
 * The records here are built exactly as Gravity writes them — `gf_entry` rows
 * with a status column, `gf_entry_meta` keyed by *input* id, where `5` is a
 * whole field and `5.3` is one input of a multi-input one. That shape is the
 * only contract available once Gravity Forms is switched off, which is
 * precisely when somebody migrates.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Entry migration from Gravity Forms: the recomputed field map, the entry
 * reader's shape assembly, and the rules about dates, spam and repeat runs.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Importers_Entries_Gravityforms extends WP_UnitTestCase {

	/**
	 * Every status an imported entry can land in.
	 *
	 * Spelled out because `'any'` would find none of them — entry statuses are
	 * registered `exclude_from_search`.
	 *
	 * @var string[]
	 */
	const ENTRY_STATUSES = array( 'alltfo-unread', 'alltfo-read', 'alltfo-spam' );

	/**
	 * The Gravity form id in the fixture tables.
	 *
	 * @var int
	 */
	protected $gf_id = 0;

	/**
	 * Builds Gravity's tables and one form.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Creating another plugin's tables is the fixture.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_form ( id mediumint(8) unsigned NOT NULL auto_increment, title varchar(150) NOT NULL, is_trash tinyint(1) NOT NULL default 0, PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_form_meta ( form_id mediumint(8) unsigned NOT NULL, display_meta longtext, notifications longtext, confirmations longtext, PRIMARY KEY (form_id) )" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_entry ( id int(10) unsigned NOT NULL auto_increment, form_id mediumint(8) unsigned NOT NULL, status varchar(20) NOT NULL default 'active', date_created datetime NOT NULL, ip varchar(39) NOT NULL default '', user_agent varchar(250) NOT NULL default '', PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gf_entry_meta ( id bigint(20) unsigned NOT NULL auto_increment, entry_id int(10) unsigned NOT NULL, meta_key varchar(255), meta_value longtext, PRIMARY KEY (id) )" );

		$wpdb->insert(
			$wpdb->prefix . 'gf_form',
			array(
				'title'    => 'Order Enquiry',
				'is_trash' => 0,
			)
		);
		$this->gf_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'gf_form_meta',
			array(
				'form_id'      => $this->gf_id,
				'display_meta' => wp_json_encode( $this->display_meta() ),
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		alltfo_add_capabilities();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Drops the fixture tables.
	 */
	public function tear_down() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the fixture; table names cannot be placeholders.
		foreach ( array( 'gf_form', 'gf_form_meta', 'gf_entry', 'gf_entry_meta' ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	/**
	 * A display_meta with one field of each shape the entries below use.
	 *
	 * The captcha in the middle matters: it converts to nothing, so the map
	 * must show a gap exactly where the conversion left one.
	 *
	 * @return array
	 */
	protected function display_meta() {
		return array(
			'title'  => 'Order Enquiry',
			'fields' => array(
				array(
					'type'   => 'name',
					'id'     => 1,
					'label'  => 'Name',
					'inputs' => array(
						array( 'id' => '1.3' ),
						array( 'id' => '1.6' ),
					),
				),
				array(
					'type'  => 'email',
					'id'    => 2,
					'label' => 'Email',
				),
				array(
					'type'    => 'checkbox',
					'id'      => 3,
					'label'   => 'Colours',
					'choices' => array(
						array(
							'text'  => 'Red',
							'value' => 'Red',
						),
						array(
							'text'  => 'Blue',
							'value' => 'Blue',
						),
					),
				),
				array(
					'type'    => 'multiselect',
					'id'      => 4,
					'label'   => 'Extras',
					'choices' => array(
						array(
							'text'  => 'Gift wrap',
							'value' => 'Gift wrap',
						),
						array(
							'text'  => 'Express',
							'value' => 'Express',
						),
					),
				),
				array(
					'type'          => 'list',
					'id'            => 5,
					'label'         => 'Items',
					'enableColumns' => true,
					'choices'       => array(
						array( 'text' => 'Item' ),
						array( 'text' => 'Qty' ),
					),
				),
				array(
					'type'  => 'address',
					'id'    => 6,
					'label' => 'Address',
				),
				array(
					'type' => 'captcha',
					'id'   => 7,
				),
				array(
					'type'  => 'textarea',
					'id'    => 8,
					'label' => 'Message',
				),
			),
		);
	}

	/**
	 * Writes one stored entry the way Gravity does.
	 *
	 * @param array  $meta   Input id => value.
	 * @param string $date   GMT date, `Y-m-d H:i:s`.
	 * @param string $status Gravity status: active, spam, trash.
	 * @return int The entry id.
	 */
	protected function add_entry( $meta, $date, $status = 'active' ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Writing the fixture.
		$wpdb->insert(
			$wpdb->prefix . 'gf_entry',
			array(
				'form_id'      => $this->gf_id,
				'status'       => $status,
				'date_created' => $date,
				'ip'           => '203.0.113.9',
				'user_agent'   => 'Mozilla/5.0 (Test)',
			)
		);
		$entry_id = (int) $wpdb->insert_id;

		foreach ( $meta as $key => $value ) {
			$wpdb->insert(
				$wpdb->prefix . 'gf_entry_meta',
				array(
					'entry_id'   => $entry_id,
					'meta_key'   => (string) $key,
					'meta_value' => (string) $value,
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $entry_id;
	}

	/**
	 * Imports the Gravity form and returns the new form's id.
	 *
	 * @return int
	 */
	protected function import_form() {
		return alltfo_import_source_form( 'gravityforms', (string) $this->gf_id );
	}

	/**
	 * The entries stored against a form, oldest first.
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
	 * @covers ::alltfo_gf_map
	 */
	public function test_map_matches_conversion() {
		$map = alltfo_gf_map( $this->display_meta() );

		$this->assertSame( 'f1', $map['1'] );
		$this->assertSame( 'f6', $map['6'] );
		$this->assertSame( 'f8', $map['8'], 'Ids keep counting past the dropped captcha.' );
		$this->assertArrayNotHasKey( '7', $map, 'A captcha converts to nothing, so it maps to nothing.' );
	}

	/**
	 * Every stored shape arrives as the shape its new field stores.
	 *
	 * @covers ::alltfo_gf_import_entries
	 * @covers ::alltfo_gf_entry_values
	 * @covers ::alltfo_gf_entry_value
	 */
	public function test_shapes_survive_the_trip() {
		$this->add_entry(
			array(
				'1.3' => 'Elena',
				'1.6' => 'Ruiz',
				'2'   => 'elena.ruiz@example.com',
				'3.1' => 'Red',
				'3.2' => 'Blue',
				'4'   => '["Gift wrap","Express"]',
				'5'   => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Gravity itself serialises List values; the fixture reproduces its storage.
					array(
						array(
							'Item' => 'Widget',
							'Qty'  => '2',
						),
						array(
							'Item' => 'Bolt',
							'Qty'  => '5',
						),
					)
				),
				'6.1' => '12 High Street',
				'6.3' => 'London',
				'6.5' => 'SW1A 1AA',
				'6.6' => 'United Kingdom',
				'8'   => "Two lines\nof message",
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
			$values[ $map['1'] ]
		);
		$this->assertSame( 'elena.ruiz@example.com', $values[ $map['2'] ] );
		$this->assertSame( array( 'Red', 'Blue' ), $values[ $map['3'] ] );
		$this->assertSame( array( 'Gift wrap', 'Express' ), $values[ $map['4'] ] );
		$this->assertSame(
			array(
				array(
					's1' => 'Widget',
					's2' => '2',
				),
				array(
					's1' => 'Bolt',
					's2' => '5',
				),
			),
			$values[ $map['5'] ]
		);
		$this->assertSame(
			array(
				'line1'    => '12 High Street',
				'city'     => 'London',
				'postcode' => 'SW1A 1AA',
				'country'  => 'United Kingdom',
			),
			$values[ $map['6'] ]
		);
		$this->assertSame( "Two lines\nof message", $values[ $map['8'] ] );

		$this->assertSame( '2025-03-04 09:15:00', $entries[0]->post_date_gmt );

		$context = json_decode( get_post_meta( $entries[0]->ID, ALLTFO_META_CONTEXT, true ), true );

		$this->assertSame( '203.0.113.9', $context['ip'] );
		$this->assertSame( 'gravityforms', $context['imported'] );
	}

	/**
	 * Spam arrives as spam; trash stays where somebody put it.
	 *
	 * @covers ::alltfo_gf_import_entries
	 * @covers ::alltfo_gf_entry_count
	 */
	public function test_spam_comes_and_trash_stays() {
		$this->add_entry( array( '2' => 'seo.team@example.net' ), '2025-01-02 08:00:00', 'spam' );
		$this->add_entry( array( '2' => 'priya@example.com' ), '2025-01-03 08:00:00' );
		$this->add_entry( array( '2' => 'binned@example.com' ), '2025-01-04 08:00:00', 'trash' );

		$form_id = $this->import_form();

		$this->assertSame( 2, alltfo_gf_entry_count( (string) $this->gf_id, $form_id ), 'Trash is not offered.' );

		$result = alltfo_import_form_entries( $form_id );

		$this->assertSame( 2, $result['imported'] );
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

		$this->assertCount( 1, $spam );
	}

	/**
	 * Running it twice imports nothing twice, and chunking says what is left.
	 *
	 * @covers ::alltfo_gf_import_entries
	 */
	public function test_second_run_and_chunking() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->add_entry( array( '2' => "person{$i}@example.com" ), sprintf( '2025-04-0%d 09:00:00', $i + 1 ) );
		}

		$form_id = $this->import_form();

		$first = alltfo_import_form_entries( $form_id, 2 );

		$this->assertSame( 2, $first['imported'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 1, $first['remaining'] );

		$second = alltfo_import_form_entries( $form_id, 2 );

		$this->assertSame( 1, $second['imported'] );
		$this->assertSame( 2, $second['skipped'] );
		$this->assertTrue( $second['done'] );

		$this->assertCount( 3, $this->entries( $form_id ) );
	}

	/**
	 * The source tables are never touched.
	 *
	 * @covers ::alltfo_gf_import_entries
	 */
	public function test_source_is_left_alone() {
		global $wpdb;

		$entry_id = $this->add_entry( array( '2' => 'aoife@example.com' ), '2025-07-01 09:00:00' );

		$form_id = $this->import_form();
		alltfo_import_form_entries( $form_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Asserting against the fixture.
		$this->assertSame( 'active', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}gf_entry WHERE id = %d", $entry_id ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = '2'", $entry_id ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
