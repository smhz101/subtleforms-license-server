<?php
/**
 * Admin: licenses list and editor, issue a key, products wizard, Pro releases
 * and settings.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and form handlers.
 */
final class SFLS_Admin {

	const CAP      = 'manage_options';
	const PER_PAGE = 20;

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_sfls_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_sfls_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_sfls_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_sfls_release', array( $this, 'handle_release' ) );
		add_action( 'admin_post_sfls_products', array( $this, 'handle_products' ) );
		add_action( 'admin_post_sfls_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_sfls_run_reminders', array( $this, 'handle_run_reminders' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SFLS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu(): void {
		add_menu_page( __( 'Licenses', 'subtleforms-license-server' ), __( 'Licenses', 'subtleforms-license-server' ), self::CAP, 'sfls', array( $this, 'page_list' ), 'dashicons-admin-network', 58 );
		add_submenu_page( 'sfls', __( 'All licenses', 'subtleforms-license-server' ), __( 'All licenses', 'subtleforms-license-server' ), self::CAP, 'sfls', array( $this, 'page_list' ) );
		add_submenu_page( 'sfls', __( 'Issue license', 'subtleforms-license-server' ), __( 'Issue license', 'subtleforms-license-server' ), self::CAP, 'sfls-new', array( $this, 'page_new' ) );
		add_submenu_page( 'sfls', __( 'Products', 'subtleforms-license-server' ), __( 'Products', 'subtleforms-license-server' ), self::CAP, 'sfls-products', array( $this, 'page_products' ) );
		add_submenu_page( 'sfls', __( 'Pro releases', 'subtleforms-license-server' ), __( 'Pro releases', 'subtleforms-license-server' ), self::CAP, 'sfls-release', array( $this, 'page_release' ) );
		add_submenu_page( 'sfls', __( 'Settings', 'subtleforms-license-server' ), __( 'Settings', 'subtleforms-license-server' ), self::CAP, 'sfls-settings', array( $this, 'page_settings' ) );
	}

	/**
	 * Admin stylesheet on our screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( string $hook ): void {
		if ( str_contains( $hook, 'sfls' ) ) {
			wp_enqueue_style( 'sfls-admin', SFLS_URL . 'assets/css/sfls-admin.css', array(), SFLS_VERSION );
		}
	}

	/**
	 * Plugins screen links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=sfls-products' ) ) . '">' . esc_html__( 'Set up products', 'subtleforms-license-server' ) . '</a>' );
		return $links;
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Stop unless the user may manage licenses.
	 */
	private function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage licenses.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Queue a notice for the next screen.
	 *
	 * @param string $message Message.
	 * @param string $type    success | error | warning.
	 */
	private function flash( string $message, string $type = 'success' ): void {
		set_transient(
			'sfls_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	/**
	 * Print a queued notice.
	 */
	private function notice(): void {
		$key = 'sfls_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		$msg  = is_array( $msg ) ? $msg : array(
			'message' => (string) $msg,
			'type'    => 'success',
		);
		$type = in_array( $msg['type'], array( 'success', 'error', 'warning' ), true ) ? $msg['type'] : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg['message'] ) . '</p></div>';
	}

	/**
	 * URL of an admin action (nonce protected).
	 *
	 * @param string $do     Action.
	 * @param int    $id     License ID.
	 * @param array  $extra  Extra args.
	 */
	private function action_url( string $do, int $id, array $extra = array() ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action' => 'sfls_action',
						'do'     => $do,
						'id'     => $id,
					),
					$extra
				),
				admin_url( 'admin-post.php' )
			),
			'sfls_action_' . $id
		);
	}

	/**
	 * Status pill for admin tables.
	 *
	 * @param string $status State slug.
	 */
	private function pill( string $status ): string {
		$tone = array(
			'valid'        => 'ok',
			'grace_period' => 'warn',
			'expired'      => 'bad',
			'suspended'    => 'bad',
		);
		return '<span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tone[ $status ] ?? 'neutral' ) . '">' . esc_html( SFLS_Licenses::status_label( $status ) ) . '</span>';
	}

	/**
	 * Page header.
	 *
	 * @param string $title  Title.
	 * @param string $action Optional page-title-action HTML (escaped).
	 */
	private function header( string $title, string $action = '' ): void {
		echo '<div class="wrap sfls-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		echo wp_kses_post( $action );
		echo '<hr class="wp-header-end">';
		$this->notice();
	}

	// ── Licenses list ───────────────────────────────────────────────────

	/**
	 * Licenses list (or the editor when ?edit=ID).
	 */
	public function page_list(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( ! empty( $_GET['edit'] ) ) {
			$this->page_edit( absint( $_GET['edit'] ) );
			return;
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$filters = array(
			''          => __( 'All', 'subtleforms-license-server' ),
			'active'    => __( 'Active', 'subtleforms-license-server' ),
			'expiring'  => __( 'Expiring soon', 'subtleforms-license-server' ),
			'expired'   => __( 'Expired', 'subtleforms-license-server' ),
			'suspended' => __( 'Suspended', 'subtleforms-license-server' ),
		);
		$filter = isset( $filters[ $filter ] ) ? $filter : '';

		[ $rows, $total ] = SFLS_Licenses::query( $search, $filter, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		$this->header( __( 'Licenses', 'subtleforms-license-server' ), '<a href="' . esc_url( admin_url( 'admin.php?page=sfls-new' ) ) . '" class="page-title-action">' . esc_html__( 'Issue license', 'subtleforms-license-server' ) . '</a>' );

		$stats = SFLS_Licenses::stats();
		echo '<div class="sfls-admin-cards">';
		$cards = array(
			array( __( 'Active licenses', 'subtleforms-license-server' ), $stats['active'], add_query_arg( 'status', 'active', admin_url( 'admin.php?page=sfls' ) ) ),
			array( __( 'Total activations', 'subtleforms-license-server' ), $stats['activations'], '' ),
			array( __( 'Expiring in 30 days', 'subtleforms-license-server' ), $stats['expiring'], add_query_arg( 'status', 'expiring', admin_url( 'admin.php?page=sfls' ) ) ),
		);
		foreach ( $cards as [ $label, $value, $link ] ) {
			echo '<div class="sfls-admin-card"><span class="sfls-admin-card-label">' . esc_html( $label ) . '</span><span class="sfls-admin-card-value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
			if ( $link ) {
				echo '<a class="sfls-admin-card-link" href="' . esc_url( $link ) . '">' . esc_html__( 'View', 'subtleforms-license-server' ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div>';

		if ( ! SFLS_Products::woo_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WooCommerce is not active, so licenses cannot be sold yet. You can still issue keys by hand.', 'subtleforms-license-server' ) . '</p></div>';
		} elseif ( ! SFLS_Products::all() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No license products yet.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sfls-products' ) ) . '">' . esc_html__( 'Set up pricing in a minute', 'subtleforms-license-server' ) . '</a></p></div>';
		}

		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $filters as $slug => $label ) {
			$url     = add_query_arg(
				array(
					'page'   => 'sfls',
					'status' => $slug ? $slug : false,
					's'      => $search ? $search : false,
				),
				admin_url( 'admin.php' )
			);
			$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $filter === $slug ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo wp_kses_post( implode( ' |</li>', $links ) . '</li>' );
		echo '</ul>';

		echo '<form method="get" class="sfls-search"><input type="hidden" name="page" value="sfls">';
		if ( $filter ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filter ) . '">';
		}
		echo '<p class="search-box"><label class="screen-reader-text" for="sfls-search">' . esc_html__( 'Search licenses', 'subtleforms-license-server' ) . '</label><input type="search" id="sfls-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Key or email', 'subtleforms-license-server' ) . '"> <button class="button">' . esc_html__( 'Search', 'subtleforms-license-server' ) . '</button></p></form>';

		echo '<table class="wp-list-table widefat fixed striped sfls-table"><thead><tr>';
		$heads = array(
			'key'      => __( 'Key', 'subtleforms-license-server' ),
			'customer' => __( 'Customer', 'subtleforms-license-server' ),
			'plan'     => __( 'Plan', 'subtleforms-license-server' ),
			'status'   => __( 'Status', 'subtleforms-license-server' ),
			'sites'    => __( 'Sites', 'subtleforms-license-server' ),
			'expires'  => __( 'Expires', 'subtleforms-license-server' ),
			'order'    => __( 'Order', 'subtleforms-license-server' ),
		);
		foreach ( $heads as $col => $label ) {
			echo '<th scope="col" class="column-' . esc_attr( $col ) . ( 'key' === $col ? ' column-primary' : '' ) . '">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html( $search || $filter ? __( 'No licenses match.', 'subtleforms-license-server' ) : __( 'No licenses yet. Issue one, or sell a product marked as a SubtleForms license.', 'subtleforms-license-server' ) ) . '</td></tr>';
		}

		foreach ( $rows as $lic ) {
			$id    = (int) $lic['id'];
			$state = SFLS_Licenses::state( $lic );
			$limit = (int) $lic['sites_allowed'];
			$edit  = add_query_arg(
				array(
					'page' => 'sfls',
					'edit' => $id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td class="column-key column-primary" data-colname="' . esc_attr( $heads['key'] ) . '"><a class="row-title sfls-mono" href="' . esc_url( $edit ) . '">' . esc_html( $lic['license_key'] ) . '</a>';
			$actions = array(
				'<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'subtleforms-license-server' ) . '</a>',
			);
			if ( $lic['expires_at'] ) {
				$actions[] = '<a href="' . esc_url( $this->action_url( 'extend', $id ) ) . '">' . esc_html__( '+1 year', 'subtleforms-license-server' ) . '</a>';
			}
			$actions[] = 'active' === $lic['status']
				? '<a href="' . esc_url( $this->action_url( 'suspend', $id ) ) . '">' . esc_html__( 'Suspend', 'subtleforms-license-server' ) . '</a>'
				: '<a href="' . esc_url( $this->action_url( 'resume', $id ) ) . '">' . esc_html__( 'Resume', 'subtleforms-license-server' ) . '</a>';
			$actions[] = '<span class="trash"><a href="' . esc_url( $this->action_url( 'delete', $id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this license permanently?', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Delete', 'subtleforms-license-server' ) . '</a></span>';
			echo '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'subtleforms-license-server' ) . '</span></button></td>';
			echo '<td class="column-customer" data-colname="' . esc_attr( $heads['customer'] ) . '">' . esc_html( $lic['customer_email'] ? $lic['customer_email'] : '–' ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['plan'] ) . '">' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['status'] ) . '">' . wp_kses_post( $this->pill( $state['status'] ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['sites'] ) . '">' . esc_html( SFLS_Licenses::used_slots( $id ) . ' / ' . ( $limit ? $limit : '∞' ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['expires'] ) . '">' . esc_html( SFLS_Licenses::expiry_label( $lic ) ) . '</td>';
			echo '<td data-colname="' . esc_attr( $heads['order'] ) . '">' . ( $lic['order_id'] ? '<a href="' . esc_url( $this->order_url( (int) $lic['order_id'] ) ) . '">#' . esc_html( (string) $lic['order_id'] ) . '</a>' : '–' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: count */ _n( '%s item', '%s items', $total, 'subtleforms-license-server' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo '<span class="pagination-links">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&lsaquo;',
						'next_text' => '&rsaquo;',
					)
				)
			) . '</span>';
		}
		echo '</div></div>';
		echo '</div>';
	}

	/**
	 * Admin URL of an order (HPOS aware).
	 *
	 * @param int $order_id Order ID.
	 */
	private function order_url( int $order_id ): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return $order->get_edit_order_url();
			}
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	// ── License editor ──────────────────────────────────────────────────

	/**
	 * Edit one license.
	 *
	 * @param int $id License ID.
	 */
	private function page_edit( int $id ): void {
		$lic = SFLS_Licenses::find( $id );
		if ( ! $lic ) {
			$this->header( __( 'Edit license', 'subtleforms-license-server' ) );
			echo '<p>' . esc_html__( 'License not found.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sfls' ) ) . '">' . esc_html__( 'Back to licenses', 'subtleforms-license-server' ) . '</a></p></div>';
			return;
		}
		$state  = SFLS_Licenses::state( $lic );
		$acts   = SFLS_Licenses::activations( $id );
		$expiry = $lic['expires_at'] ? wp_date( 'Y-m-d', strtotime( $lic['expires_at'] . ' UTC' ) ) : '';
		$user   = (int) $lic['user_id'] ? get_userdata( (int) $lic['user_id'] ) : false;

		$this->header( __( 'Edit license', 'subtleforms-license-server' ), '<a href="' . esc_url( admin_url( 'admin.php?page=sfls' ) ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'subtleforms-license-server' ) . '</a>' );

		echo '<div class="sfls-edit">';
		echo '<div class="sfls-edit-main">';

		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Details', 'subtleforms-license-server' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_save_' . $id );
		echo '<input type="hidden" name="action" value="sfls_save"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="sfls-plan">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-plan" name="plan">';
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $lic['plan'], false ) . '>' . esc_html( $plan['label'] ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-sites">' . esc_html__( 'Sites allowed', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-sites" type="number" min="0" name="sites_allowed" value="' . esc_attr( (string) (int) $lic['sites_allowed'] ) . '" class="small-text"> <span class="description">' . esc_html__( '0 for unlimited. Local and staging sites are always free.', 'subtleforms-license-server' ) . '</span></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-expires">' . esc_html__( 'Expires', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-expires" type="date" name="expires_at" value="' . esc_attr( $expiry ) . '"> <span class="description">' . esc_html__( 'Leave empty for a lifetime license.', 'subtleforms-license-server' ) . '</span></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-status">' . esc_html__( 'Status', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-status" name="status">';
		echo '<option value="active"' . selected( 'active', $lic['status'], false ) . '>' . esc_html__( 'Active', 'subtleforms-license-server' ) . '</option>';
		echo '<option value="suspended"' . selected( 'active' !== $lic['status'], true, false ) . '>' . esc_html__( 'Suspended', 'subtleforms-license-server' ) . '</option>';
		echo '</select> ' . wp_kses_post( $this->pill( $state['status'] ) ) . '</td></tr>';

		echo '<tr><th scope="row"><label for="sfls-email">' . esc_html__( 'Customer email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-email" type="email" name="customer_email" value="' . esc_attr( $lic['customer_email'] ) . '" class="regular-text">';
		if ( $user ) {
			/* translators: %s: user display name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Linked account: %s', 'subtleforms-license-server' ), $user->display_name ) ) . ' <a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'View user', 'subtleforms-license-server' ) . '</a></p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sfls-notes">' . esc_html__( 'Notes', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-notes" name="notes" rows="4" class="large-text">' . esc_textarea( (string) $lic['notes'] ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save license', 'subtleforms-license-server' ) );
		echo '</form></div>';

		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Activations', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $acts ) {
			echo '<p class="description">' . esc_html__( 'Not active on any site yet.', 'subtleforms-license-server' ) . '</p>';
		} else {
			echo '<table class="widefat striped sfls-acts"><thead><tr><th>' . esc_html__( 'Site', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Pro version', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Activated', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Last seen', 'subtleforms-license-server' ) . '</th><th></th></tr></thead><tbody>';
			$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			foreach ( $acts as $act ) {
				echo '<tr><td><strong>' . esc_html( $act['domain'] ) . '</strong>' . ( $act['is_local'] ? ' <span class="sfls-admin-pill sfls-admin-pill--neutral">' . esc_html__( 'staging', 'subtleforms-license-server' ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( $act['plugin_version'] ? $act['plugin_version'] : '–' ) . '</td>';
				echo '<td>' . esc_html( wp_date( $fmt, strtotime( $act['activated_at'] . ' UTC' ) ) ) . '</td>';
				echo '<td>' . esc_html( wp_date( $fmt, strtotime( $act['last_seen_at'] . ' UTC' ) ) ) . '</td>';
				echo '<td class="sfls-right"><a class="button button-small" href="' . esc_url( $this->action_url( 'deactivate', $id, array( 'domain' => rawurlencode( $act['domain'] ) ) ) ) . '">' . esc_html__( 'Deactivate', 'subtleforms-license-server' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><a class="button-link" href="' . esc_url( $this->action_url( 'reset', $id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove every activation from this license?', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Deactivate all sites', 'subtleforms-license-server' ) . '</a></p>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="sfls-edit-side">';
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'License key', 'subtleforms-license-server' ) . '</h2><p><code class="sfls-mono sfls-keybox">' . esc_html( $lic['license_key'] ) . '</code></p>';
		echo '<p><a class="button" href="' . esc_url( $this->action_url( 'regenerate', $id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Replace this key with a new one? Sites using the old key stop validating until the new key is entered.', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Regenerate key', 'subtleforms-license-server' ) . '</a></p></div>';

		echo '<div class="sfls-panel"><h2>' . esc_html__( 'History', 'subtleforms-license-server' ) . '</h2><ul class="sfls-meta-list">';
		$fmt = get_option( 'date_format' );
		echo '<li><span>' . esc_html__( 'Created', 'subtleforms-license-server' ) . '</span><span>' . esc_html( wp_date( $fmt, strtotime( $lic['created_at'] . ' UTC' ) ) ) . '</span></li>';
		echo '<li><span>' . esc_html__( 'Updated', 'subtleforms-license-server' ) . '</span><span>' . esc_html( wp_date( $fmt, strtotime( $lic['updated_at'] . ' UTC' ) ) ) . '</span></li>';
		if ( $lic['order_id'] ) {
			echo '<li><span>' . esc_html__( 'Order', 'subtleforms-license-server' ) . '</span><span><a href="' . esc_url( $this->order_url( (int) $lic['order_id'] ) ) . '">#' . esc_html( (string) $lic['order_id'] ) . '</a></span></li>';
		}
		echo '</ul></div>';

		echo '<div class="sfls-panel sfls-panel--danger"><h2>' . esc_html__( 'Delete', 'subtleforms-license-server' ) . '</h2><p class="description">' . esc_html__( 'Removes the key and all its activations. This cannot be undone.', 'subtleforms-license-server' ) . '</p>';
		echo '<p><a class="button sfls-button-danger" href="' . esc_url( $this->action_url( 'delete', $id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this license permanently?', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Delete license', 'subtleforms-license-server' ) . '</a></p></div>';
		echo '</div>';

		echo '</div></div>';
	}

	// ── Issue ───────────────────────────────────────────────────────────

	/**
	 * Issue license form.
	 */
	public function page_new(): void {
		$this->guard();
		$this->header( __( 'Issue license', 'subtleforms-license-server' ) );
		echo '<p>' . esc_html__( 'For complimentary, partner, support or test keys. Paid keys are issued automatically when a WooCommerce order completes.', 'subtleforms-license-server' ) . '</p>';
		echo '<div class="sfls-panel"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_create' );
		echo '<input type="hidden" name="action" value="sfls_create"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="sfls-email">' . esc_html__( 'Customer email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-email" type="email" name="email" class="regular-text"><p class="description">' . esc_html__( 'If an account uses this email, the key appears in its My Account › Licenses.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th><label for="sfls-plan">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-plan" name="plan">';
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, 'pro', false ) . '>' . esc_html( $plan['label'] . ' (' . SFLS_Plans::sites_label( (int) $plan['sites'] ) . ')' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="sfls-sites">' . esc_html__( 'Sites', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-sites" type="number" min="0" name="sites" class="small-text" placeholder="' . esc_attr__( 'plan', 'subtleforms-license-server' ) . '"> <span class="description">' . esc_html__( 'Empty for the plan default, 0 for unlimited.', 'subtleforms-license-server' ) . '</span></td></tr>';
		echo '<tr><th><label for="sfls-months">' . esc_html__( 'Duration (months)', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-months" type="number" min="0" name="months" value="12" class="small-text"> <span class="description">' . esc_html__( '0 for lifetime.', 'subtleforms-license-server' ) . '</span></td></tr>';
		echo '<tr><th><label for="sfls-count">' . esc_html__( 'How many', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-count" type="number" min="1" max="50" name="count" value="1" class="small-text"></td></tr>';
		echo '<tr><th><label for="sfls-notes">' . esc_html__( 'Notes', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-notes" name="notes" rows="3" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Issue license', 'subtleforms-license-server' ) );
		echo '</form></div></div>';
	}

	// ── Products wizard ─────────────────────────────────────────────────

	/**
	 * Licenses › Products.
	 */
	public function page_products(): void {
		$this->guard();
		$this->header( __( 'Products', 'subtleforms-license-server' ) );

		if ( ! SFLS_Products::woo_active() ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'WooCommerce is required to sell licenses.', 'subtleforms-license-server' ) . '</strong> ' . esc_html__( 'Install and activate WooCommerce, then come back here to create the products in one step.', 'subtleforms-license-server' ) . '</p></div>';
			if ( current_user_can( 'install_plugins' ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">' . esc_html__( 'Install WooCommerce', 'subtleforms-license-server' ) . '</a></p>';
			}
			echo '</div>';
			return;
		}

		$values   = SFLS_Products::wizard_values();
		$symbol   = get_woocommerce_currency_symbol();
		$currency = get_woocommerce_currency();

		echo '<p class="sfls-lead">' . esc_html__( 'Set your prices and save. The license products are created for you: virtual, sold one at a time, and wired to issue keys. Saving again updates the same products.', 'subtleforms-license-server' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel sfls-wizard">';
		wp_nonce_field( 'sfls_products' );
		echo '<input type="hidden" name="action" value="sfls_products">';
		echo '<table class="widefat sfls-wizard-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Sites', 'subtleforms-license-server' ) . '</th>';
		/* translators: %s: currency code */
		echo '<th scope="col">' . esc_html( sprintf( __( 'Yearly price (%s)', 'subtleforms-license-server' ), $currency ) ) . '</th>';
		/* translators: %s: currency code */
		echo '<th scope="col">' . esc_html( sprintf( __( 'Renewal price (%s)', 'subtleforms-license-server' ), $currency ) ) . '</th>';
		/* translators: %s: currency code */
		echo '<th scope="col">' . esc_html( sprintf( __( 'Lifetime price (%s)', 'subtleforms-license-server' ), $currency ) ) . ' <span class="sfls-optional">' . esc_html__( 'optional', 'subtleforms-license-server' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		$labels = SFLS_Plans::feature_labels();
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			$v     = $values[ $slug ];
			$feats = array_map( static fn( $f ) => $labels[ $f ] ?? $f, $plan['features'] );
			echo '<tr><th scope="row"><strong>' . esc_html( $plan['label'] ) . '</strong><span class="sfls-plan-feats">' . esc_html( implode( ', ', $feats ) ) . '</span></th>';
			echo '<td data-colname="' . esc_attr__( 'Sites', 'subtleforms-license-server' ) . '"><input type="number" min="0" class="small-text" name="plans[' . esc_attr( $slug ) . '][sites]" value="' . esc_attr( $v['sites'] ) . '" aria-label="' . esc_attr( $plan['label'] . ' ' . __( 'sites', 'subtleforms-license-server' ) ) . '"><span class="description">' . esc_html__( '0 = unlimited', 'subtleforms-license-server' ) . '</span></td>';
			foreach ( array(
				'yearly'   => __( 'Yearly price', 'subtleforms-license-server' ),
				'renewal'  => __( 'Renewal price', 'subtleforms-license-server' ),
				'lifetime' => __( 'Lifetime price', 'subtleforms-license-server' ),
			) as $type => $label ) {
				echo '<td data-colname="' . esc_attr( $label ) . '"><span class="sfls-money"><span class="sfls-money-symbol">' . esc_html( html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) ) . '</span><input type="text" inputmode="decimal" name="plans[' . esc_attr( $slug ) . '][' . esc_attr( $type ) . ']" value="' . esc_attr( $v[ $type ] ) . '" placeholder="' . esc_attr( 'lifetime' === $type ? __( 'not sold', 'subtleforms-license-server' ) : '0.00' ) . '" aria-label="' . esc_attr( $plan['label'] . ' ' . $label ) . '"></span></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Renewal products are hidden from the shop; customers reach them from the Renew button in their account. Clearing a price moves that product to draft so it is no longer sold.', 'subtleforms-license-server' ) . '</p>';
		submit_button( __( 'Save products', 'subtleforms-license-server' ) );
		echo '</form>';

		$products = SFLS_Products::all();
		echo '<h2>' . esc_html__( 'License products', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $products ) {
			echo '<p class="description">' . esc_html__( 'None yet. Fill in the prices above and save.', 'subtleforms-license-server' ) . '</p>';
		} else {
			$types = array(
				'yearly'   => __( 'Yearly', 'subtleforms-license-server' ),
				'lifetime' => __( 'Lifetime', 'subtleforms-license-server' ),
				'renewal'  => __( 'Renewal', 'subtleforms-license-server' ),
			);
			echo '<table class="widefat striped sfls-table"><thead><tr><th>' . esc_html__( 'Product', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Type', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Sites', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Price', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Status', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
			foreach ( $products as $row ) {
				$prod = wc_get_product( $row['id'] );
				if ( ! $prod ) {
					continue;
				}
				echo '<tr><td><a class="row-title" href="' . esc_url( (string) get_edit_post_link( $row['id'] ) ) . '">' . esc_html( $prod->get_name() ) . '</a></td><td>' . esc_html( SFLS_Plans::label( $row['plan'] ) ) . '</td><td>' . esc_html( $types[ $row['type'] ] ) . '</td><td>' . esc_html( SFLS_Plans::sites_label( (int) $row['sites'] ) ) . '</td><td>' . wp_kses_post( $prod->get_price_html() ? $prod->get_price_html() : '–' ) . '</td><td>' . esc_html( (string) get_post_status_object( $row['status'] )->label ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<div class="sfls-panel sfls-help"><h2>' . esc_html__( 'Show prices on your site', 'subtleforms-license-server' ) . '</h2><ul>';
		echo '<li><code>[sfls_pricing]</code> ' . esc_html__( 'Pricing cards with Buy buttons that go straight to checkout. Add period="lifetime" to open on lifetime prices.', 'subtleforms-license-server' ) . '</li>';
		echo '<li><code>[sfls_changelog]</code> ' . esc_html__( 'Every Pro release with its changes.', 'subtleforms-license-server' ) . '</li>';
		echo '<li><code>[sfls_latest_version]</code> ' . esc_html__( 'The current Pro version number.', 'subtleforms-license-server' ) . '</li>';
		echo '</ul></div>';
		echo '</div>';
	}

	// ── Releases ────────────────────────────────────────────────────────

	/**
	 * Pro releases.
	 */
	public function page_release(): void {
		$this->guard();
		$rel = SFLS_Releases::current();
		$this->header( __( 'Pro releases', 'subtleforms-license-server' ) );
		echo '<p class="sfls-lead">' . esc_html__( 'Upload a new SubtleForms Pro zip and every site with an active license is offered the update in Dashboard › Updates. Customers can also download it from My Account › Pro downloads.', 'subtleforms-license-server' ) . '</p>';
		if ( $rel['version'] ) {
			echo '<p><strong>' . esc_html( sprintf( /* translators: %s: version */ __( 'Current release: %s', 'subtleforms-license-server' ), $rel['version'] ) ) . '</strong> ';
			if ( SFLS_Releases::has_package() ) {
				echo esc_html( size_format( (int) filesize( SFLS_Releases::package_path() ) ) );
			} else {
				echo '<span class="sfls-danger-text">' . esc_html__( 'package missing', 'subtleforms-license-server' ) . '</span>';
			}
			echo '</p>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_release' );
		echo '<input type="hidden" name="action" value="sfls_release"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="sfls-zip">' . esc_html__( 'Plugin zip', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-zip" type="file" name="package" accept=".zip,application/zip"> <p class="description">' . esc_html__( 'Leave empty to only update the details below.', 'subtleforms-license-server' ) . '</p></td></tr>';
		foreach ( array(
			'version'      => __( 'Version', 'subtleforms-license-server' ),
			'requires'     => __( 'Requires WordPress', 'subtleforms-license-server' ),
			'tested'       => __( 'Tested up to', 'subtleforms-license-server' ),
			'requires_php' => __( 'Requires PHP', 'subtleforms-license-server' ),
		) as $field => $label ) {
			echo '<tr><th><label for="sfls-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td><input id="sfls-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $rel[ $field ] ) . '" class="regular-text"></td></tr>';
		}
		echo '<tr><th><label for="sfls-changelog">' . esc_html__( 'Changelog', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-changelog" name="changelog" rows="8" class="large-text">' . esc_textarea( $rel['changelog'] ) . '</textarea><p class="description">' . esc_html__( 'One change per line, starting with "* ", shows as a list.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Publish release', 'subtleforms-license-server' ) );
		echo '</form>';

		$history = SFLS_Releases::history();
		if ( $history ) {
			echo '<h2>' . esc_html__( 'Earlier releases', 'subtleforms-license-server' ) . '</h2><table class="widefat striped sfls-table"><thead><tr><th>' . esc_html__( 'Version', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Released', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Package', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $old ) {
				echo '<tr><td>' . esc_html( $old['version'] ) . '</td><td>' . esc_html( SFLS_Releases::date_label( $old ) ) . '</td><td>' . esc_html( SFLS_Releases::has_package( $old ) ? size_format( (int) filesize( SFLS_Releases::package_path( $old ) ) ) : __( 'missing', 'subtleforms-license-server' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	// ── Settings ────────────────────────────────────────────────────────

	/**
	 * Licenses › Settings.
	 */
	public function page_settings(): void {
		$this->guard();
		$s = SFLS_Settings::all();
		$this->header( __( 'License settings', 'subtleforms-license-server' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_settings' );
		echo '<input type="hidden" name="action" value="sfls_settings"><table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="sfls-pricing-url">' . esc_html__( 'Pricing page URL', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-pricing-url" type="url" name="pricing_url" value="' . esc_attr( $s['pricing_url'] ) . '" class="regular-text" placeholder="' . esc_attr( home_url( '/pricing/' ) ) . '"><p class="description">' . esc_html__( 'Where "See plans" links point. Leave empty for /pricing/.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-days">' . esc_html__( 'Renewal reminders', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-days" type="text" name="reminder_days" value="' . esc_attr( $s['reminder_days'] ) . '" class="regular-text" placeholder="30,7"><p class="description">' . esc_html__( 'Days before expiry to email the customer, separated by commas. Leave empty to turn reminders off.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'After expiry', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="lapsed_reminder" value="1"' . checked( (int) $s['lapsed_reminder'], 1, false ) . '> ' . esc_html__( 'Send one more reminder the day after a license expires', 'subtleforms-license-server' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-support">' . esc_html__( 'Support email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-support" type="email" name="support_email" value="' . esc_attr( $s['support_email'] ) . '" class="regular-text" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Shown to customers and used as Reply-To on reminder emails.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="delete_data" value="1"' . checked( (bool) get_option( 'sfls_delete_data_on_uninstall' ), true, false ) . '> ' . esc_html__( 'Delete all licenses, activations and release files when the plugin is deleted', 'subtleforms-license-server' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'subtleforms-license-server' ) );
		echo '</form>';

		$next = wp_next_scheduled( SFLS_Reminders::HOOK );
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Reminder schedule', 'subtleforms-license-server' ) . '</h2><p>';
		echo esc_html(
			$next
				/* translators: %s: date and time */
				? sprintf( __( 'Reminders are checked daily. Next check: %s.', 'subtleforms-license-server' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
				: __( 'The daily check is not scheduled. Deactivate and activate the plugin to schedule it.', 'subtleforms-license-server' )
		);
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_run_reminders' );
		echo '<input type="hidden" name="action" value="sfls_run_reminders">';
		submit_button( __( 'Send due reminders now', 'subtleforms-license-server' ), 'secondary', 'submit', false );
		echo '</form></div>';
		echo '</div>';
	}

	// ── Handlers ────────────────────────────────────────────────────────

	/**
	 * Issue keys.
	 */
	public function handle_create(): void {
		$this->guard();
		check_admin_referer( 'sfls_create' );
		$count = min( 50, max( 1, absint( $_POST['count'] ?? 1 ) ) );
		$sites = isset( $_POST['sites'] ) && '' !== $_POST['sites'] ? absint( $_POST['sites'] ) : null;
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$plan  = sanitize_key( wp_unslash( $_POST['plan'] ?? 'pro' ) );
		$plan  = SFLS_Plans::get( $plan ) ? $plan : 'pro';
		$keys  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$args = array(
				'plan'           => $plan,
				'customer_email' => $email,
				'months'         => absint( $_POST['months'] ?? 12 ),
				'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			);
			if ( null !== $sites ) {
				$args['sites_allowed'] = $sites;
			}
			$keys[] = SFLS_Licenses::create( $args )['license_key'];
		}
		/* translators: %s: keys */
		$this->flash( sprintf( __( 'Issued: %s', 'subtleforms-license-server' ), implode( ', ', $keys ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls' ) );
		exit;
	}

	/**
	 * Row and editor actions.
	 */
	public function handle_action(): void {
		$this->guard();
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'sfls_action_' . $id );
		$do   = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
		$back = wp_get_referer();
		$back = $back ? $back : admin_url( 'admin.php?page=sfls' );
		$msg  = __( 'License updated.', 'subtleforms-license-server' );

		if ( ! SFLS_Licenses::find( $id ) ) {
			$this->flash( __( 'License not found.', 'subtleforms-license-server' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=sfls' ) );
			exit;
		}

		switch ( $do ) {
			case 'extend':
				SFLS_Licenses::extend( $id, 12 );
				$msg = __( 'License extended by one year.', 'subtleforms-license-server' );
				break;
			case 'suspend':
				SFLS_Licenses::update( $id, array( 'status' => 'suspended' ) );
				$msg = __( 'License suspended.', 'subtleforms-license-server' );
				break;
			case 'resume':
				SFLS_Licenses::update( $id, array( 'status' => 'active' ) );
				$msg = __( 'License resumed.', 'subtleforms-license-server' );
				break;
			case 'reset':
				SFLS_Licenses::reset_activations( $id );
				$msg = __( 'All sites deactivated.', 'subtleforms-license-server' );
				break;
			case 'deactivate':
				$domain = SFLS_Licenses::normalize_domain( sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) ) );
				SFLS_Licenses::remove_activation( $id, $domain );
				/* translators: %s: domain */
				$msg = sprintf( __( '%s deactivated.', 'subtleforms-license-server' ), $domain );
				break;
			case 'regenerate':
				$key = SFLS_Licenses::regenerate_key( $id );
				/* translators: %s: key */
				$msg = sprintf( __( 'New key: %s', 'subtleforms-license-server' ), $key );
				break;
			case 'delete':
				SFLS_Licenses::delete( $id );
				$msg  = __( 'License deleted.', 'subtleforms-license-server' );
				$back = admin_url( 'admin.php?page=sfls' );
				break;
		}
		$this->flash( $msg );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Save the license editor.
	 */
	public function handle_save(): void {
		$this->guard();
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'sfls_save_' . $id );
		$lic = SFLS_Licenses::find( $id );
		if ( ! $lic ) {
			wp_die( esc_html__( 'License not found.', 'subtleforms-license-server' ) );
		}

		$plan   = sanitize_key( wp_unslash( $_POST['plan'] ?? $lic['plan'] ) );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );
		$email  = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
		$date   = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
		$data   = array(
			'plan'           => SFLS_Plans::get( $plan ) ? $plan : $lic['plan'],
			'sites_allowed'  => absint( $_POST['sites_allowed'] ?? $lic['sites_allowed'] ),
			'status'         => 'suspended' === $status ? ( 'refunded' === $lic['status'] ? 'refunded' : 'suspended' ) : 'active',
			'customer_email' => $email,
			'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		);

		if ( '' === $date ) {
			$data['expires_at'] = null;
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$data['expires_at'] = get_gmt_from_date( $date . ' 23:59:59' );
		} else {
			$this->flash( __( 'The expiry date was not understood, so it was left unchanged.', 'subtleforms-license-server' ), 'warning' );
		}

		if ( 0 !== strcasecmp( $email, (string) $lic['customer_email'] ) ) {
			$user            = $email ? get_user_by( 'email', $email ) : false;
			$data['user_id'] = $user ? (int) $user->ID : 0;
		}

		SFLS_Licenses::update( $id, $data );
		if ( ! get_transient( 'sfls_notice_' . get_current_user_id() ) ) {
			$this->flash( __( 'License saved.', 'subtleforms-license-server' ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'sfls',
					'edit' => $id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save the products wizard.
	 */
	public function handle_products(): void {
		$this->guard();
		check_admin_referer( 'sfls_products' );
		if ( ! SFLS_Products::woo_active() ) {
			wp_die( esc_html__( 'WooCommerce is required.', 'subtleforms-license-server' ) );
		}
		$raw  = isset( $_POST['plans'] ) && is_array( $_POST['plans'] ) ? wp_unslash( $_POST['plans'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$rows = array();
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			$in            = isset( $raw[ $plan ] ) && is_array( $raw[ $plan ] ) ? $raw[ $plan ] : array();
			$rows[ $plan ] = array();
			foreach ( array( 'sites', 'yearly', 'renewal', 'lifetime' ) as $field ) {
				$rows[ $plan ][ $field ] = isset( $in[ $field ] ) ? sanitize_text_field( (string) $in[ $field ] ) : '';
			}
		}
		$result = SFLS_Products::save_wizard( $rows );
		$this->flash(
			sprintf(
				/* translators: 1: created count, 2: updated count */
				__( 'Products saved: %1$d created, %2$d updated.', 'subtleforms-license-server' ),
				$result['created'],
				$result['updated']
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-products' ) );
		exit;
	}

	/**
	 * Save settings.
	 */
	public function handle_settings(): void {
		$this->guard();
		check_admin_referer( 'sfls_settings' );
		SFLS_Settings::save(
			array(
				'pricing_url'     => isset( $_POST['pricing_url'] ) ? sanitize_url( wp_unslash( $_POST['pricing_url'] ) ) : '',
				'reminder_days'   => isset( $_POST['reminder_days'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_days'] ) ) : '',
				'lapsed_reminder' => ! empty( $_POST['lapsed_reminder'] ),
				'support_email'   => isset( $_POST['support_email'] ) ? sanitize_email( wp_unslash( $_POST['support_email'] ) ) : '',
			)
		);
		update_option( 'sfls_delete_data_on_uninstall', ! empty( $_POST['delete_data'] ), false );
		$this->flash( __( 'Settings saved.', 'subtleforms-license-server' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings' ) );
		exit;
	}

	/**
	 * Run reminders now.
	 */
	public function handle_run_reminders(): void {
		$this->guard();
		check_admin_referer( 'sfls_run_reminders' );
		$sent = SFLS_Reminders::run();
		/* translators: %d: number of emails */
		$this->flash( sprintf( _n( '%d reminder sent.', '%d reminders sent.', $sent, 'subtleforms-license-server' ), $sent ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings' ) );
		exit;
	}

	/**
	 * Publish a release.
	 */
	public function handle_release(): void {
		$this->guard();
		check_admin_referer( 'sfls_release' );

		$data = array(
			'version'      => sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) ),
			'requires'     => sanitize_text_field( wp_unslash( $_POST['requires'] ?? '' ) ),
			'tested'       => sanitize_text_field( wp_unslash( $_POST['tested'] ?? '' ) ),
			'requires_php' => sanitize_text_field( wp_unslash( $_POST['requires_php'] ?? '' ) ),
			'changelog'    => sanitize_textarea_field( wp_unslash( $_POST['changelog'] ?? '' ) ),
			'updated'      => gmdate( 'Y-m-d' ),
		);

		if ( ! empty( $_FILES['package']['tmp_name'] ) && ! empty( $_FILES['package']['name'] ) ) {
			$file = $this->store_upload( $data['version'] );
			if ( is_wp_error( $file ) ) {
				$this->flash( $file->get_error_message(), 'error' );
				wp_safe_redirect( admin_url( 'admin.php?page=sfls-release' ) );
				exit;
			}
			$data['file'] = $file;
		}

		SFLS_Releases::save( $data );
		$this->flash( __( 'Release saved. Licensed sites will see it within 12 hours.', 'subtleforms-license-server' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-release' ) );
		exit;
	}

	/**
	 * Move the uploaded zip into the private folder with wp_handle_upload().
	 *
	 * @param string $version Release version (used in the file name).
	 * @return string|WP_Error Stored file name.
	 */
	private function store_upload( string $version ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$dir = SFLS_Releases::dir();
		wp_mkdir_p( $dir );

		$name = 'subtleforms-pro-' . sanitize_file_name( '' !== $version ? $version : gmdate( 'YmdHis' ) ) . '.zip';
		$path = trailingslashit( $dir ) . $name;
		if ( file_exists( $path ) ) {
			wp_delete_file( $path ); // Re-uploading the same version replaces it.
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by the caller; wp_handle_upload() validates the file.
		$upload         = isset( $_FILES['package'] ) ? (array) $_FILES['package'] : array();
		$upload['name'] = $name;

		$target = static function ( array $dirs ) use ( $dir ): array {
			$dirs['path']   = $dir;
			$dirs['subdir'] = '';
			$dirs['url']    = trailingslashit( $dirs['baseurl'] ) . 'sfls-private';
			return $dirs;
		};
		add_filter( 'upload_dir', $target );
		$result = wp_handle_upload(
			$upload,
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);
		remove_filter( 'upload_dir', $target );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'upload', (string) $result['error'] );
		}
		return basename( (string) $result['file'] );
	}
}
