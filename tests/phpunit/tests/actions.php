<?php
/**
 * Post-submit actions.
 *
 * The webhook is the one action that leaves the site, which makes it the one
 * whose transport is a security property and not an implementation detail.
 *
 * @package AllTerrain_Forms
 * @group allterrain-forms
 */

/**
 * The webhook action's transport guarantees.
 *
 * @group allterrain-forms
 */
class ALLTFO_Test_Actions extends WP_UnitTestCase {

	/**
	 * The webhook refuses to travel unsafely.
	 *
	 * The URL is validated before the request, but only the first URL — an
	 * endpoint answering 302 chooses where the follow-up goes, internal
	 * addresses included. `reject_unsafe_urls` is what makes WordPress
	 * re-validate every hop, and it must be on for every webhook request.
	 *
	 * @covers ::alltfo_action_webhook
	 */
	public function test_webhook_rejects_unsafe_urls() {
		$form_id = alltfo_test_form(
			array(
				'fields' => array(
					array(
						'id'    => 'f1',
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);

		$seen = null;

		$capture = static function ( $pre, $args, $url ) use ( &$seen ) {
			$seen = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $capture, 10, 3 );

		// The site's own host, because the tests container has no DNS and
		// `wp_http_validate_url()` refuses a host it cannot resolve — the
		// same-host case is the one it accepts without asking a resolver.
		$hook_url = home_url( '/hook' );

		$result = alltfo_action_webhook(
			array(
				'url'    => $hook_url,
				'secret' => 'a-shared-secret',
			),
			array(
				'schema'   => alltfo_get_form_schema( $form_id ),
				'values'   => array( 'f1' => 'Ada' ),
				'form_id'  => $form_id,
				'entry_id' => 0,
			)
		);

		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $seen, 'The request was attempted.' );
		$this->assertSame( $hook_url, $seen['url'] );
		$this->assertTrue( ! empty( $seen['args']['reject_unsafe_urls'] ), 'Every hop of a webhook request is validated, not just the first URL.' );
		$this->assertArrayHasKey( 'X-ATF-Signature', $seen['args']['headers'], 'A configured secret signs the payload.' );
	}
}
