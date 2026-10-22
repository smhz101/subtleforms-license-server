<?php
/**
 * The plans the server advertises match what SubtleForms Pro promises.
 *
 * SubtleForms Pro keeps a copy of the advertised table
 * (tests/unit/fixtures/advertised-plans.json in its repository) and checks
 * that every promise is a capability. This test keeps the server side of that
 * agreement: tests/fixtures/advertised-plans.json here is the same file, and
 * the plan feature labels, the common items, the plan features and the seats
 * must equal it. Set SFLS_PRO_DIR to a SubtleForms Pro checkout to also check
 * that this copy is identical to Pro's.
 *
 * @package SFLS
 */

/**
 * Advertised plans, labels and common items.
 */
class SFLS_Advertised_Plans_Test extends SFLS_Test_Case {

	/**
	 * The shared table.
	 *
	 * @return array
	 */
	private function fixture(): array {
		$file = dirname( __DIR__ ) . '/fixtures/advertised-plans.json';
		$data = json_decode( (string) file_get_contents( $file ), true );
		$this->assertIsArray( $data, 'The fixture is valid JSON.' );
		return $data;
	}

	/**
	 * The CRM and automation tools, and the moved features, are advertised.
	 */
	public function test_labels_name_the_new_integrations_and_the_moved_features(): void {
		$labels = SFLS_Plans::feature_labels();
		foreach ( array( 'Salesforce', 'Pipedrive', 'Close', 'Make', 'n8n', 'HubSpot', 'Zapier' ) as $name ) {
			$this->assertStringContainsString( $name, $labels['integrations'], "The integrations label names $name." );
		}
		$common = implode( ' | ', SFLS_Plans::common_items() );
		foreach ( array( 'Picture Choice', 'Multi-file uploads', 'Advanced conditional logic', 'form analytics' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $common, "The common items list $phrase." );
		}
	}

	/**
	 * Every non-empty label equals the one Pro is tested against.
	 */
	public function test_feature_labels_match_the_fixture(): void {
		$labels = SFLS_Plans::feature_labels();
		foreach ( $this->fixture()['feature_labels'] as $slug => $label ) {
			if ( '' === $label ) {
				// Covered by the common items, so the pricing cards never show it.
				$this->assertContains( $slug, SFLS_Plans::common_slugs(), "$slug has no advertised label, so it must be a common slug." );
				continue;
			}
			$this->assertSame( $label, $labels[ $slug ] ?? null, "Label of $slug." );
		}
	}

	/**
	 * The common items are the ones Pro maps to capabilities.
	 */
	public function test_common_items_match_the_fixture(): void {
		$this->assertSame( $this->fixture()['common_items'], SFLS_Plans::common_items() );
	}

	/**
	 * Plan features and seats are the ones Pro expects.
	 */
	public function test_plans_match_the_fixture(): void {
		$plans = SFLS_Plans::all();
		foreach ( $this->fixture()['plans'] as $slug => $expected ) {
			$this->assertArrayHasKey( $slug, $plans );
			$this->assertSame( $expected['features'], array_values( $plans[ $slug ]['features'] ), "Features of $slug." );
			$this->assertSame( (int) $expected['sites'], (int) $plans[ $slug ]['sites'], "Seats of $slug." );
		}
		$this->assertSame( array_keys( $this->fixture()['plans'] ), array_keys( $plans ) );
	}

	/**
	 * This copy is the same file as the one in SubtleForms Pro.
	 */
	public function test_fixture_is_the_same_as_in_pro_when_pro_is_at_hand(): void {
		$dir = getenv( 'SFLS_PRO_DIR' );
		if ( ! $dir || ! is_readable( rtrim( $dir, '/' ) . '/tests/unit/fixtures/advertised-plans.json' ) ) {
			$this->markTestSkipped( 'Set SFLS_PRO_DIR to a SubtleForms Pro checkout to compare the two copies.' );
		}
		$this->assertSame(
			file_get_contents( rtrim( $dir, '/' ) . '/tests/unit/fixtures/advertised-plans.json' ),
			file_get_contents( dirname( __DIR__ ) . '/fixtures/advertised-plans.json' )
		);
	}
}
