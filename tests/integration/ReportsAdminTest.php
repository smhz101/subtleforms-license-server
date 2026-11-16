<?php
/**
 * R3: saved reports, REST route and CSV export.
 *
 * @package SFLS
 */

/**
 * Reports admin tests.
 */
class SFLS_Reports_Admin_Test extends SFLS_Test_Case {

	/**
	 * Report IDs to remove.
	 *
	 * @var int[]
	 */
	private array $reports = array();

	/**
	 * Remove the fixtures.
	 */
	protected function tearDown(): void {
		foreach ( $this->reports as $id ) {
			SFLS_Reports::delete( $id );
		}
		parent::tearDown();
	}

	/**
	 * Make an administrator.
	 */
	private function admin(): WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => 'rpt' . wp_generate_password( 6, false ),
				'user_email' => 'rpt' . wp_generate_password( 6, false ) . '@sfls-reports.example.net',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		$this->users[] = $id;
		return get_userdata( $id );
	}

	/**
	 * Save a report.
	 *
	 * @param int  $owner  Owner.
	 * @param bool $shared Shared.
	 */
	private function report( int $owner, bool $shared ): int {
		$id = SFLS_Reports::save(
			array(
				'name'       => 'rpt-test ' . wp_generate_password( 4, false ),
				'user_id'    => $owner,
				'shared'     => $shared,
				'definition' => array(
					'dataset'  => 'licenses',
					'group_by' => array( 'plan' ),
				),
			)
		);
		$this->reports[] = $id;
		return $id;
	}

	/**
	 * Private is for the owner, shared for everyone with the capability.
	 */
	public function test_visibility(): void {
		$a = $this->admin();
		$b = $this->admin();
		$c = $this->make_user();
		$private = SFLS_Reports::find( $this->report( $a->ID, false ) );
		$shared  = SFLS_Reports::find( $this->report( $a->ID, true ) );
		$this->assertTrue( SFLS_Reports::can_view( $private, $a->ID ) );
		$this->assertFalse( SFLS_Reports::can_view( $private, $b->ID ) );
		$this->assertTrue( SFLS_Reports::can_view( $shared, $b->ID ) );
		$this->assertFalse( SFLS_Reports::can_view( $shared, $c->ID ), 'Needs the capability.' );
		$ids_b = wp_list_pluck( SFLS_Reports::visible_to( $b->ID ), 'id' );
		$this->assertContains( $shared['id'], $ids_b );
		$this->assertNotContains( $private['id'], $ids_b );
		$this->assertSame( array( 'dataset' => 'licenses', 'group_by' => array( 'plan' ) ), $shared['definition'] );
	}

	/**
	 * REST: capability and visibility.
	 */
	public function test_rest_permission(): void {
		$a       = $this->admin();
		$b       = $this->admin();
		$private = $this->report( $a->ID, false );
		$shared  = $this->report( $a->ID, true );

		$this->assertSame( 401, $this->rest( 'GET', "/sfls/v1/reports/{$shared}" )->get_status() );

		wp_set_current_user( $this->make_user()->ID );
		$this->assertSame( 403, $this->rest( 'GET', "/sfls/v1/reports/{$shared}" )->get_status() );

		wp_set_current_user( $b->ID );
		$this->assertSame( 404, $this->rest( 'GET', "/sfls/v1/reports/{$private}" )->get_status() );
		$ok = $this->rest( 'GET', "/sfls/v1/reports/{$shared}" );
		$this->assertSame( 200, $ok->get_status() );
		$data = $ok->get_data();
		$this->assertSame( array( 'plan', 'count' ), wp_list_pluck( $data['columns'], 'key' ) );

		wp_set_current_user( $a->ID );
		$this->assertSame( 200, $this->rest( 'GET', "/sfls/v1/reports/{$private}" )->get_status() );

		$this->filter(
			'sfls_reports_capability',
			static fn() => 'sfls_no_such_cap'
		);
		$this->assertSame( 403, $this->rest( 'GET', "/sfls/v1/reports/{$shared}" )->get_status() );
	}

	/**
	 * CSV cells that start with a formula character are quoted off.
	 */
	public function test_csv_escapes_formulas(): void {
		$csv = SFLS_Reports_Admin::csv(
			array(
				'columns'    => array(
					array(
						'key'   => 'a',
						'label' => '=bad',
						'type'  => 'string',
					),
					array(
						'key'   => 'n',
						'label' => 'N',
						'type'  => 'number',
					),
				),
				'rows'       => array(
					array(
						'a' => '=HYPERLINK("x")',
						'n' => -5,
					),
					array(
						'a' => '+1',
						'n' => 2.5,
					),
					array(
						'a' => '-2',
						'n' => 3,
					),
					array(
						'a' => '@SUM(1)',
						'n' => 4,
					),
					array(
						'a' => 'plain',
						'n' => 5,
					),
				),
				'total_rows' => 5,
			)
		);
		$lines = array_map( 'str_getcsv', array_filter( explode( "\n", $csv ) ) );
		$this->assertSame( "'=bad", $lines[0][0] );
		$this->assertSame( "'=HYPERLINK(\"x\")", $lines[1][0] );
		$this->assertSame( '-5', $lines[1][1], 'Numbers stay numbers.' );
		$this->assertSame( "'+1", $lines[2][0] );
		$this->assertSame( "'-2", $lines[3][0] );
		$this->assertSame( "'@SUM(1)", $lines[4][0] );
		$this->assertSame( 'plain', $lines[5][0] );
	}

	/**
	 * Builder input is reduced to whitelisted fields of the chosen dataset.
	 */
	public function test_definition_from_input(): void {
		$def = SFLS_Reports_Admin::definition_from_input(
			array(
				'dataset'  => 'refunds',
				'group_by' => array( 'plan', 'country', 'currency', 'status' ),
				'measures' => array( array( 'fn' => 'sum', 'field' => 'refunded' ), array( 'fn' => 'sum', 'field' => 'plan' ) ),
				'filters'  => array( array( 'field' => 'plan', 'op' => 'eq', 'value' => 'x' ), array( 'field' => 'status', 'op' => 'eq', 'value' => 'wc-completed' ) ),
				'display'  => 'evil',
			)
		);
		$this->assertSame( array( 'country' ), $def['group_by'] );
		$this->assertCount( 1, $def['measures'] );
		$this->assertCount( 1, $def['filters'] );
		$this->assertSame( 'table', $def['display'] );
	}
}
