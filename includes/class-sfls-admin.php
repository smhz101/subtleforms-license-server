<?php
/**
 * Admin: Licenses list, issue a key, edit, and publish Pro releases.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

final class SFLS_Admin {

	const CAP = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sfls_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_sfls_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_sfls_release', array( $this, 'handle_release' ) );
	}

	public function menu(): void {
		add_menu_page( __( 'Licenses', 'subtleforms-license-server' ), __( 'Licenses', 'subtleforms-license-server' ), self::CAP, 'sfls', array( $this, 'page_list' ), 'dashicons-admin-network', 58 );
		add_submenu_page( 'sfls', __( 'All licenses', 'subtleforms-license-server' ), __( 'All licenses', 'subtleforms-license-server' ), self::CAP, 'sfls', array( $this, 'page_list' ) );
		add_submenu_page( 'sfls', __( 'Issue license', 'subtleforms-license-server' ), __( 'Issue license', 'subtleforms-license-server' ), self::CAP, 'sfls-new', array( $this, 'page_new' ) );
		add_submenu_page( 'sfls', __( 'Pro releases', 'subtleforms-license-server' ), __( 'Pro releases', 'subtleforms-license-server' ), self::CAP, 'sfls-release', array( $this, 'page_release' ) );
	}

	// ── Pages ───────────────────────────────────────────────────────────

	public function page_list(): void {
		global $wpdb;
		$table  = SFLS_DB::licenses();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$offset = ( $paged - 1 ) * $per;

		if ( $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key LIKE %s OR customer_email LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, $like, $per, $offset ), ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_key LIKE %s OR customer_email LIKE %s", $like, $like ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Licenses', 'subtleforms-license-server' ) . '</h1> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=sfls-new' ) ) . '" class="page-title-action">' . esc_html__( 'Issue license', 'subtleforms-license-server' ) . '</a><hr class="wp-header-end">';
		$this->notice();

		echo '<form method="get"><input type="hidden" name="page" value="sfls"><p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Key or email', 'subtleforms-license-server' ) . '"> <button class="button">' . esc_html__( 'Search', 'subtleforms-license-server' ) . '</button></p></form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'Key', 'subtleforms-license-server' ), __( 'Customer', 'subtleforms-license-server' ), __( 'Plan', 'subtleforms-license-server' ), __( 'Status', 'subtleforms-license-server' ), __( 'Sites', 'subtleforms-license-server' ), __( 'Expires', 'subtleforms-license-server' ), __( 'Order', 'subtleforms-license-server' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No licenses yet. Issue one, or sell a product marked as a SubtleForms license.', 'subtleforms-license-server' ) . '</td></tr>';
		}

		foreach ( $rows as $lic ) {
			$state = SFLS_Licenses::state( $lic );
			$acts  = SFLS_Licenses::activations( (int) $lic['id'] );
			$limit = (int) $lic['sites_allowed'];
			echo '<tr>';
			echo '<td><code>' . esc_html( $lic['license_key'] ) . '</code>';
			if ( $acts ) {
				echo '<details><summary>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d activation', '%d activations', count( $acts ), 'subtleforms-license-server' ), count( $acts ) ) ) . '</summary><ul style="margin:4px 0 0;">';
				foreach ( $acts as $act ) {
					echo '<li>' . esc_html( $act['domain'] . ( $act['is_local'] ? ' (local)' : '' ) . ( $act['plugin_version'] ? ' · v' . $act['plugin_version'] : '' ) ) . '</li>';
				}
				echo '</ul></details>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $lic['customer_email'] ) . '</td>';
			echo '<td>' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', $state['status'] ) ) ) . '</td>';
			echo '<td>' . esc_html( SFLS_Licenses::used_slots( (int) $lic['id'] ) . ' / ' . ( $limit ? $limit : '∞' ) ) . '</td>';
			echo '<td>' . esc_html( $lic['expires_at'] ? wp_date( get_option( 'date_format' ), strtotime( $lic['expires_at'] . ' UTC' ) ) : __( 'Never', 'subtleforms-license-server' ) ) . '</td>';
			echo '<td>' . ( $lic['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $lic['order_id'] . '&action=edit' ) ) . '">#' . (int) $lic['order_id'] . '</a>' : '&mdash;' ) . '</td>';
			echo '<td>';
			$actions = array(
				'extend'  => __( '+1 year', 'subtleforms-license-server' ),
				'suspended' === $lic['status'] ? 'resume' : 'suspend' => 'suspended' === $lic['status'] ? __( 'Resume', 'subtleforms-license-server' ) : __( 'Suspend', 'subtleforms-license-server' ),
				'reset'   => __( 'Reset sites', 'subtleforms-license-server' ),
				'delete'  => __( 'Delete', 'subtleforms-license-server' ),
			);
			$links = array();
			foreach ( $actions as $do => $label ) {
				$url     = wp_nonce_url( admin_url( 'admin-post.php?action=sfls_action&do=' . $do . '&id=' . (int) $lic['id'] ), 'sfls_action_' . $lic['id'] );
				$confirm = 'delete' === $do ? ' onclick="return confirm(\'' . esc_js( __( 'Delete this license permanently?', 'subtleforms-license-server' ) ) . '\');"' : '';
				$links[] = '<a href="' . esc_url( $url ) . '"' . $confirm . ( 'delete' === $do ? ' style="color:#b32d2e"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
		echo '</div>';
	}

	public function page_new(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Issue license', 'subtleforms-license-server' ) . '</h1>';
		echo '<p>' . esc_html__( 'For complimentary, partner, support or test keys. Paid keys are issued automatically when a WooCommerce order completes.', 'subtleforms-license-server' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_create' );
		echo '<input type="hidden" name="action" value="sfls_create"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="sfls-email">' . esc_html__( 'Customer email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-email" type="email" name="email" class="regular-text"></td></tr>';
		echo '<tr><th><label for="sfls-plan">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-plan" name="plan">';
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, 'pro', false ) . '>' . esc_html( $plan['label'] . ' (' . ( $plan['sites'] ? $plan['sites'] : '∞' ) . ' sites)' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="sfls-sites">' . esc_html__( 'Sites', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-sites" type="number" min="0" name="sites" class="small-text" placeholder="' . esc_attr__( 'plan', 'subtleforms-license-server' ) . '"> <span class="description">' . esc_html__( 'Empty for the plan default, 0 for unlimited.', 'subtleforms-license-server' ) . '</span></td></tr>';
		echo '<tr><th><label for="sfls-months">' . esc_html__( 'Duration (months)', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-months" type="number" min="0" name="months" value="12" class="small-text"> <span class="description">' . esc_html__( '0 for lifetime.', 'subtleforms-license-server' ) . '</span></td></tr>';
		echo '<tr><th><label for="sfls-count">' . esc_html__( 'How many', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-count" type="number" min="1" max="50" name="count" value="1" class="small-text"></td></tr>';
		echo '<tr><th><label for="sfls-notes">' . esc_html__( 'Notes', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-notes" name="notes" rows="3" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Issue license', 'subtleforms-license-server' ) );
		echo '</form></div>';
	}

	public function page_release(): void {
		$rel  = SFLS_Releases::current();
		$path = SFLS_Releases::package_path();
		echo '<div class="wrap"><h1>' . esc_html__( 'Pro releases', 'subtleforms-license-server' ) . '</h1>';
		$this->notice();
		echo '<p>' . esc_html__( 'Upload a new SubtleForms Pro zip and every site with an active license is offered the update in Dashboard › Updates.', 'subtleforms-license-server' ) . '</p>';
		if ( $rel['version'] ) {
			echo '<p><strong>' . esc_html( sprintf( /* translators: %s: version */ __( 'Current release: %s', 'subtleforms-license-server' ), $rel['version'] ) ) . '</strong> ' . ( $path && is_readable( $path ) ? esc_html( size_format( filesize( $path ) ) ) : '<span style="color:#b32d2e">' . esc_html__( 'package missing', 'subtleforms-license-server' ) . '</span>' ) . '</p>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_release' );
		echo '<input type="hidden" name="action" value="sfls_release"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="sfls-zip">' . esc_html__( 'Plugin zip', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-zip" type="file" name="package" accept=".zip"> <p class="description">' . esc_html__( 'Leave empty to only update the details below.', 'subtleforms-license-server' ) . '</p></td></tr>';
		foreach ( array(
			'version'      => __( 'Version', 'subtleforms-license-server' ),
			'requires'     => __( 'Requires WordPress', 'subtleforms-license-server' ),
			'tested'       => __( 'Tested up to', 'subtleforms-license-server' ),
			'requires_php' => __( 'Requires PHP', 'subtleforms-license-server' ),
		) as $field => $label ) {
			echo '<tr><th><label for="sfls-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td><input id="sfls-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $rel[ $field ] ) . '" class="regular-text"></td></tr>';
		}
		echo '<tr><th><label for="sfls-changelog">' . esc_html__( 'Changelog', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-changelog" name="changelog" rows="8" class="large-text">' . esc_textarea( $rel['changelog'] ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Publish release', 'subtleforms-license-server' ) );
		echo '</form></div>';
	}

	// ── Handlers ────────────────────────────────────────────────────────

	public function handle_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'subtleforms-license-server' ) );
		}
		check_admin_referer( 'sfls_create' );
		$count = min( 50, max( 1, absint( $_POST['count'] ?? 1 ) ) );
		$sites = isset( $_POST['sites'] ) && '' !== $_POST['sites'] ? absint( $_POST['sites'] ) : null;
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$user  = $email ? get_user_by( 'email', $email ) : null;
		$keys  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$args = array(
				'plan'           => sanitize_key( wp_unslash( $_POST['plan'] ?? 'pro' ) ),
				'customer_email' => $email,
				'user_id'        => $user ? $user->ID : 0,
				'months'         => absint( $_POST['months'] ?? 12 ),
				'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			);
			if ( null !== $sites ) {
				$args['sites_allowed'] = $sites;
			}
			$keys[] = SFLS_Licenses::create( $args )['license_key'];
		}
		set_transient( 'sfls_notice_' . get_current_user_id(), sprintf( /* translators: %s: keys */ __( 'Issued: %s', 'subtleforms-license-server' ), implode( ', ', $keys ) ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls' ) );
		exit;
	}

	public function handle_action(): void {
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'subtleforms-license-server' ) );
		}
		check_admin_referer( 'sfls_action_' . $id );
		$do = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
		switch ( $do ) {
			case 'extend':
				SFLS_Licenses::extend( $id, 12 );
				break;
			case 'suspend':
				SFLS_Licenses::update( $id, array( 'status' => 'suspended' ) );
				break;
			case 'resume':
				SFLS_Licenses::update( $id, array( 'status' => 'active' ) );
				break;
			case 'reset':
				global $wpdb;
				$wpdb->delete( SFLS_DB::activations(), array( 'license_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				break;
			case 'delete':
				SFLS_Licenses::delete( $id );
				break;
		}
		set_transient( 'sfls_notice_' . get_current_user_id(), __( 'License updated.', 'subtleforms-license-server' ), 60 );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=sfls' ) );
		exit;
	}

	public function handle_release(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'subtleforms-license-server' ) );
		}
		check_admin_referer( 'sfls_release' );

		$data = array(
			'version'      => sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) ),
			'requires'     => sanitize_text_field( wp_unslash( $_POST['requires'] ?? '' ) ),
			'tested'       => sanitize_text_field( wp_unslash( $_POST['tested'] ?? '' ) ),
			'requires_php' => sanitize_text_field( wp_unslash( $_POST['requires_php'] ?? '' ) ),
			'changelog'    => sanitize_textarea_field( wp_unslash( $_POST['changelog'] ?? '' ) ),
			'updated'      => gmdate( 'Y-m-d' ),
		);

		if ( ! empty( $_FILES['package']['tmp_name'] ) ) {
			$name = sanitize_file_name( wp_unslash( $_FILES['package']['name'] ?? 'subtleforms-pro.zip' ) );
			if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				wp_die( esc_html__( 'Upload a .zip file.', 'subtleforms-license-server' ) );
			}
			$dir = SFLS_Releases::dir();
			wp_mkdir_p( $dir );
			$target = trailingslashit( $dir ) . 'subtleforms-pro-' . sanitize_file_name( $data['version'] ?: gmdate( 'YmdHis' ) ) . '.zip';
			if ( ! move_uploaded_file( $_FILES['package']['tmp_name'], $target ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				wp_die( esc_html__( 'The file could not be saved.', 'subtleforms-license-server' ) );
			}
			$data['file'] = basename( $target );
		}

		SFLS_Releases::save( $data );
		set_transient( 'sfls_notice_' . get_current_user_id(), __( 'Release saved. Licensed sites will see it within 12 hours.', 'subtleforms-license-server' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-release' ) );
		exit;
	}

	private function notice(): void {
		$key = 'sfls_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}
