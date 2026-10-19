<?php
/**
 * Admin: Licenses › Pro releases (every version, upload, row actions, edit,
 * yank, pin) and Licenses › Settings › Releases (deploy tokens, the CI API
 * and the GitHub connection with its delivery log).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Release management screens and form handlers.
 */
final class SFLS_Admin_Releases {

	const PAGE = 'sfls-release';

	/**
	 * Shared admin helpers (header, notices, capability).
	 *
	 * @var SFLS_Admin
	 */
	private SFLS_Admin $admin;

	/**
	 * Constructor.
	 *
	 * @param SFLS_Admin $admin Admin.
	 */
	public function __construct( SFLS_Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Hook the form handlers.
	 */
	public function register(): void {
		foreach ( array(
			'sfls_release_upload'  => 'handle_upload',
			'sfls_release_do'      => 'handle_row_action',
			'sfls_release_edit'    => 'handle_edit',
			'sfls_release_yank'    => 'handle_yank',
			'sfls_release_unpin'   => 'handle_unpin',
			'sfls_token_create'    => 'handle_token_create',
			'sfls_token_revoke'    => 'handle_token_revoke',
			'sfls_release_ci'      => 'handle_ci_settings',
			'sfls_github_settings' => 'handle_github_settings',
			'sfls_github_secret'   => 'handle_github_secret',
			'sfls_github_test'     => 'handle_github_test',
			'sfls_github_import'   => 'handle_github_import',
			'sfls_github_retry'    => 'handle_github_retry',
		) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Releases screen URL.
	 *
	 * @param array $args Extra args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Settings › Releases URL.
	 *
	 * @param string $anchor Optional #anchor.
	 */
	public static function settings_url( string $anchor = '' ): string {
		return admin_url( 'admin.php?page=sfls-settings&tab=releases' ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * Nonce-protected row action URL.
	 *
	 * @param string $do Action.
	 * @param int    $id Release ID.
	 */
	private function action_url( string $do, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'sfls_release_do',
					'do'     => $do,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'sfls_release_' . $id
		);
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url URL.
	 */
	private function back( string $url = '' ): void {
		wp_safe_redirect( '' !== $url ? $url : self::url() );
		exit;
	}

	/**
	 * Show a secret once on the next screen (deploy token, webhook secret).
	 *
	 * @param string $label Label.
	 * @param string $value Secret.
	 */
	private function reveal( string $label, string $value ): void {
		set_transient(
			'sfls_reveal_' . get_current_user_id(),
			array(
				'label' => $label,
				'value' => $value,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and forget a secret queued with reveal().
	 */
	private function print_reveal(): void {
		$key  = 'sfls_reveal_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-success sfls-reveal"><p><strong>' . esc_html( (string) $data['label'] ) . '</strong></p>';
		echo '<p><label class="screen-reader-text" for="sfls-reveal-value">' . esc_html( (string) $data['label'] ) . '</label><input id="sfls-reveal-value" type="text" readonly class="large-text sfls-mono" value="' . esc_attr( (string) $data['value'] ) . '" onfocus="this.select();"></p>';
		echo '<p class="description">' . esc_html__( 'Copy it now. It is stored hashed or masked and will not be shown again.', 'subtleforms-license-server' ) . '</p></div>';
	}

	/**
	 * Status pill.
	 *
	 * @param array $release Release.
	 */
	private static function status_pill( array $release ): string {
		$labels = array(
			'published' => array( 'ok', __( 'Published', 'subtleforms-license-server' ) ),
			'draft'     => array( 'neutral', __( 'Draft', 'subtleforms-license-server' ) ),
			'yanked'    => array( 'bad', __( 'Yanked', 'subtleforms-license-server' ) ),
		);
		[ $tone, $label ] = $labels[ $release['status'] ] ?? array( 'neutral', $release['status'] );
		return '<span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Channel label.
	 *
	 * @param string $channel Channel.
	 */
	private static function channel_label( string $channel ): string {
		return 'beta' === $channel ? __( 'Beta', 'subtleforms-license-server' ) : __( 'Stable', 'subtleforms-license-server' );
	}

	/**
	 * Source label.
	 *
	 * @param string $source Source.
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'upload' => __( 'Upload', 'subtleforms-license-server' ),
			'api'    => __( 'CI API', 'subtleforms-license-server' ),
			'github' => __( 'GitHub', 'subtleforms-license-server' ),
			'cli'    => __( 'WP-CLI', 'subtleforms-license-server' ),
		);
		return $labels[ $source ] ?? $source;
	}

	/**
	 * Date and time for people.
	 *
	 * @param string|null $mysql GMT datetime.
	 */
	private static function when( ?string $mysql ): string {
		return $mysql ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $mysql . ' UTC' ) ) : '';
	}

	/**
	 * Git ref and commit, linked to GitHub when a repository is set.
	 *
	 * @param array $release Release.
	 */
	private static function git_html( array $release ): string {
		$parts = array();
		if ( '' !== $release['git_ref'] ) {
			$url     = SFLS_GitHub::ref_url( $release['git_ref'] );
			$parts[] = '' !== $url ? '<a class="sfls-mono" href="' . esc_url( $url ) . '">' . esc_html( $release['git_ref'] ) . '</a>' : '<span class="sfls-mono">' . esc_html( $release['git_ref'] ) . '</span>';
		}
		if ( '' !== $release['git_commit'] ) {
			$short   = preg_match( '/^[a-f0-9]{40}$/i', $release['git_commit'] ) ? substr( $release['git_commit'], 0, 7 ) : $release['git_commit'];
			$url     = SFLS_GitHub::ref_url( '', $release['git_commit'] );
			$parts[] = '' !== $url ? '<a class="sfls-mono" href="' . esc_url( $url ) . '" title="' . esc_attr( $release['git_commit'] ) . '">' . esc_html( $short ) . '</a>' : '<span class="sfls-mono" title="' . esc_attr( $release['git_commit'] ) . '">' . esc_html( $short ) . '</span>';
		}
		return implode( ' · ', $parts );
	}

	// ── Releases screen ─────────────────────────────────────────────────

	/**
	 * Licenses › Pro releases.
	 */
	public function page(): void {
		$this->admin->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		if ( $edit ) {
			$this->page_edit( $edit );
			return;
		}

		SFLS_Releases::flush();
		$rows    = SFLS_Releases::rows();
		$latest  = SFLS_Releases::latest( 'stable' );
		$beta    = SFLS_Releases::latest( 'beta' );
		$pinned  = SFLS_Releases::pinned();
		$sites   = SFLS_Releases::site_versions();
		$actions = '<a href="#sfls-upload" class="page-title-action">' . esc_html__( 'Upload release', 'subtleforms-license-server' ) . '</a> <a href="' . esc_url( self::settings_url() ) . '" class="page-title-action">' . esc_html__( 'Git and CI settings', 'subtleforms-license-server' ) . '</a>';
		$this->admin->header( __( 'Pro releases', 'subtleforms-license-server' ), $actions );

		echo '<p class="sfls-lead">' . esc_html__( 'Every SubtleForms Pro version you upload stays here and stays downloadable for customers. Sites with an active license are offered the latest published stable version in Dashboard › Updates. Yank a bad version and the previous one becomes the latest again.', 'subtleforms-license-server' ) . '</p>';

		$downloads = array_sum( array_map( static fn( $r ) => (int) $r['downloads'], $rows ) );
		$cards     = array(
			array( __( 'Latest stable', 'subtleforms-license-server' ), $latest ? $latest['version'] : '–', $latest && $pinned === $latest['version'] ? __( 'Pinned', 'subtleforms-license-server' ) : '' ),
			array( __( 'Latest beta', 'subtleforms-license-server' ), $beta && 'beta' === $beta['channel'] ? $beta['version'] : '–', '' ),
			array( __( 'Downloads', 'subtleforms-license-server' ), number_format_i18n( $downloads ), '' ),
			array( __( 'Sites reporting a version', 'subtleforms-license-server' ), number_format_i18n( array_sum( $sites ) ), '' ),
		);
		echo '<div class="sfls-admin-cards sfls-release-cards">';
		foreach ( $cards as [ $label, $value, $chip ] ) {
			echo '<div class="sfls-admin-card"><span class="sfls-admin-card-label">' . esc_html( $label ) . '</span><span class="sfls-admin-card-value">' . esc_html( $value );
			if ( '' !== $chip ) {
				echo ' <span class="sfls-admin-pill sfls-admin-pill--info">' . esc_html( $chip ) . '</span>';
			}
			echo '</span></div>';
		}
		echo '</div>';

		if ( '' !== $pinned ) {
			echo '<div class="notice notice-info inline sfls-pin-notice"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: version */
					__( '%s is pinned as the latest release. Newer stable versions are not offered to sites until you unpin it.', 'subtleforms-license-server' ),
					$pinned
				)
			);
			echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'sfls_release_unpin' );
			echo '<input type="hidden" name="action" value="sfls_release_unpin">';
			submit_button( __( 'Unpin', 'subtleforms-license-server' ), 'secondary small', 'submit', false );
			echo '</form></div>';
		}

		$this->table( $rows, $latest, $beta, $pinned, $sites );

		echo '<div class="sfls-edit sfls-release-lower">';
		echo '<div class="sfls-edit-main">';
		$this->upload_panel( $latest );
		echo '</div><div class="sfls-edit-side">';
		$this->sites_panel( $sites );
		echo '</div></div>';
		echo '</div>';
	}

	/**
	 * Releases table.
	 *
	 * @param array[]     $rows   Releases.
	 * @param array|null  $latest Latest stable.
	 * @param array|null  $beta   Latest beta.
	 * @param string      $pinned Pinned version.
	 * @param array<string,int> $sites Sites per version.
	 */
	private function table( array $rows, ?array $latest, ?array $beta, string $pinned, array $sites ): void {
		$heads = array(
			'version'   => __( 'Version', 'subtleforms-license-server' ),
			'channel'   => __( 'Channel', 'subtleforms-license-server' ),
			'status'    => __( 'Status', 'subtleforms-license-server' ),
			'released'  => __( 'Released', 'subtleforms-license-server' ),
			'size'      => __( 'Size', 'subtleforms-license-server' ),
			'sha'       => __( 'SHA-256', 'subtleforms-license-server' ),
			'downloads' => __( 'Downloads', 'subtleforms-license-server' ),
			'sites'     => __( 'Sites', 'subtleforms-license-server' ),
			'source'    => __( 'Source', 'subtleforms-license-server' ),
		);
		echo '<table class="wp-list-table widefat fixed striped sfls-table sfls-release-table"><thead><tr>';
		foreach ( $heads as $col => $label ) {
			echo '<th scope="col" class="column-' . esc_attr( $col ) . ( 'version' === $col ? ' column-primary' : '' ) . '">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No releases yet. Upload the SubtleForms Pro zip below, or connect GitHub or CI in Git and CI settings.', 'subtleforms-license-server' ) . '</td></tr>';
		}
		foreach ( $rows as $rel ) {
			$id       = (int) $rel['id'];
			$is_live  = SFLS_Releases::is_live( $rel );
			$has_file = SFLS_Releases::has_package( $rel );
			$edit     = self::url( array( 'edit' => $id ) );
			echo '<tr class="sfls-release-row sfls-release-row--' . esc_attr( $rel['status'] ) . '">';

			echo '<td class="column-version column-primary" data-colname="' . esc_attr( $heads['version'] ) . '"><a class="row-title sfls-mono" href="' . esc_url( $edit ) . '">' . esc_html( $rel['version'] ) . '</a>';
			$badges = array();
			if ( $latest && $latest['version'] === $rel['version'] ) {
				$badges[] = '<span class="sfls-admin-pill sfls-admin-pill--info">' . esc_html__( 'Latest', 'subtleforms-license-server' ) . '</span>';
			}
			if ( $beta && 'beta' === $rel['channel'] && $beta['version'] === $rel['version'] ) {
				$badges[] = '<span class="sfls-admin-pill sfls-admin-pill--warn">' . esc_html__( 'Latest beta', 'subtleforms-license-server' ) . '</span>';
			}
			if ( $pinned === $rel['version'] ) {
				$badges[] = '<span class="sfls-admin-pill sfls-admin-pill--info">' . esc_html__( 'Pinned', 'subtleforms-license-server' ) . '</span>';
			}
			if ( ! $has_file ) {
				$badges[] = '<span class="sfls-admin-pill sfls-admin-pill--bad">' . esc_html__( 'Package missing', 'subtleforms-license-server' ) . '</span>';
			}
			if ( $badges ) {
				echo ' <span class="sfls-badges">' . wp_kses_post( implode( ' ', $badges ) ) . '</span>';
			}

			$actions = array();
			if ( $has_file ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'download', $id ) ) . '">' . esc_html__( 'Download zip', 'subtleforms-license-server' ) . '</a>';
			}
			$actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'subtleforms-license-server' ) . '</a>';
			if ( 'published' !== $rel['status'] && $has_file ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'publish', $id ) ) . '">' . esc_html( 'yanked' === $rel['status'] ? __( 'Publish again', 'subtleforms-license-server' ) : __( 'Publish', 'subtleforms-license-server' ) ) . '</a>';
			}
			if ( 'published' === $rel['status'] ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'draft', $id ) ) . '">' . esc_html__( 'Unpublish to draft', 'subtleforms-license-server' ) . '</a>';
				$actions[] = '<a href="' . esc_url( add_query_arg( 'yank', 1, $edit ) . '#sfls-yank' ) . '">' . esc_html__( 'Yank', 'subtleforms-license-server' ) . '</a>';
			}
			if ( $pinned === $rel['version'] ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'unpin', $id ) ) . '">' . esc_html__( 'Unpin', 'subtleforms-license-server' ) . '</a>';
			} elseif ( $is_live && 'stable' === $rel['channel'] && ( ! $latest || $latest['version'] !== $rel['version'] ) ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'pin', $id ) ) . '" onclick="return confirm(\'' . esc_js( sprintf( /* translators: %s: version */ __( 'Make %s the latest release? Sites are offered this version, and newer stable versions are held back until you unpin it.', 'subtleforms-license-server' ), $rel['version'] ) ) . '\');">' . esc_html__( 'Make this the latest', 'subtleforms-license-server' ) . '</a>';
			}
			$actions[] = '<span class="trash"><a href="' . esc_url( $this->action_url( 'delete', $id ) ) . '" onclick="return confirm(\'' . esc_js( sprintf( /* translators: %s: version */ __( 'Delete %s and its zip file permanently? Customers can no longer download it. This cannot be undone.', 'subtleforms-license-server' ), $rel['version'] ) ) . '\');">' . esc_html__( 'Delete', 'subtleforms-license-server' ) . '</a></span>';
			echo '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'subtleforms-license-server' ) . '</span></button></td>';

			echo '<td data-colname="' . esc_attr( $heads['channel'] ) . '">' . esc_html( self::channel_label( $rel['channel'] ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['status'] ) . '">' . wp_kses_post( self::status_pill( $rel ) );
			if ( 'yanked' === $rel['status'] && '' !== $rel['yank_reason'] ) {
				echo '<span class="sfls-cell-note">' . esc_html( $rel['yank_reason'] ) . '</span>';
			}
			echo '</td>';
			echo '<td data-colname="' . esc_attr( $heads['released'] ) . '">' . esc_html( $rel['released_at'] ? SFLS_Releases::date_label( $rel ) : __( 'Not yet', 'subtleforms-license-server' ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['size'] ) . '">' . esc_html( $rel['size'] ? SFLS_Releases::size_label( (int) $rel['size'] ) : '–' ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['sha'] ) . '">' . ( '' !== $rel['sha256'] ? '<code class="sfls-sha" title="' . esc_attr( $rel['sha256'] ) . '">' . esc_html( substr( $rel['sha256'], 0, 12 ) ) . '</code>' : '–' ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['downloads'] ) . '">' . esc_html( number_format_i18n( $rel['downloads'] ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['sites'] ) . '">' . esc_html( number_format_i18n( $sites[ $rel['version'] ] ?? 0 ) ) . '</td>';
			$git = self::git_html( $rel );
			echo '<td data-colname="' . esc_attr( $heads['source'] ) . '">' . esc_html( self::source_label( $rel['source'] ) ) . ( '' !== $git ? '<span class="sfls-cell-note">' . wp_kses_post( $git ) . '</span>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Upload form.
	 *
	 * @param array|null $latest Latest stable release.
	 */
	private function upload_panel( ?array $latest ): void {
		echo '<form id="sfls-upload" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_release_upload' );
		echo '<input type="hidden" name="action" value="sfls_release_upload"><h2>' . esc_html__( 'Upload a release', 'subtleforms-license-server' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sfls-zip">' . esc_html__( 'Plugin zip', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-zip" type="file" name="package" accept=".zip,application/zip" required><p class="description">' . esc_html(
			sprintf(
				/* translators: 1: path inside the zip, 2: size limit */
				__( 'The zip must contain %1$s, up to %2$s. Version, Requires at least, Tested up to and Requires PHP are read from its plugin header.', 'subtleforms-license-server' ),
				SFLS_Releases::MAIN_FILE,
				size_format( SFLS_Releases::max_size() )
			)
		) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-channel">' . esc_html__( 'Channel', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-channel" name="channel"><option value="">' . esc_html__( 'Automatic (beta when the version has a hyphen)', 'subtleforms-license-server' ) . '</option><option value="stable">' . esc_html__( 'Stable', 'subtleforms-license-server' ) . '</option><option value="beta">' . esc_html__( 'Beta', 'subtleforms-license-server' ) . '</option></select><p class="description">' . esc_html__( 'Beta versions are offered only to sites that ask for the beta channel, and are listed for customers as older versions.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'When', 'subtleforms-license-server' ) . '</th><td><fieldset class="sfls-choice-list"><legend class="screen-reader-text">' . esc_html__( 'When', 'subtleforms-license-server' ) . '</legend>';
		echo '<label><input type="radio" name="publish" value="1" checked> ' . esc_html__( 'Publish now', 'subtleforms-license-server' ) . '</label><br><label><input type="radio" name="publish" value="0"> ' . esc_html__( 'Save as draft', 'subtleforms-license-server' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Licensed sites see a published version within 12 hours. A draft is only visible here until you publish it.', 'subtleforms-license-server' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-changelog">' . esc_html__( 'Changelog', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-changelog" name="changelog" rows="6" class="large-text" placeholder="' . esc_attr__( '* New: …', 'subtleforms-license-server' ) . '"></textarea><p class="description">' . esc_html__( 'One change per line, starting with "* ", shows as a list.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Existing version', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="replace" value="1"> ' . esc_html__( 'Replace the package if this version was uploaded before', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'Without this, uploading a version that already exists with a different zip is refused. The old zip is kept on disk until the release is deleted.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Upload release', 'subtleforms-license-server' ) );
		echo '</form>';
		unset( $latest );
	}

	/**
	 * Which versions licensed sites last checked in with.
	 *
	 * @param array<string,int> $sites Version => sites.
	 */
	private function sites_panel( array $sites ): void {
		echo '<div class="sfls-panel sfls-sites-panel"><h2>' . esc_html__( 'Versions on sites', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $sites ) {
			echo '<p class="description">' . esc_html__( 'No site with an active license has reported its Pro version yet. Sites report it when they check for updates.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'The Pro version each site with an active license had when it last checked for updates.', 'subtleforms-license-server' ) . '</p>';
		$max = max( $sites );
		echo '<ul class="sfls-version-bars">';
		foreach ( $sites as $version => $count ) {
			$pct = $max ? max( 4, (int) round( $count / $max * 100 ) ) : 0;
			echo '<li><span class="sfls-mono">' . esc_html( (string) $version ) . '</span><span class="sfls-bar" aria-hidden="true"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></span><span class="sfls-bar-count">' . esc_html( sprintf( /* translators: %s: number of sites */ _n( '%s site', '%s sites', $count, 'subtleforms-license-server' ), number_format_i18n( $count ) ) ) . '</span></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Edit one release (changelog, channel, requirements, notes), with yank,
	 * pin and delete on the side.
	 *
	 * @param int $id Release ID.
	 */
	private function page_edit( int $id ): void {
		$rel = SFLS_Releases::get_by_id( $id );
		$all = '<a href="' . esc_url( self::url() ) . '" class="page-title-action">' . esc_html__( 'All releases', 'subtleforms-license-server' ) . '</a>';
		if ( ! $rel ) {
			$this->admin->header( __( 'Edit release', 'subtleforms-license-server' ), $all );
			echo '<p>' . esc_html__( 'Release not found.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		/* translators: %s: version */
		$this->admin->header( sprintf( __( 'Edit release %s', 'subtleforms-license-server' ), $rel['version'] ), $all );
		$latest = SFLS_Releases::latest( 'stable' );

		echo '<div class="sfls-edit"><div class="sfls-edit-main">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_release_edit_' . $id );
		echo '<input type="hidden" name="action" value="sfls_release_edit"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><h2>' . esc_html__( 'Details', 'subtleforms-license-server' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sfls-channel">' . esc_html__( 'Channel', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-channel" name="channel">';
		foreach ( SFLS_Releases::CHANNELS as $channel ) {
			echo '<option value="' . esc_attr( $channel ) . '"' . selected( $rel['channel'], $channel, false ) . '>' . esc_html( self::channel_label( $channel ) ) . '</option>';
		}
		echo '</select></td></tr>';
		foreach ( array(
			'requires'     => __( 'Requires WordPress', 'subtleforms-license-server' ),
			'tested'       => __( 'Tested up to', 'subtleforms-license-server' ),
			'requires_php' => __( 'Requires PHP', 'subtleforms-license-server' ),
		) as $field => $label ) {
			echo '<tr><th scope="row"><label for="sfls-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td><input id="sfls-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $rel[ $field ] ) . '" class="small-text sfls-version-input"></td></tr>';
		}
		echo '<tr><th scope="row"><label for="sfls-changelog">' . esc_html__( 'Changelog', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-changelog" name="changelog" rows="8" class="large-text">' . esc_textarea( $rel['changelog'] ) . '</textarea><p class="description">' . esc_html__( 'One change per line, starting with "* ", shows as a list. Customers see it in My Account › Pro downloads.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-notes">' . esc_html__( 'Private notes', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-notes" name="notes" rows="3" class="large-text">' . esc_textarea( $rel['notes'] ) . '</textarea><p class="description">' . esc_html__( 'Only shown here.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save release', 'subtleforms-license-server' ) );
		echo '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Highlights the yank form only.
		$focus = isset( $_GET['yank'] );
		if ( 'published' === $rel['status'] ) {
			echo '<form id="sfls-yank" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel sfls-panel--danger' . ( $focus ? ' is-focused' : '' ) . '">';
			wp_nonce_field( 'sfls_release_yank_' . $id );
			echo '<input type="hidden" name="action" value="sfls_release_yank"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><h2>' . esc_html__( 'Yank this version', 'subtleforms-license-server' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Sites stop being offered this version, and customers can no longer download it. The previous published version becomes the latest again. The zip is kept, and you can publish it again later.', 'subtleforms-license-server' ) . '</p>';
			echo '<p><label for="sfls-yank-reason">' . esc_html__( 'Reason (only shown to admins)', 'subtleforms-license-server' ) . '</label><br><input id="sfls-yank-reason" type="text" name="reason" class="large-text" required placeholder="' . esc_attr__( 'For example: breaks the payment field on PHP 8.1', 'subtleforms-license-server' ) . '"' . ( $focus ? ' autofocus' : '' ) . '></p>';
			submit_button( __( 'Yank version', 'subtleforms-license-server' ), 'secondary sfls-button-danger', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		echo '<div class="sfls-edit-side"><div class="sfls-panel"><h2>' . esc_html__( 'Package', 'subtleforms-license-server' ) . '</h2><ul class="sfls-meta-list">';
		$meta = array(
			__( 'Status', 'subtleforms-license-server' )    => wp_kses_post( self::status_pill( $rel ) ),
			__( 'Released', 'subtleforms-license-server' )  => esc_html( $rel['released_at'] ? self::when( $rel['released_at'] ) : __( 'Not yet', 'subtleforms-license-server' ) ),
			__( 'Size', 'subtleforms-license-server' )      => esc_html( $rel['size'] ? SFLS_Releases::size_label( (int) $rel['size'] ) : '–' ),
			__( 'Downloads', 'subtleforms-license-server' ) => esc_html( number_format_i18n( $rel['downloads'] ) ),
			__( 'Source', 'subtleforms-license-server' )    => esc_html( self::source_label( $rel['source'] ) ),
		);
		$git  = self::git_html( $rel );
		if ( '' !== $git ) {
			$meta[ __( 'Git', 'subtleforms-license-server' ) ] = wp_kses_post( $git );
		}
		if ( 'yanked' === $rel['status'] && '' !== $rel['yank_reason'] ) {
			$meta[ __( 'Yanked because', 'subtleforms-license-server' ) ] = esc_html( $rel['yank_reason'] );
		}
		foreach ( $meta as $label => $value ) {
			echo '<li><span>' . esc_html( $label ) . '</span><span>' . $value . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
		echo '</ul>';
		if ( '' !== $rel['sha256'] ) {
			echo '<p class="sfls-sha-full"><span class="description">' . esc_html__( 'SHA-256', 'subtleforms-license-server' ) . '</span><code class="sfls-keybox sfls-mono">' . esc_html( $rel['sha256'] ) . '</code></p>';
		}
		$buttons = array();
		if ( SFLS_Releases::has_package( $rel ) ) {
			$buttons[] = '<a class="button" href="' . esc_url( $this->action_url( 'download', $id ) ) . '">' . esc_html__( 'Download zip', 'subtleforms-license-server' ) . '</a>';
			if ( 'published' !== $rel['status'] ) {
				$buttons[] = '<a class="button button-primary" href="' . esc_url( $this->action_url( 'publish', $id ) ) . '">' . esc_html( 'yanked' === $rel['status'] ? __( 'Publish again', 'subtleforms-license-server' ) : __( 'Publish', 'subtleforms-license-server' ) ) . '</a>';
			}
		} else {
			$buttons[] = '<span class="sfls-danger-text">' . esc_html__( 'The zip file is missing. Upload this version again with "Replace the package" ticked.', 'subtleforms-license-server' ) . '</span>';
		}
		if ( 'published' === $rel['status'] ) {
			$buttons[] = '<a class="button" href="' . esc_url( $this->action_url( 'draft', $id ) ) . '">' . esc_html__( 'Unpublish to draft', 'subtleforms-license-server' ) . '</a>';
		}
		if ( SFLS_Releases::pinned() === $rel['version'] ) {
			$buttons[] = '<a class="button" href="' . esc_url( $this->action_url( 'unpin', $id ) ) . '">' . esc_html__( 'Unpin', 'subtleforms-license-server' ) . '</a>';
		} elseif ( 'published' === $rel['status'] && 'stable' === $rel['channel'] && ( ! $latest || $latest['version'] !== $rel['version'] ) ) {
			$buttons[] = '<a class="button" href="' . esc_url( $this->action_url( 'pin', $id ) ) . '">' . esc_html__( 'Make this the latest', 'subtleforms-license-server' ) . '</a>';
		}
		echo '<p class="sfls-button-row">' . implode( ' ', $buttons ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		echo '<div class="sfls-panel sfls-panel--danger"><h2>' . esc_html__( 'Delete', 'subtleforms-license-server' ) . '</h2><p class="description">' . esc_html__( 'Removes this release and its zip files. Customers can no longer download it. This cannot be undone.', 'subtleforms-license-server' ) . '</p>';
		echo '<p><a class="button sfls-button-danger" href="' . esc_url( $this->action_url( 'delete', $id ) ) . '" onclick="return confirm(\'' . esc_js( sprintf( /* translators: %s: version */ __( 'Delete %s and its zip file permanently? Customers can no longer download it. This cannot be undone.', 'subtleforms-license-server' ), $rel['version'] ) ) . '\');">' . esc_html__( 'Delete release', 'subtleforms-license-server' ) . '</a></p></div>';
		echo '</div></div></div>';
	}

	// ── Release handlers ────────────────────────────────────────────────

	/**
	 * Upload a zip.
	 */
	public function handle_upload(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_release_upload' );
		$file = isset( $_FILES['package'] ) && is_array( $_FILES['package'] ) ? $_FILES['package'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only tmp_name and error are used; the file is checked with is_uploaded_file() and inspect_zip().
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! empty( $file['error'] ) || ! is_uploaded_file( $tmp ) ) {
			$this->admin->flash( __( 'The upload failed. Choose the zip again; check upload_max_filesize if it is large.', 'subtleforms-license-server' ), 'error' );
			$this->back();
		}
		$headers = SFLS_Releases::inspect_zip( $tmp );
		if ( is_wp_error( $headers ) ) {
			$this->admin->flash( $headers->get_error_message(), 'error' );
			$this->back();
		}
		$replace  = ! empty( $_POST['replace'] );
		$existing = SFLS_Releases::get( $headers['version'], true );
		if ( $existing && ! $replace && SFLS_Releases::has_package( $existing ) && ! hash_equals( $existing['sha256'], (string) hash_file( 'sha256', $tmp ) ) ) {
			$this->admin->flash(
				sprintf(
					/* translators: %s: version */
					__( 'Version %s already exists with a different zip. Tick "Replace the package" to replace it.', 'subtleforms-license-server' ),
					$headers['version']
				),
				'error'
			);
			$this->back();
		}
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		$result  = SFLS_Releases::ingest(
			$tmp,
			array(
				'source'    => 'upload',
				'channel'   => $channel,
				'publish'   => ! empty( $_POST['publish'] ),
				'changelog' => isset( $_POST['changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changelog'] ) ) : '',
				'replace'   => $replace,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->admin->flash( $result->get_error_message(), 'error' );
			$this->back();
		}
		$rel = $result['release'];
		if ( 'existing' === $result['result'] ) {
			/* translators: %s: version */
			$message = sprintf( __( 'SubtleForms Pro %s is already stored with exactly this zip. Nothing changed.', 'subtleforms-license-server' ), $rel['version'] );
		} elseif ( 'published' === $rel['status'] ) {
			$message = sprintf(
				/* translators: %s: version */
				'replaced' === $result['result'] ? __( 'The package of SubtleForms Pro %s was replaced.', 'subtleforms-license-server' ) : __( 'SubtleForms Pro %s is published. Licensed sites see it within 12 hours.', 'subtleforms-license-server' ),
				$rel['version']
			);
			$latest = SFLS_Releases::latest( 'stable' );
			if ( 'stable' === $rel['channel'] && $latest && $latest['version'] !== $rel['version'] ) {
				/* translators: %s: version */
				$message .= ' ' . sprintf( __( 'The latest release is still %s, so it is listed as an older version.', 'subtleforms-license-server' ), $latest['version'] );
			}
		} else {
			/* translators: %s: version */
			$message = sprintf( __( 'SubtleForms Pro %s is saved as a draft. Publish it when it is ready.', 'subtleforms-license-server' ), $rel['version'] );
		}
		$this->admin->flash( $message );
		$this->back();
	}

	/**
	 * Row actions: download, publish, draft, pin, unpin, delete.
	 */
	public function handle_row_action(): void {
		$this->admin->guard();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'sfls_release_' . $id );
		$do  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$rel = SFLS_Releases::get_by_id( $id );
		if ( ! $rel ) {
			$this->admin->flash( __( 'That release was not found.', 'subtleforms-license-server' ), 'error' );
			$this->back();
		}
		switch ( $do ) {
			case 'download':
				SFLS_Releases::stream( $rel );
				break;
			case 'publish':
				$result = SFLS_Releases::publish( $id );
				/* translators: %s: version */
				$this->admin->flash( is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( '%s is published.', 'subtleforms-license-server' ), $rel['version'] ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'draft':
				SFLS_Releases::unpublish( $id );
				/* translators: %s: version */
				$this->admin->flash( sprintf( __( '%s is a draft again. Sites are no longer offered it.', 'subtleforms-license-server' ), $rel['version'] ) );
				break;
			case 'pin':
				$result = SFLS_Releases::pin( $rel['version'] );
				/* translators: %s: version */
				$this->admin->flash( is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( '%s is now the latest release.', 'subtleforms-license-server' ), $rel['version'] ), is_wp_error( $result ) ? 'error' : 'success' );
				break;
			case 'unpin':
				SFLS_Releases::unpin();
				$this->admin->flash( __( 'Unpinned. The highest published stable version is the latest again.', 'subtleforms-license-server' ) );
				break;
			case 'delete':
				SFLS_Releases::delete( $id );
				/* translators: %s: version */
				$this->admin->flash( sprintf( __( '%s and its zip were deleted.', 'subtleforms-license-server' ), $rel['version'] ) );
				break;
		}
		$this->back( 'delete' === $do || ! wp_get_referer() ? self::url() : (string) wp_get_referer() );
	}

	/**
	 * Save the edit form.
	 */
	public function handle_edit(): void {
		$this->admin->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		check_admin_referer( 'sfls_release_edit_' . $id );
		$rel = SFLS_Releases::get_by_id( $id );
		if ( ! $rel ) {
			$this->back();
		}
		$data = array(
			'channel'      => isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : $rel['channel'],
			'changelog'    => isset( $_POST['changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changelog'] ) ) : '',
			'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'requires'     => isset( $_POST['requires'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['requires'] ) ), 0, 20 ) : '',
			'tested'       => isset( $_POST['tested'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['tested'] ) ), 0, 20 ) : '',
			'requires_php' => isset( $_POST['requires_php'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['requires_php'] ) ), 0, 20 ) : '',
		);
		SFLS_Releases::update( $id, $data );
		if ( 'beta' === $data['channel'] && SFLS_Releases::pinned() === $rel['version'] ) {
			SFLS_Releases::unpin();
		}
		/* translators: %s: version */
		$this->admin->flash( sprintf( __( '%s saved.', 'subtleforms-license-server' ), $rel['version'] ) );
		$this->back( self::url( array( 'edit' => $id ) ) );
	}

	/**
	 * Yank with a reason.
	 */
	public function handle_yank(): void {
		$this->admin->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		check_admin_referer( 'sfls_release_yank_' . $id );
		$rel = SFLS_Releases::get_by_id( $id );
		if ( ! $rel ) {
			$this->back();
		}
		SFLS_Releases::yank( $id, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '' );
		$latest = SFLS_Releases::latest( 'stable' );
		$this->admin->flash(
			$latest
				/* translators: 1: yanked version, 2: latest version */
				? sprintf( __( '%1$s was yanked. Sites are offered %2$s again.', 'subtleforms-license-server' ), $rel['version'], $latest['version'] )
				/* translators: %s: version */
				: sprintf( __( '%s was yanked. No published stable version is left.', 'subtleforms-license-server' ), $rel['version'] ),
			'warning'
		);
		$this->back();
	}

	/**
	 * Unpin from the notice.
	 */
	public function handle_unpin(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_release_unpin' );
		SFLS_Releases::unpin();
		$this->admin->flash( __( 'Unpinned. The highest published stable version is the latest again.', 'subtleforms-license-server' ) );
		$this->back();
	}

	// ── Settings › Releases ─────────────────────────────────────────────

	/**
	 * Licenses › Settings › Releases.
	 */
	public function settings_tab(): void {
		$this->print_reveal();
		$this->tokens_panel();
		$this->ci_panel();
		$this->github_panel();
		$this->deliveries_panel();
	}

	/**
	 * Deploy tokens.
	 */
	private function tokens_panel(): void {
		$tokens = SFLS_Deploy_Tokens::all();
		echo '<div class="sfls-panel" id="sfls-tokens"><h2>' . esc_html__( 'Deploy tokens', 'subtleforms-license-server' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A deploy token lets CI (for example GitHub Actions) upload and publish releases through the release API. It can only manage releases (scope releases:write). It is shown once and stored as a hash.', 'subtleforms-license-server' ) . '</p>';
		if ( $tokens ) {
			echo '<table class="widefat striped sfls-acts sfls-token-table"><thead><tr><th>' . esc_html__( 'Name', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Token', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Created', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Last used', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Status', 'subtleforms-license-server' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'subtleforms-license-server' ) . '</span></th></tr></thead><tbody>';
			foreach ( $tokens as $token ) {
				$revoked = '' !== $token['revoked_at'];
				echo '<tr><td><strong>' . esc_html( $token['name'] ) . '</strong></td><td><code class="sfls-mono">' . esc_html( $token['hint'] ) . '</code></td><td>' . esc_html( self::when( $token['created_at'] ) ) . '</td>';
				echo '<td>' . esc_html( '' !== $token['last_used_at'] ? self::when( $token['last_used_at'] ) : __( 'Never', 'subtleforms-license-server' ) ) . ( '' !== $token['last_used_ip'] ? '<span class="sfls-cell-note sfls-mono">' . esc_html( $token['last_used_ip'] ) . '</span>' : '' ) . '</td>';
				echo '<td>' . ( $revoked ? '<span class="sfls-admin-pill sfls-admin-pill--bad">' . esc_html__( 'Revoked', 'subtleforms-license-server' ) . '</span>' : '<span class="sfls-admin-pill sfls-admin-pill--ok">' . esc_html__( 'Active', 'subtleforms-license-server' ) . '</span>' ) . '</td><td class="sfls-right">';
				if ( ! $revoked ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( 'sfls_token_revoke_' . $token['id'] );
					echo '<input type="hidden" name="action" value="sfls_token_revoke"><input type="hidden" name="id" value="' . esc_attr( (string) $token['id'] ) . '">';
					echo '<button type="submit" class="button button-small sfls-button-danger" onclick="return confirm(\'' . esc_js( __( 'Revoke this token? CI jobs that use it stop working.', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Revoke', 'subtleforms-license-server' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-inline-create">';
		wp_nonce_field( 'sfls_token_create' );
		echo '<input type="hidden" name="action" value="sfls_token_create"><label for="sfls-token-name">' . esc_html__( 'Name', 'subtleforms-license-server' ) . '</label> <input id="sfls-token-name" type="text" name="name" class="regular-text" placeholder="' . esc_attr__( 'GitHub Actions', 'subtleforms-license-server' ) . '" required> ';
		submit_button( __( 'Create token', 'subtleforms-license-server' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * CI release API.
	 */
	private function ci_panel(): void {
		$settings = SFLS_Releases::settings();
		$endpoint = rest_url( 'sfls/v1/releases' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel" id="sfls-ci"><h2>' . esc_html__( 'Release API for CI', 'subtleforms-license-server' ) . '</h2>';
		wp_nonce_field( 'sfls_release_ci' );
		echo '<input type="hidden" name="action" value="sfls_release_ci"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sfls-ci-url">' . esc_html__( 'Endpoint', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-ci-url" type="text" readonly class="large-text sfls-mono" value="' . esc_attr( $endpoint ) . '" onfocus="this.select();"><p class="description">' . esc_html__( 'POST the zip as a multipart field named "package" with Authorization: Bearer and a deploy token. Optional fields: changelog, channel (stable or beta), publish, git_ref, git_commit, sha256, replace.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Publishing', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="auto_publish" value="1"' . checked( (int) $settings['auto_publish'], 1, false ) . '> ' . esc_html__( 'Auto publish CI releases', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'When off, a release sent without publish=true is saved as a draft for you to check and publish here.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Example', 'subtleforms-license-server' ) . '</th><td><pre class="sfls-code">' . esc_html( "curl --fail-with-body -X POST \\\n  -H \"Authorization: Bearer \$SFLS_DEPLOY_TOKEN\" \\\n  -F \"package=@dist/subtleforms-pro-2.4.0.zip\" \\\n  -F \"changelog=* New: ...\" \\\n  -F \"git_ref=v2.4.0\" -F \"publish=true\" \\\n  " . $endpoint ) . '</pre><p class="description">' . esc_html__( 'The SubtleForms Pro repository has a GitHub Actions workflow that does this on every v* tag. See docs/CI.md in the license server plugin.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'subtleforms-license-server' ) );
		echo '</form>';
	}

	/**
	 * GitHub connection.
	 */
	private function github_panel(): void {
		$config = SFLS_GitHub::config();
		echo '<div class="sfls-panel" id="sfls-github"><h2>' . esc_html__( 'GitHub connection', 'subtleforms-license-server' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Publish a release on GitHub with the Pro zip attached, and GitHub tells this site through a webhook. The zip is downloaded, checked and stored as a release. Pre-releases go to the beta channel.', 'subtleforms-license-server' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_github_settings' );
		echo '<input type="hidden" name="action" value="sfls_github_settings"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sfls-gh-repo">' . esc_html__( 'Repository', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-gh-repo" type="text" name="repo" class="regular-text sfls-mono" value="' . esc_attr( $config['repo'] ) . '" placeholder="owner/subtleforms-pro"><p class="description">' . esc_html__( 'owner/repo. Only events from this repository are used, and tags and commits link to it.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-gh-token">' . esc_html__( 'GitHub token', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-gh-token" type="password" autocomplete="new-password" name="token" class="regular-text sfls-mono" value="" placeholder="' . esc_attr( '' !== $config['token'] ? '••••••••' . substr( $config['token'], -4 ) : 'github_pat_…' ) . '">';
		if ( '' !== $config['token'] ) {
			echo ' <label class="sfls-inline-check"><input type="checkbox" name="clear_token" value="1"> ' . esc_html__( 'Remove', 'subtleforms-license-server' ) . '</label>';
		}
		echo '<p class="description">' . esc_html( '' !== $config['token'] ? sprintf( /* translators: %s: last four characters */ __( 'Saved, ends in %s. Leave empty to keep it.', 'subtleforms-license-server' ), substr( $config['token'], -4 ) ) : __( 'Needed for a private repository: a fine-grained token with read access to Contents of that repository only. Public repositories work without one.', 'subtleforms-license-server' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Webhook secret', 'subtleforms-license-server' ) . '</th><td>';
		if ( '' !== $config['secret'] ) {
			echo '<code class="sfls-mono">••••••••' . esc_html( substr( $config['secret'], -4 ) ) . '</code> <span class="description">' . esc_html__( 'Saved. Generate a new one to see it again; then update it in GitHub too.', 'subtleforms-license-server' ) . '</span>';
		} else {
			echo '<span class="sfls-field-warning">' . esc_html__( 'Not set. Generate a secret and paste it into the GitHub webhook.', 'subtleforms-license-server' ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="sfls-gh-pattern">' . esc_html__( 'Asset name pattern', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-gh-pattern" type="text" name="asset_pattern" class="regular-text sfls-mono" value="' . esc_attr( $config['asset_pattern'] ) . '"><p class="description">' . esc_html__( 'The release asset to import. * matches anything.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Publishing', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="auto_publish" value="1"' . checked( (int) $config['auto_publish'], 1, false ) . '> ' . esc_html__( 'Auto publish GitHub releases', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'When off, imported releases are saved as drafts.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-gh-url">' . esc_html__( 'Webhook URL', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-gh-url" type="text" readonly class="large-text sfls-mono" value="' . esc_attr( SFLS_GitHub::webhook_url() ) . '" onfocus="this.select();"><p class="description">' . esc_html__( 'In the repository, open Settings › Webhooks › Add webhook. Payload URL: this address. Content type: application/json. Secret: the webhook secret. Events: choose "Let me select individual events" and tick Releases only.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save GitHub settings', 'subtleforms-license-server' ) );
		echo '</form>';

		echo '<div class="sfls-button-row sfls-github-tools">';
		foreach ( array(
			'sfls_github_secret' => array( '' !== $config['secret'] ? __( 'Generate new webhook secret', 'subtleforms-license-server' ) : __( 'Generate webhook secret', 'subtleforms-license-server' ), '' !== $config['secret'] ? __( 'Replace the webhook secret? Deliveries fail until you paste the new secret into GitHub.', 'subtleforms-license-server' ) : '' ),
			'sfls_github_test'   => array( __( 'Test connection', 'subtleforms-license-server' ), '' ),
		) as $action => [ $label, $confirm ] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( $action );
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
			$attrs = '' !== $confirm ? array( 'onclick' => 'return confirm(\'' . esc_js( $confirm ) . '\');' ) : array();
			if ( 'sfls_github_test' === $action && '' === $config['repo'] ) {
				$attrs['disabled'] = 'disabled';
			}
			submit_button( $label, 'secondary', 'submit', false, $attrs );
			echo '</form>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-import-form">';
		wp_nonce_field( 'sfls_github_import' );
		echo '<input type="hidden" name="action" value="sfls_github_import"><label class="screen-reader-text" for="sfls-gh-tag">' . esc_html__( 'Tag', 'subtleforms-license-server' ) . '</label><input id="sfls-gh-tag" type="text" name="tag" class="sfls-mono" placeholder="v2.4.0" required> ';
		submit_button( __( 'Import a release by tag', 'subtleforms-license-server' ), 'secondary', 'submit', false, '' === $config['repo'] ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form></div></div>';
	}

	/**
	 * The last 20 webhook deliveries.
	 */
	private function deliveries_panel(): void {
		$rows = SFLS_GitHub::recent_deliveries( 20 );
		echo '<div class="sfls-panel" id="sfls-deliveries"><h2>' . esc_html__( 'Recent GitHub deliveries', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'None yet. When the webhook is added, GitHub sends a ping that shows up here.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		$tones = array(
			'stored'   => 'ok',
			'ok'       => 'ok',
			'existing' => 'neutral',
			'ignored'  => 'neutral',
			'failed'   => 'bad',
			'rejected' => 'bad',
		);
		echo '<table class="widefat striped sfls-acts sfls-log sfls-deliveries"><thead><tr><th>' . esc_html__( 'Received', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Event', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Tag', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Result', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Message', 'subtleforms-license-server' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'subtleforms-license-server' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( self::when( $row['received_at'] ) ) . '</td><td class="sfls-mono">' . esc_html( trim( $row['event'] . ' ' . $row['action'] ) ) . ( '' !== $row['delivery_id'] ? '<span class="sfls-cell-note">' . esc_html( $row['delivery_id'] ) . '</span>' : '' ) . '</td>';
			echo '<td class="sfls-mono">' . esc_html( '' !== $row['tag'] ? $row['tag'] : '–' ) . '</td>';
			echo '<td><span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tones[ $row['result'] ] ?? 'neutral' ) . '">' . esc_html( $row['result'] ) . '</span></td><td>' . esc_html( (string) $row['message'] ) . '</td><td class="sfls-right">';
			if ( (int) $row['has_payload'] && 'release' === $row['event'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'sfls_github_retry_' . $row['id'] );
				echo '<input type="hidden" name="action" value="sfls_github_retry"><input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '"><button type="submit" class="button button-small">' . esc_html__( 'Retry', 'subtleforms-license-server' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Settings handlers ───────────────────────────────────────────────

	/**
	 * Create a deploy token and show it once.
	 */
	public function handle_token_create(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_token_create' );
		$created = SFLS_Deploy_Tokens::create( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', get_current_user_id() );
		/* translators: %s: token name */
		$this->reveal( sprintf( __( 'Deploy token "%s" created', 'subtleforms-license-server' ), $created['record']['name'] ), $created['token'] );
		$this->back( self::settings_url( 'sfls-tokens' ) );
	}

	/**
	 * Revoke a deploy token.
	 */
	public function handle_token_revoke(): void {
		$this->admin->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		check_admin_referer( 'sfls_token_revoke_' . $id );
		SFLS_Deploy_Tokens::revoke( $id );
		$this->admin->flash( __( 'Token revoked. Requests that use it are refused from now on.', 'subtleforms-license-server' ) );
		$this->back( self::settings_url( 'sfls-tokens' ) );
	}

	/**
	 * Save CI settings.
	 */
	public function handle_ci_settings(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_release_ci' );
		SFLS_Releases::save_settings( array( 'auto_publish' => ! empty( $_POST['auto_publish'] ) ) );
		$this->admin->flash( __( 'Release API settings saved.', 'subtleforms-license-server' ) );
		$this->back( self::settings_url( 'sfls-ci' ) );
	}

	/**
	 * Save GitHub settings. An empty token field keeps the saved token.
	 */
	public function handle_github_settings(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_github_settings' );
		$raw    = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$values = array(
			'repo'          => $raw,
			'asset_pattern' => isset( $_POST['asset_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_pattern'] ) ) : '',
			'auto_publish'  => ! empty( $_POST['auto_publish'] ),
		);
		$token  = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' !== $token ) {
			$values['token'] = $token;
		} elseif ( ! empty( $_POST['clear_token'] ) ) {
			$values['token'] = '';
		}
		SFLS_GitHub::save_config( $values );
		if ( '' !== $raw && '' === SFLS_GitHub::config()['repo'] ) {
			$this->admin->flash( __( 'Saved, but the repository was not understood. Enter it as owner/repo.', 'subtleforms-license-server' ), 'warning' );
		} else {
			$this->admin->flash( __( 'GitHub settings saved.', 'subtleforms-license-server' ) );
		}
		$this->back( self::settings_url( 'sfls-github' ) );
	}

	/**
	 * Generate a webhook secret and show it once.
	 */
	public function handle_github_secret(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_github_secret' );
		$this->reveal( __( 'Webhook secret generated. Paste it into the Secret field of the GitHub webhook', 'subtleforms-license-server' ), SFLS_GitHub::generate_secret() );
		$this->back( self::settings_url( 'sfls-github' ) );
	}

	/**
	 * Test connection.
	 */
	public function handle_github_test(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_github_test' );
		$result = SFLS_GitHub::test_connection();
		if ( is_wp_error( $result ) ) {
			$this->admin->flash( $result->get_error_message(), 'error' );
		} else {
			$this->admin->flash(
				sprintf(
					/* translators: 1: repository, 2: private or public, 3: default branch */
					__( 'Connected to %1$s (%2$s, default branch %3$s).', 'subtleforms-license-server' ),
					$result['full_name'],
					$result['private'] ? __( 'private', 'subtleforms-license-server' ) : __( 'public', 'subtleforms-license-server' ),
					'' !== $result['default_branch'] ? $result['default_branch'] : '–'
				)
			);
		}
		$this->back( self::settings_url( 'sfls-github' ) );
	}

	/**
	 * Import a release by tag.
	 */
	public function handle_github_import(): void {
		$this->admin->guard();
		check_admin_referer( 'sfls_github_import' );
		$outcome = SFLS_GitHub::import_tag( isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '' );
		$this->admin->flash( $outcome['message'], 'failed' === $outcome['result'] ? 'error' : 'success' );
		$this->back( self::settings_url( 'sfls-deliveries' ) );
	}

	/**
	 * Retry a stored delivery.
	 */
	public function handle_github_retry(): void {
		$this->admin->guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked next.
		check_admin_referer( 'sfls_github_retry_' . $id );
		$outcome = SFLS_GitHub::retry( $id );
		$this->admin->flash( $outcome['message'], 'failed' === $outcome['result'] ? 'error' : 'success' );
		$this->back( self::settings_url( 'sfls-deliveries' ) );
	}
}
