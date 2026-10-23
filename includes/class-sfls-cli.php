<?php
/**
 * WP-CLI: wp sfls create | list | remind, wp sfls release …, wp sfls release token …
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage SubtleForms licenses.
 */
final class SFLS_CLI {

	/**
	 * Issue license keys.
	 *
	 * ## OPTIONS
	 *
	 * [--plan=<plan>]
	 * : starter, pro or agency.
	 * ---
	 * default: pro
	 * ---
	 *
	 * [--email=<email>]
	 * : Customer email. Links the key to the account with this email.
	 *
	 * [--user=<user>]
	 * : User ID, login or email that owns the key.
	 *
	 * [--sites=<n>]
	 * : Site limit (0 = unlimited). Defaults to the plan.
	 *
	 * [--months=<n>]
	 * : Duration in months (0 = lifetime).
	 * ---
	 * default: 12
	 * ---
	 *
	 * [--count=<n>]
	 * : How many keys.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--porcelain]
	 * : Output only the new keys.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sfls create --plan=agency --email=me@example.com --months=0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function create( $args, $assoc_args ): void {
		$count   = max( 1, (int) ( $assoc_args['count'] ?? 1 ) );
		$user_id = 0;
		$email   = (string) ( $assoc_args['email'] ?? '' );
		if ( ! empty( $assoc_args['user'] ) ) {
			$u    = $assoc_args['user'];
			$user = is_numeric( $u ) ? get_userdata( (int) $u ) : ( get_user_by( 'login', $u ) ? get_user_by( 'login', $u ) : get_user_by( 'email', $u ) );
			if ( ! $user ) {
				WP_CLI::error( 'User not found.' );
			}
			$user_id = (int) $user->ID;
			$email   = '' !== $email ? $email : $user->user_email;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$data = array(
				'plan'           => $assoc_args['plan'] ?? 'pro',
				'customer_email' => $email,
				'user_id'        => $user_id,
				'months'         => (int) ( $assoc_args['months'] ?? 12 ),
			);
			if ( isset( $assoc_args['sites'] ) ) {
				$data['sites_allowed'] = (int) $assoc_args['sites'];
			}
			$lic = SFLS_Licenses::create( $data );
			if ( isset( $assoc_args['porcelain'] ) ) {
				WP_CLI::line( $lic['license_key'] );
				continue;
			}
			WP_CLI::line( $lic['license_key'] . "\t" . $lic['plan'] . "\t" . ( $lic['expires_at'] ?? 'lifetime' ) );
		}
		if ( ! isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::success( sprintf( '%d license(s) issued.', $count ) );
		}
	}

	/**
	 * List licenses.
	 *
	 * [--format=<format>]
	 * : table, csv, json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function list( $args, $assoc_args ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, license_key, plan, status, sites_allowed, customer_email, user_id, expires_at FROM {$wpdb->prefix}sfls_licenses ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'license_key', 'plan', 'status', 'sites_allowed', 'customer_email', 'user_id', 'expires_at' ) );
	}

	/**
	 * Send renewal reminders that are due now (what the daily cron does).
	 *
	 * [--dry-run]
	 * : Only count what would be sent.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function remind( $args, $assoc_args ): void {
		$dry  = isset( $assoc_args['dry-run'] );
		$sent = SFLS_Reminders::run( $dry );
		WP_CLI::success( sprintf( $dry ? '%d reminder(s) due.' : '%d reminder(s) sent.', $sent ) );
	}
}

/**
 * Manage SubtleForms Pro releases.
 *
 * ## EXAMPLES
 *
 *     wp sfls release list
 *     wp sfls release add dist/subtleforms-pro-2.4.0.zip --publish --changelog=@CHANGES.txt
 *     wp sfls release yank 2.4.0 --reason="Breaks PHP 8.1"
 *     wp sfls release latest 2.3.3
 */
final class SFLS_CLI_Releases {

	/**
	 * Release by version or stop.
	 *
	 * @param string $version Version.
	 */
	private static function release( string $version ): array {
		$release = SFLS_Releases::get( $version, true );
		if ( ! $release ) {
			WP_CLI::error( sprintf( 'Release %s not found.', $version ) );
		}
		return $release;
	}

	/**
	 * List every release.
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
		$latest = SFLS_Releases::latest( 'stable' );
		$beta   = SFLS_Releases::latest( 'beta' );
		$pinned = SFLS_Releases::pinned();
		$rows   = array();
		foreach ( SFLS_Releases::rows() as $r ) {
			$flags  = array_filter(
				array(
					$latest && $latest['version'] === $r['version'] ? 'latest' : '',
					$beta && 'beta' === $r['channel'] && $beta['version'] === $r['version'] ? 'latest-beta' : '',
					$pinned === $r['version'] ? 'pinned' : '',
				)
			);
			$rows[] = array(
				'version'     => $r['version'],
				'channel'     => $r['channel'],
				'status'      => $r['status'],
				'flags'       => implode( ',', $flags ),
				'released_at' => (string) $r['released_at'],
				'size'        => $r['size'],
				'sha256'      => $r['sha256'],
				'downloads'   => $r['downloads'],
				'source'      => $r['source'],
				'git_ref'     => $r['git_ref'],
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'version', 'channel', 'status', 'flags', 'released_at', 'size', 'sha256', 'downloads', 'source', 'git_ref' ) );
	}

	/**
	 * Add a release from a zip.
	 *
	 * <zip>
	 * : Path to subtleforms-pro-x.y.z.zip.
	 *
	 * [--channel=<channel>]
	 * : stable or beta (default: beta when the version has a hyphen).
	 *
	 * [--publish]
	 * : Publish now. Without it the release is a draft.
	 *
	 * [--changelog=<changelog>]
	 * : Changelog text, or @file to read it from a file.
	 *
	 * [--replace]
	 * : Replace the package of an existing version.
	 *
	 * [--confirm-replace]
	 * : Needed with --replace when the version is published or yanked. The replacement is logged.
	 *
	 * [--porcelain]
	 * : Output only the version.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function add( $args, $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( ! is_file( $path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $path ) );
		}
		$changelog = (string) ( $assoc_args['changelog'] ?? '' );
		if ( str_starts_with( $changelog, '@' ) ) {
			$file = substr( $changelog, 1 );
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( sprintf( 'Changelog file not found: %s', $file ) );
			}
			$changelog = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file given on the command line.
		}
		$result = SFLS_Releases::ingest(
			$path,
			array(
				'source'          => 'cli',
				'channel'         => (string) ( $assoc_args['channel'] ?? '' ),
				'publish'         => ! empty( $assoc_args['publish'] ),
				'changelog'       => $changelog,
				'replace'         => ! empty( $assoc_args['replace'] ),
				'confirm_replace' => ! empty( $assoc_args['confirm-replace'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$rel = $result['release'];
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $rel['version'] );
			return;
		}
		WP_CLI::success( sprintf( '%s %s (%s, %s, sha256 %s).', $rel['version'], $result['result'], $rel['channel'], $rel['status'], $rel['sha256'] ) );
	}

	/**
	 * Publish a release.
	 *
	 * <version>
	 * : Version.
	 *
	 * @param array $args Positional args.
	 */
	public function publish( $args ): void {
		$rel    = self::release( (string) $args[0] );
		$result = SFLS_Releases::publish( $rel['id'] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s published.', $rel['version'] ) );
	}

	/**
	 * Yank a release so it is no longer offered or downloadable.
	 *
	 * <version>
	 * : Version.
	 *
	 * [--reason=<reason>]
	 * : Why (shown to admins).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function yank( $args, $assoc_args ): void {
		$rel = self::release( (string) $args[0] );
		SFLS_Releases::yank( $rel['id'], (string) ( $assoc_args['reason'] ?? '' ) );
		$latest = SFLS_Releases::latest( 'stable' );
		WP_CLI::success( sprintf( '%s yanked. Latest stable: %s.', $rel['version'], $latest ? $latest['version'] : 'none' ) );
	}

	/**
	 * Make a published stable version the latest (pin), or unpin.
	 *
	 * [<version>]
	 * : Version to pin.
	 *
	 * [--unpin]
	 * : Remove the pin.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function latest( $args, $assoc_args ): void {
		if ( ! empty( $assoc_args['unpin'] ) ) {
			SFLS_Releases::unpin();
			$latest = SFLS_Releases::latest( 'stable' );
			WP_CLI::success( sprintf( 'Unpinned. Latest stable: %s.', $latest ? $latest['version'] : 'none' ) );
			return;
		}
		if ( empty( $args[0] ) ) {
			$latest = SFLS_Releases::latest( 'stable' );
			WP_CLI::line( $latest ? $latest['version'] : '' );
			return;
		}
		$result = SFLS_Releases::pin( (string) $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s is now the latest release.', $args[0] ) );
	}

	/**
	 * Delete a release and its zip files.
	 *
	 * <version>
	 * : Version.
	 *
	 * [--yes]
	 * : Confirm.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function delete( $args, $assoc_args ): void {
		$rel = self::release( (string) $args[0] );
		WP_CLI::confirm( sprintf( 'Delete %s and its zip permanently?', $rel['version'] ), $assoc_args );
		SFLS_Releases::delete( $rel['id'] );
		WP_CLI::success( sprintf( '%s deleted.', $rel['version'] ) );
	}
}

/**
 * Manage deploy tokens for the release API.
 *
 * ## EXAMPLES
 *
 *     wp sfls release token create "GitHub Actions"
 *     wp sfls release token list
 *     wp sfls release token revoke 2
 */
final class SFLS_CLI_Tokens {

	/**
	 * Create a token (printed once).
	 *
	 * <name>
	 * : Name.
	 *
	 * [--porcelain]
	 * : Output only the token.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function create( $args, $assoc_args ): void {
		$created = SFLS_Deploy_Tokens::create( (string) ( $args[0] ?? '' ) );
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $created['token'] );
			return;
		}
		WP_CLI::line( $created['token'] );
		WP_CLI::success( sprintf( 'Token %d "%s" created. It is shown only now.', $created['record']['id'], $created['record']['name'] ) );
	}

	/**
	 * List tokens (never the secret).
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
		foreach ( SFLS_Deploy_Tokens::all() as $t ) {
			$rows[] = array(
				'id'           => $t['id'],
				'name'         => $t['name'],
				'token'        => $t['hint'],
				'created_at'   => $t['created_at'],
				'last_used_at' => $t['last_used_at'],
				'status'       => '' === $t['revoked_at'] ? 'active' : 'revoked',
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'name', 'token', 'created_at', 'last_used_at', 'status' ) );
	}

	/**
	 * Revoke a token.
	 *
	 * <id>
	 * : Token ID.
	 *
	 * @param array $args Positional args.
	 */
	public function revoke( $args ): void {
		if ( ! SFLS_Deploy_Tokens::revoke( (int) ( $args[0] ?? 0 ) ) ) {
			WP_CLI::error( 'Token not found.' );
		}
		WP_CLI::success( 'Token revoked.' );
	}
}

/**
 * Package and response signing key (ed25519).
 *
 * ## EXAMPLES
 *
 *     wp sfls signing generate
 *     wp sfls signing status
 *     wp sfls signing sign-releases
 */
final class SFLS_CLI_Signing {

	/**
	 * Make a new key pair and print the two wp-config.php lines. Nothing is
	 * stored: paste the lines into wp-config.php, and the public key into
	 * SubtleForms Pro (src/Licensing/Signature.php) before its release.
	 *
	 * [--force]
	 * : Print a new pair even though a key is already configured.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function generate( $args, $assoc_args ): void {
		if ( ! SFLS_Signing::sodium() ) {
			WP_CLI::error( 'This PHP has no sodium (ed25519) support.' );
		}
		if ( SFLS_Signing::enabled() && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'A signing key is already configured. A new key breaks updates for every Pro site that ships the old public key. Use --force to print a new pair anyway.' );
		}
		$pair = SFLS_Signing::generate();
		WP_CLI::line( sprintf( "define( 'SFLS_SIGNING_SECRET_KEY', '%s' );", $pair['secret'] ) );
		WP_CLI::line( sprintf( "define( 'SFLS_SIGNING_PUBLIC_KEY', '%s' );", $pair['public'] ) );
		WP_CLI::success( 'Add both lines to wp-config.php (keep the secret key out of backups and version control), then run wp sfls signing sign-releases.' );
	}

	/**
	 * Show whether signing is on and the public key in use.
	 */
	public function status(): void {
		if ( ! SFLS_Signing::enabled() ) {
			WP_CLI::warning( 'Signing is off: SFLS_SIGNING_SECRET_KEY is not set or is not a base64 ed25519 secret key.' );
			return;
		}
		WP_CLI::line( 'Public key: ' . SFLS_Signing::public_key() );
		if ( ! SFLS_Signing::public_key_matches() ) {
			WP_CLI::warning( 'SFLS_SIGNING_PUBLIC_KEY does not belong to SFLS_SIGNING_SECRET_KEY.' );
		}
		$unsigned = array();
		foreach ( SFLS_Releases::rows() as $release ) {
			if ( SFLS_Releases::has_package( $release ) && '' === SFLS_Signing::release_signature( $release ) ) {
				$unsigned[] = $release['version'];
			}
		}
		WP_CLI::success( $unsigned ? 'Signing is on. Unsigned releases: ' . implode( ', ', $unsigned ) . '.' : 'Signing is on. Every release is signed.' );
	}

	/**
	 * Sign every release whose zip still matches its sha256.
	 *
	 * [--resign]
	 * : Sign again releases that already have a signature (after a key change).
	 *
	 * @subcommand sign-releases
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function sign_releases( $args, $assoc_args ): void {
		if ( ! SFLS_Signing::enabled() ) {
			WP_CLI::error( 'Signing is off: set SFLS_SIGNING_SECRET_KEY in wp-config.php first (wp sfls signing generate).' );
		}
		$out = SFLS_Signing::sign_all( ! empty( $assoc_args['resign'] ) );
		foreach ( $out['mismatch'] as $version ) {
			WP_CLI::warning( sprintf( '%s not signed: its zip is missing or does not match the stored sha256.', $version ) );
		}
		WP_CLI::success( sprintf( '%d signed, %d already signed, %d not signed.', count( $out['signed'] ), count( $out['skipped'] ), count( $out['mismatch'] ) ) );
	}
}

WP_CLI::add_command( 'sfls signing', 'SFLS_CLI_Signing' );
