<?php
/**
 * WP-CLI: wp sfls extension add | list | publish | unpublish | update
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the extension catalog.
 *
 * ## EXAMPLES
 *
 *     wp sfls extension add google-sheets --title="Google Sheets" --description="Send entries to a sheet." --category=integrations --min-plan=pro
 *     wp sfls extension list
 *     wp sfls extension publish google-sheets
 *     wp sfls extension update google-sheets --min-plan=agency
 */
final class SFLS_CLI_Extensions {

	/**
	 * Add an extension to the catalog (a draft until it is published).
	 *
	 * <slug>
	 * : Extension slug, such as google-sheets. The plugin folder is subtleforms-<slug>.
	 *
	 * --title=<title>
	 * : Name shown to customers.
	 *
	 * --description=<description>
	 * : One or two plain sentences.
	 *
	 * --category=<category>
	 * : payments, integrations, fields, entries or site.
	 *
	 * --min-plan=<plan>
	 * : Smallest plan that includes it: starter, pro or agency.
	 *
	 * [--icon=<icon>]
	 * : Dashicon name, such as database-export.
	 *
	 * [--docs-url=<url>]
	 * : Documentation link.
	 *
	 * [--sort=<n>]
	 * : Order in the catalog (lowest first).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function add( $args, $assoc_args ): void {
		unset( $assoc_args['status'] ); // A new extension is a draft; publish it when its first release is up.
		$entry = SFLS_Extensions::create( (string) ( $args[0] ?? '' ), $assoc_args );
		if ( is_wp_error( $entry ) ) {
			self::fail( $entry->get_error_message() );
		}
		self::done( sprintf( '%s added as a draft (%s, %s and up).', $entry['slug'], $entry['category'], $entry['min_plan'] ) );
	}

	/**
	 * List the catalog.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list_( $args, $assoc_args ): void {
		$rows = array();
		foreach ( SFLS_Extensions::all() as $e ) {
			$latest = SFLS_Releases::latest( 'stable', false, $e['slug'] );
			$rows[] = array(
				'slug'     => $e['slug'],
				'title'    => $e['title'],
				'category' => $e['category'],
				'min_plan' => $e['min_plan'],
				'status'   => $e['status'],
				'sort'     => $e['sort'],
				'version'  => $latest ? $latest['version'] : '',
			);
		}
		self::table( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'slug', 'title', 'category', 'min_plan', 'status', 'sort', 'version' ) );
	}

	/**
	 * Publish an extension: it appears in the public catalog and in the allowed list of licenses whose plan includes it.
	 *
	 * <slug>
	 * : Extension slug.
	 *
	 * @param array $args Positional args.
	 */
	public function publish( $args ): void {
		$this->set_status( (string) ( $args[0] ?? '' ), 'published' );
	}

	/**
	 * Take an extension out of the public catalog and the allowed lists. Installed copies keep their files; sites stop getting the extension at the next licence check.
	 *
	 * <slug>
	 * : Extension slug.
	 *
	 * @param array $args Positional args.
	 */
	public function unpublish( $args ): void {
		$this->set_status( (string) ( $args[0] ?? '' ), 'draft' );
	}

	/**
	 * Change fields of an extension.
	 *
	 * <slug>
	 * : Extension slug.
	 *
	 * [--title=<title>]
	 * [--description=<description>]
	 * [--category=<category>]
	 * [--min-plan=<plan>]
	 * [--icon=<icon>]
	 * [--docs-url=<url>]
	 * [--sort=<n>]
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function update( $args, $assoc_args ): void {
		// The state changes only through publish and unpublish.
		unset( $assoc_args['status'] );
		$entry = SFLS_Extensions::update( (string) ( $args[0] ?? '' ), $assoc_args );
		if ( is_wp_error( $entry ) ) {
			self::fail( $entry->get_error_message() );
		}
		self::done( sprintf( '%s updated.', $entry['slug'] ) );
	}

	/**
	 * Stop with an error.
	 *
	 * @param string $message Message.
	 */
	private static function fail( string $message ): void {
		WP_CLI::error( $message ); // @phpstan-ignore-line -- WP-CLI is not installed for the analysis.
	}

	/**
	 * Report success.
	 *
	 * @param string $message Message.
	 */
	private static function done( string $message ): void {
		WP_CLI::success( $message ); // @phpstan-ignore-line -- WP-CLI is not installed for the analysis.
	}

	/**
	 * Print rows.
	 *
	 * @param string   $format  table, csv, json or yaml.
	 * @param array[]  $rows    Rows.
	 * @param string[] $columns Columns.
	 */
	private static function table( string $format, array $rows, array $columns ): void {
		\WP_CLI\Utils\format_items( $format, $rows, $columns ); // @phpstan-ignore-line -- WP-CLI is not installed for the analysis.
	}

	/**
	 * Set draft or published.
	 *
	 * @param string $slug   Slug.
	 * @param string $status draft | published.
	 */
	private function set_status( string $slug, string $status ): void {
		$entry = SFLS_Extensions::update( $slug, array( 'status' => $status ) );
		if ( is_wp_error( $entry ) ) {
			self::fail( $entry->get_error_message() );
		}
		self::done( sprintf( '%s is now %s.', $entry['slug'], $status ) );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'sfls extension', 'SFLS_CLI_Extensions' );
}
