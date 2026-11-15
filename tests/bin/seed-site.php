<?php
// Higher plans cost more, so upgrades have a price difference.
$rows = array();
$step = 0;
foreach ( SFLS_Plans::all() as $plan => $def ) {
    ++$step;
    $rows[ $plan ] = array(
        'yearly'   => (string) ( 40 * $step ),
        'renewal'  => (string) ( 30 * $step ),
        'lifetime' => (string) ( 150 * $step ),
        'sites'    => (string) $def['sites'],
    );
}
// One license, as on the verified site (a test reads the first row).
// The address is not on the tests' own domain, which they clean up.
SFLS_Licenses::create( array( 'plan' => 'pro', 'customer_email' => 'seed@example.com' ) );
// One published Pro release, so update checks have something to offer.
$zip_path = wp_tempnam( 'seed-release.zip' );
$zip      = new ZipArchive();
$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'subtleforms-pro/subtleforms-pro.php', "<?php\n/**\n * Plugin Name: SubtleForms Pro\n * Version: 2.4.2\n * Requires at least: 6.5\n * Requires PHP: 8.1\n */\n" );
$zip->addFromString( 'subtleforms-pro/readme.txt', "=== SubtleForms Pro ===\nTested up to: 7.1\nStable tag: 2.4.2\n" );
$zip->close();
// The CI site has no signing key; since 1.11.0 publishing needs one
// unless signing is not required, so allow an unsigned seed release.
add_filter( 'sfls_require_signing', '__return_false' );
$stored = SFLS_Releases::ingest( $zip_path, array( 'publish' => true ) );
if ( ! is_wp_error( $stored ) && 'published' !== ( SFLS_Releases::get_by_id( (int) $stored['release']['id'] )['status'] ?? '' ) ) {
    echo "release seed was not published\n";
    exit( 1 );
}
remove_filter( 'sfls_require_signing', '__return_false' );
if ( is_wp_error( $stored ) ) {
    echo 'release seed failed: ' . $stored->get_error_message() . "\n";
    exit( 1 );
}
$result = SFLS_Products::save_wizard( $rows );
echo wp_json_encode( $result ), "\n";
