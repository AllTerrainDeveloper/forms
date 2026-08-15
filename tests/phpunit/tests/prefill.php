<?php
/**
 * Pre-filling a field's opening value.
 *
 * The sources are a small closed set, and the builder now offers them as a list
 * rather than as a text box under a hint. That makes the *set* the contract: a
 * source the builder offers and the resolver does not understand produces an
 * empty field and no error, which is the failure this file exists to prevent —
 * it looks exactly like a visitor who simply had nothing to pre-fill.
 *
 * `query:` gets the most attention because it is the one source whose value is
 * unsanitised visitor input arriving in a field that will be echoed back into
 * the page.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * Pre-filling a field’s opening value.
 *
 * @group allterrain-forms
 */
class ATF_Test_Prefill extends WP_UnitTestCase {

	/**
	 * A one-field schema whose field pre-fills from a source.
	 *
	 * @param string $source The prefill source.
	 * @param string $type   The field type.
	 * @return array The normalised schema.
	 */
	private function schema_with( $source, $type = 'text' ) {
		return atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => $type,
						'label'   => 'A question',
						'prefill' => $source,
					),
				),
			)
		);
	}

	/**
	 * The field, normalised, so the resolver gets what it would in production.
	 *
	 * @param string $type The field type.
	 * @return array The normalised field.
	 */
	private function field( $type = 'text' ) {
		return $this->schema_with( '', $type )['fields'][0];
	}

	/**
	 * Every source the builder offers actually resolves.
	 *
	 * The list here mirrors `PREFILL_SOURCES` in `src/builder.ts`. Two of the
	 * five examples the old free-text hint gave — `user:name` and `site:name` —
	 * were not sources at all, so anybody who typed what the hint said got an
	 * empty field and nothing to tell them why. A closed list only helps if
	 * everything on it works.
	 *
	 * @dataProvider data_offered_sources
	 * @covers ::atf_resolve_prefill
	 *
	 * @param string $source The source string.
	 */
	public function test_every_offered_source_resolves( $source ) {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'         => 'administrator',
					'user_email'   => 'ada@example.com',
					'user_login'   => 'ada',
					'display_name' => 'Ada Lovelace',
					'first_name'   => 'Ada',
					'last_name'    => 'Lovelace',
				)
			)
		);

		$this->assertNotSame(
			'',
			atf_resolve_prefill( $source, $this->field() ),
			sprintf( 'The builder offers "%s" but nothing resolves it, so the field opens empty.', $source )
		);
	}

	/**
	 * The sources the builder's picker lists.
	 *
	 * @return array[]
	 */
	public function data_offered_sources() {
		$sources = array(
			'user:email',
			'user:display_name',
			'user:first_name',
			'user:last_name',
			'user:login',
			'date:today',
			'date:now',
			'site',
			'site:url',
			'site:admin_email',
		);

		$cases = array();

		foreach ( $sources as $source ) {
			$cases[ $source ] = array( $source );
		}

		return $cases;
	}

	/**
	 * `date:` honours its key.
	 *
	 * The bug this pins: the key was read and then ignored, so `date:today`,
	 * `date:now` and `date:anything` all produced the same `Y-m-d` — while the
	 * builder's hint said `date:today` as though the word mattered. A time field
	 * pre-filled with `date:now` got a date in it.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_date_sources_differ() {
		$today = atf_resolve_prefill( 'date:today', $this->field( 'date' ) );
		$now   = atf_resolve_prefill( 'date:now', $this->field( 'time' ) );

		$this->assertSame( wp_date( 'Y-m-d' ), $today );
		$this->assertSame( wp_date( 'H:i' ), $now );
		$this->assertNotSame( $today, $now, 'A time field pre-filled with the time must not get a date.' );
	}

	/**
	 * A bare `date:` still means today, because it always did.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_a_bare_date_source_is_still_today() {
		$this->assertSame( wp_date( 'Y-m-d' ), atf_resolve_prefill( 'date', $this->field( 'date' ) ) );
	}

	/**
	 * A `query:` source reads the URL and is sanitised by the field's own type.
	 *
	 * This value is a stranger's input arriving in a field that gets echoed back
	 * into the page. Sanitising through the field type rather than generically is
	 * what makes a number field reject a script tag by being a number field.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_a_query_source_is_sanitised() {
		$_GET['utm_source'] = '<script>alert(1)</script>newsletter';

		$value = atf_resolve_prefill( 'query:utm_source', $this->field() );

		unset( $_GET['utm_source'] );

		$this->assertStringNotContainsString( '<script', $value );
		$this->assertStringContainsString( 'newsletter', $value );
	}

	/**
	 * A `query:` source that is not in the URL fills nothing.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_a_missing_query_parameter_fills_nothing() {
		$this->assertSame( '', atf_resolve_prefill( 'query:not_here', $this->field() ) );
	}

	/**
	 * A source nobody recognises fills nothing rather than the source string.
	 *
	 * The opposite choice to merge tags, and deliberately so: an unresolved merge
	 * tag prints itself because brace-shaped text is often not a tag at all, but
	 * a prefill source is never anything else, and echoing `myplugin:whatever`
	 * into a visitor's name box would be worse than leaving it empty.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_an_unknown_source_fills_nothing() {
		$this->assertSame( '', atf_resolve_prefill( 'nonsense:here', $this->field() ) );
	}

	/**
	 * A plugin can add a source.
	 *
	 * @covers ::atf_resolve_prefill
	 */
	public function test_a_plugin_can_add_a_source() {
		add_filter(
			'atf_resolve_prefill',
			static function ( $value, $source ) {
				return 'crm:ref' === $source ? 'CRM-1234' : $value;
			},
			10,
			2
		);

		$this->assertSame( 'CRM-1234', atf_resolve_prefill( 'crm:ref', $this->field() ) );
	}

	/**
	 * A field's own default survives a source that resolves to nothing.
	 *
	 * Otherwise adding a pre-fill would silently wipe the default for every
	 * visitor the source does not apply to — a logged-out one, most of the time.
	 *
	 * @covers ::atf_prefill_values
	 */
	public function test_the_default_survives_an_empty_source() {
		wp_set_current_user( 0 );

		$schema = atf_normalize_schema(
			array(
				'fields' => array(
					array(
						'id'      => 'f1',
						'type'    => 'text',
						'label'   => 'A question',
						'default' => 'Fallback',
						'prefill' => 'user:email',
					),
				),
			)
		);

		$this->assertSame( 'Fallback', atf_prefill_values( $schema )['f1'] );
	}

	/**
	 * Every offered source survives being stored and read back.
	 *
	 * The builder writes the source into the schema and the schema is sanitised
	 * on every read *and* every write. A source mangled on the way through would
	 * resolve to nothing on the front end while still looking correct in the
	 * picker, because the picker reads the same mangled value back and matches it
	 * against nothing.
	 *
	 * @dataProvider data_storable_sources
	 * @covers ::atf_normalize_field
	 *
	 * @param string $source The source string.
	 */
	public function test_a_source_survives_a_round_trip( $source ) {
		$stored = $this->schema_with( $source )['fields'][0]['prefill'];

		$this->assertSame( $source, $stored );
	}

	/**
	 * The sources, plus the `query:` shapes the picker builds.
	 *
	 * @return array[]
	 */
	public function data_storable_sources() {
		$cases = $this->data_offered_sources();

		foreach ( array( 'query:utm_source', 'query:utm_campaign', 'query:ref', 'myplugin:thing' ) as $source ) {
			$cases[ $source ] = array( $source );
		}

		return $cases;
	}
}
