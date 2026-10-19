<?php
/**
 * LS-12: release sizes under 1 KB show whole bytes.
 *
 * @package SFLS
 */

/**
 * Release size labels.
 */
class SFLS_Size_Label_Test extends SFLS_Test_Case {

	/**
	 * The repro: a 478 byte package read "478.0 B" on Pro releases.
	 */
	public function test_bytes_have_no_decimal(): void {
		$this->assertSame( '478 B', SFLS_Releases::size_label( 478 ) );
		$this->assertSame( '1 B', SFLS_Releases::size_label( 1 ) );
		$this->assertSame( '1.5 KB', SFLS_Releases::size_label( 1536 ) );
		$this->assertSame( '2.0 MB', SFLS_Releases::size_label( 2 * MB_IN_BYTES ) );
		$this->assertSame( '', SFLS_Releases::size_label( 0 ) );
	}
}
