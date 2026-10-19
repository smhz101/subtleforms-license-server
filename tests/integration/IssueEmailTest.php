<?php
/**
 * LS-18: a license issued by hand can be emailed to the customer.
 *
 * @package SFLS
 */

/**
 * Issue license email.
 */
class SFLS_Issue_Email_Test extends SFLS_Test_Case {

	/**
	 * The repro: issuing a license by hand for a customer email sent nothing
	 * and offered no way to send it. With the option on, the customer gets
	 * the key and a link to the license page.
	 */
	public function test_key_is_emailed_when_asked(): void {
		$to          = 'issued' . wp_generate_password( 4, false ) . '@' . self::DOMAIN;
		$this->mails = array();
		$result      = SFLS_Admin::issue_from_form(
			array(
				'email'      => $to,
				'plan'       => 'pro',
				'months'     => 12,
				'count'      => 1,
				'send_email' => '1',
			)
		);

		$this->assertTrue( $result['emailed'] );
		$this->assertCount( 1, $result['licenses'] );
		$mail = array_values( array_filter( $this->mails, static fn( $m ) => ( is_array( $m['to'] ) ? in_array( $to, $m['to'], true ) : $m['to'] === $to ) ) );
		$this->assertCount( 1, $mail, 'One email to the customer.' );
		$this->assertSame( 'Your SubtleForms Pro license key', $mail[0]['subject'] );
		$this->assertStringContainsString( $result['licenses'][0]['license_key'], $mail[0]['message'] );
		$this->assertStringContainsString( 'sfls_guest=' . (int) $result['licenses'][0]['id'], $mail[0]['message'] );
		$this->assertStringContainsString( 'How to install Pro', $mail[0]['message'] );
	}

	/**
	 * Without the option nothing is sent, as before; asking without an
	 * address issues the key and reports that no email went out.
	 */
	public function test_no_email_unless_asked(): void {
		$this->mails = array();
		$result      = SFLS_Admin::issue_from_form(
			array(
				'email' => 'quiet' . wp_generate_password( 4, false ) . '@' . self::DOMAIN,
				'count' => 2,
			)
		);
		$this->assertNull( $result['emailed'] );
		$this->assertCount( 2, $result['licenses'] );
		$this->assertSame( array(), $this->mails );

		$this->mails = array();
		$blank       = SFLS_Admin::issue_from_form( array( 'send_email' => '1' ) );
		$this->assertFalse( $blank['emailed'] );
		$this->assertSame( array(), $this->mails );
		foreach ( $blank['licenses'] as $lic ) {
			SFLS_Licenses::delete( (int) $lic['id'] );
		}
	}
}
