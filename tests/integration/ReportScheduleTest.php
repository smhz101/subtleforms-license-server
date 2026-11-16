<?php
/**
 * R4: scheduled report emails.
 *
 * @package SFLS
 */

/**
 * Report schedule tests.
 */
class SFLS_Report_Schedule_Test extends SFLS_Test_Case {

	const MAIL = 'sfls-std.example.net';

	/**
	 * Sent mails: [ to, subject, attachment name, attachment text ].
	 *
	 * @var array[]
	 */
	private array $sent = array();

	/**
	 * Attachment paths seen.
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * Whether mail fails.
	 */
	private bool $fail = false;

	/**
	 * Logged contexts.
	 *
	 * @var string[]
	 */
	private array $logged = array();

	/**
	 * Report IDs.
	 *
	 * @var int[]
	 */
	private array $reports = array();

	/**
	 * Capture mail and log.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				if ( $this->fail ) {
					return false;
				}
				$file         = (array) $atts['attachments'];
				$this->files  = array_merge( $this->files, $file );
				$this->sent[] = array( $atts['to'], $atts['subject'], basename( $file[0] ?? '' ), (string) @file_get_contents( $file[0] ?? '' ), $atts['message'] ); // phpcs:ignore
				return true;
			},
			10,
			2
		);
		$this->filter(
			'sfls_logged',
			function ( $context ) {
				$this->logged[] = $context;
			}
		);
	}

	/**
	 * Remove the fixtures.
	 */
	protected function tearDown(): void {
		foreach ( $this->reports as $id ) {
			SFLS_Reports::delete( $id );
		}
		delete_option( SFLS_Report_Schedule::OPTION );
		parent::tearDown();
	}

	/**
	 * A scheduled report.
	 *
	 * @param string $schedule   Schedule.
	 * @param string $recipients Recipients.
	 */
	private function report( string $schedule, string $recipients = 'boss@sfls-std.example.net' ): array {
		$id              = SFLS_Reports::save(
			array(
				'name'       => 'Sched test',
				'user_id'    => 1,
				'definition' => array(
					'dataset'  => 'licenses',
					'group_by' => array( 'plan' ),
				),
				'schedule'   => $schedule,
				'recipients' => $recipients,
			)
		);
		$this->reports[] = $id;
		return (array) SFLS_Reports::find( $id );
	}

	/**
	 * Weekly goes out once per ISO week, with the CSV attached and deleted after.
	 */
	public function test_weekly_sent_once_per_week(): void {
		$r   = $this->report( 'weekly' );
		$mon = strtotime( '2026-11-16 08:00:00 UTC' );
		$this->assertTrue( SFLS_Report_Schedule::is_due( $r, $mon ) );
		SFLS_Report_Schedule::run_due( $mon );
		$mine = array_values( array_filter( $this->sent, static fn( $m ) => 'Scheduled report: Sched test' === $m[1] ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( array( 'boss@' . self::MAIL ), $mine[0][0] );
		$this->assertStringStartsWith( 'report-' . $r['id'] . '-', $mine[0][2] );
		$this->assertStringContainsString( 'Plan', $mine[0][3] );
		$this->assertStringContainsString( '<table', $mine[0][4] );
		foreach ( $this->files as $f ) {
			$this->assertFileDoesNotExist( $f );
		}
		$this->assertFalse( SFLS_Report_Schedule::is_due( $r, $mon + 3 * DAY_IN_SECONDS ), 'Same week.' );
		$before = count( $this->sent );
		SFLS_Report_Schedule::run_due( $mon + 3 * DAY_IN_SECONDS );
		$this->assertCount( $before, $this->sent );
		$this->assertTrue( SFLS_Report_Schedule::is_due( $r, $mon + 7 * DAY_IN_SECONDS ), 'Next week.' );
	}

	/**
	 * Monthly follows the calendar month; none and empty recipients are never due.
	 */
	public function test_monthly_and_never(): void {
		$m = $this->report( 'monthly' );
		$t = strtotime( '2026-11-30 08:00:00 UTC' );
		SFLS_Report_Schedule::run_due( $t );
		$this->assertFalse( SFLS_Report_Schedule::is_due( $m, $t + 12 * HOUR_IN_SECONDS ) );
		$this->assertTrue( SFLS_Report_Schedule::is_due( $m, $t + 2 * DAY_IN_SECONDS ), 'December.' );
		$this->assertFalse( SFLS_Report_Schedule::is_due( $this->report( 'none' ), $t ) );
		$this->assertFalse( SFLS_Report_Schedule::is_due( $this->report( 'weekly', '' ), $t ) );
	}

	/**
	 * Recipients are validated.
	 */
	public function test_recipient_validation(): void {
		$this->assertSame( array( 'a@' . self::MAIL, 'b@' . self::MAIL ), SFLS_Report_Schedule::parse_recipients( 'a@' . self::MAIL . ', b@' . self::MAIL . ', A@' . self::MAIL ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Schedule::parse_recipients( 'a@' . self::MAIL . ', not-an-email' ) );
		$this->assertInstanceOf( WP_Error::class, SFLS_Report_Schedule::parse_recipients( implode( ',', array_map( static fn( $i ) => "u{$i}@sfls-std.example.net", range( 1, 11 ) ) ) ) );
	}

	/**
	 * A failing wp_mail is logged and the report stays due.
	 */
	public function test_mail_failure_logged(): void {
		$r          = $this->report( 'weekly' );
		$this->fail = true;
		$t          = strtotime( '2026-11-16 08:00:00 UTC' );
		SFLS_Report_Schedule::run_due( $t );
		$this->assertContains( 'report-schedule', $this->logged );
		$this->assertTrue( SFLS_Report_Schedule::is_due( $r, $t ), 'Retried next run.' );
		foreach ( $this->files as $f ) {
			$this->assertFileDoesNotExist( $f );
		}
	}

	/**
	 * Run the save handler; return "redirect:<url>" or "die".
	 *
	 * @param array $post User ID and request values.
	 * @param int   $user User ID.
	 */
	private function save( array $post, int $user ): string {
		wp_set_current_user( $user );
		$_POST    = $post + array( '_wpnonce' => wp_create_nonce( 'sfls_report_save' ) );
		$_REQUEST = $_POST;
		$this->filter( 'wp_redirect', static fn( $l ) => throw new RuntimeException( "redirect:$l" ) );
		$this->filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'die' );
		} );
		try {
			( new SFLS_Reports_Admin() )->handle_save();
			return '';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			$_POST = $_REQUEST = array();
		}
	}

	/**
	 * Only administrators set a schedule, with valid recipients; capability enforced.
	 */
	public function test_only_admins_schedule(): void {
		$r    = $this->report( 'none', '' );
		$post = array(
			'name'       => 'Sched test',
			'dataset'    => 'licenses',
			'schedule'   => 'weekly',
			'recipients' => 'boss@' . self::MAIL,
			'id'         => $r['id'],
		);
		$user = $this->make_user();
		$this->assertSame( 'die', $this->save( $post, $user->ID ), 'No reports capability.' );

		$this->filter( 'sfls_reports_capability', static fn() => 'read' );
		$r = (array) SFLS_Reports::find( $r['id'] );
		$this->assertSame( '', $r['schedule'] );
		$user_report = SFLS_Reports::save( array( 'name' => 'mine', 'user_id' => $user->ID, 'definition' => array( 'dataset' => 'licenses' ) ) );
		$this->reports[] = $user_report;
		$this->assertStringStartsWith( 'redirect:', $this->save( array( 'id' => $user_report ) + $post, $user->ID ) );
		$this->assertSame( '', SFLS_Reports::find( $user_report )['schedule'], 'Not an admin: ignored.' );

		$this->assertStringContainsString( 'action=edit', $this->save( array( 'recipients' => 'bad' ) + $post, 1 ) );
		$this->assertSame( '', SFLS_Reports::find( $r['id'] )['schedule'], 'Invalid recipients rejected.' );
		$this->assertStringContainsString( 'action=view', $this->save( $post, 1 ) );
		$saved = SFLS_Reports::find( $r['id'] );
		$this->assertSame( 'weekly', $saved['schedule'] );
		$this->assertSame( 'boss@' . self::MAIL, $saved['recipients'] );
	}
}
