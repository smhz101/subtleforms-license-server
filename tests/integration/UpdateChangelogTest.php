<?php
/**
 * LS-14: the update check sends the changelog as a list.
 *
 * @package SFLS
 */

/**
 * Changelog in update checks.
 */
class SFLS_Update_Changelog_Test extends SFLS_Test_Case {

	/**
	 * The repro: "* Test upload release" arrived as "<p>* Test upload release</p>",
	 * bullets as text in the WordPress update modal.
	 */
	public function test_bullets_are_a_list(): void {
		$this->add_release( '9.4.0', array( 'changelog' => "* Test upload release\n* Second change" ) );
		$check = $this->rest( 'GET', '/sfls/v1/update/check' )->get_data();
		$this->assertSame( '9.4.0', $check['new_version'] );
		$this->assertSame( '<ul><li>Test upload release</li><li>Second change</li></ul>', $check['changelog'] );
	}

	/**
	 * Notes without bullets stay paragraphs.
	 */
	public function test_plain_notes_stay_paragraphs(): void {
		$this->add_release( '9.4.1', array( 'changelog' => 'Security release.' ) );
		$check = $this->rest( 'GET', '/sfls/v1/update/check' )->get_data();
		$this->assertSame( "<p>Security release.</p>\n", $check['changelog'] );
	}
}
