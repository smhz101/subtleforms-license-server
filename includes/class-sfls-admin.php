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
		add_action( 'admin_post_sfls_products', array( $this, 'handle_products' ) );
		add_action( 'admin_post_sfls_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_sfls_run_reminders', array( $this, 'handle_run_reminders' ) );
		add_action( 'admin_post_sfls_test_reminder', array( $this, 'handle_test_reminder' ) );
		add_action( 'admin_post_sfls_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_sfls_paddle_settings', array( $this, 'handle_paddle_settings' ) );
		add_action( 'admin_post_sfls_paddle_test', array( $this, 'handle_paddle_test' ) );
		add_action( 'admin_post_sfls_paddle_sync', array( $this, 'handle_paddle_sync' ) );
		add_action( 'admin_post_sfls_paddle_map', array( $this, 'handle_paddle_map' ) );
		add_action( 'admin_post_sfls_paddle_create', array( $this, 'handle_paddle_create' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SFLS_FILE ), array( $this, 'action_links' ) );
		$this->releases()->register();
	}

	/**
	 * Release management screens (1.6.0).
	 */
	public function releases(): SFLS_Admin_Releases {
		static $releases = null;
		if ( null === $releases ) {
			$releases = new SFLS_Admin_Releases( $this );
		}
		return $releases;
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
	public function guard(): void {
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
	public function flash( string $message, string $type = 'success' ): void {
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
	 * Small chip for Paddle billing: Trial, Past due, Cancels, Paddle.
	 *
	 * @param array $lic License.
	 */
	private function billing_chip( array $lic ): string {
		if ( ! SFLS_Licenses::is_paddle( $lic ) ) {
			return '';
		}
		if ( ! empty( $lic['trial'] ) && 'trialing' === $lic['billing_status'] ) {
			$chip = array( 'info', __( 'Trial', 'subtleforms-license-server' ) );
		} elseif ( 'past_due' === $lic['billing_status'] ) {
			$chip = array( 'warn', __( 'Past due', 'subtleforms-license-server' ) );
		} elseif ( ! empty( $lic['cancels_at'] ) ) {
			$chip = array( 'warn', __( 'Cancels', 'subtleforms-license-server' ) );
		} elseif ( 'canceled' === $lic['billing_status'] ) {
			$chip = array( 'neutral', __( 'Canceled', 'subtleforms-license-server' ) );
		} else {
			$chip = array( 'neutral', __( 'Paddle', 'subtleforms-license-server' ) );
		}
		return ' <span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $chip[0] ) . ' sfls-admin-pill--chip">' . esc_html( $chip[1] ) . '</span>';
	}

	/**
	 * Page header.
	 *
	 * @param string $title  Title.
	 * @param string $action Optional page-title-action HTML (escaped).
	 */
	public function header( string $title, string $action = '' ): void {
		echo '<div class="wrap sfls-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		echo wp_kses_post( $action );
		echo '<hr class="wp-header-end">';
		$this->notice();
		$this->no_release_notice();
	}

	/**
	 * Warn on every Licenses screen while customers have nothing to download.
	 */
	private function no_release_notice(): void {
		if ( SFLS_Releases::has_package() ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible sfls-no-release"><p><strong>' . esc_html__( 'Customers cannot download Pro yet.', 'subtleforms-license-server' ) . '</strong> ';
		printf(
			/* translators: %s: link to the Pro releases screen */
			esc_html__( 'Upload a release in %s.', 'subtleforms-license-server' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=sfls-release' ) ) . '">' . esc_html__( 'Licenses › Pro releases', 'subtleforms-license-server' ) . '</a>'
		);
		echo '</p></div>';
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
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		[ $search, $filter, $plan ] = self::list_filters();
		$filters                    = self::status_filters();

		[ $rows, $total ] = SFLS_Licenses::query(
			array(
				'search' => $search,
				'status' => $filter,
				'plan'   => $plan,
				'per'    => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

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
					'plan'   => $plan ? $plan : false,
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
		echo '<p class="search-box"><label class="screen-reader-text" for="sfls-search">' . esc_html__( 'Search licenses', 'subtleforms-license-server' ) . '</label><input type="search" id="sfls-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Key, email or domain', 'subtleforms-license-server' ) . '"> <button class="button">' . esc_html__( 'Search', 'subtleforms-license-server' ) . '</button></p>';
		echo '<div class="tablenav top"><div class="alignleft actions"><label class="screen-reader-text" for="sfls-plan-filter">' . esc_html__( 'Filter by plan', 'subtleforms-license-server' ) . '</label><select id="sfls-plan-filter" name="plan"><option value="">' . esc_html__( 'All plans', 'subtleforms-license-server' ) . '</option>';
		foreach ( SFLS_Plans::all() as $slug => $def ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $plan, $slug, false ) . '>' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select> <button class="button" type="submit">' . esc_html__( 'Filter', 'subtleforms-license-server' ) . '</button>';
		if ( $search || $filter || $plan ) {
			echo ' <a class="button-link sfls-clear" href="' . esc_url( admin_url( 'admin.php?page=sfls' ) ) . '">' . esc_html__( 'Clear filters', 'subtleforms-license-server' ) . '</a>';
		}
		echo '</div><div class="alignright actions"><a class="button" href="' . esc_url( $this->export_url( $search, $filter, $plan ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: number of licenses */
				_n( 'Export %s license as CSV', 'Export %s licenses as CSV', $total, 'subtleforms-license-server' ),
				number_format_i18n( $total )
			)
		) . '</a></div><br class="clear"></div></form>';

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
			echo '<tr><td colspan="7">' . esc_html( $search || $filter || $plan ? __( 'No licenses match.', 'subtleforms-license-server' ) : __( 'No licenses yet. Issue one, or sell a product marked as a SubtleForms license.', 'subtleforms-license-server' ) ) . '</td></tr>';
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
			echo '<td data-colname="' . esc_attr( $heads['status'] ) . '">' . wp_kses_post( $this->pill( $state['status'] ) . $this->billing_chip( $lic ) ) . '</td>';
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
	 * Status filters of the list.
	 *
	 * @return array<string,string>
	 */
	private static function status_filters(): array {
		return array(
			''          => __( 'All', 'subtleforms-license-server' ),
			'active'    => __( 'Active', 'subtleforms-license-server' ),
			'expiring'  => __( 'Expiring in 30 days', 'subtleforms-license-server' ),
			'expired'   => __( 'Expired', 'subtleforms-license-server' ),
			'suspended' => __( 'Suspended', 'subtleforms-license-server' ),
		);
	}

	/**
	 * Search, status and plan from the query string.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private static function list_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$plan   = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
		// phpcs:enable
		$status = array_key_exists( $status, self::status_filters() ) ? $status : '';
		$plan   = SFLS_Plans::get( $plan ) ? $plan : '';
		return array( $search, $status, $plan );
	}

	/**
	 * CSV export link for the current filters.
	 *
	 * @param string $search Search.
	 * @param string $status Status filter.
	 * @param string $plan   Plan filter.
	 */
	private function export_url( string $search, string $status, string $plan ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => 'sfls_export',
						's'      => $search,
						'status' => $status,
						'plan'   => $plan,
					),
					'strlen'
				),
				admin_url( 'admin-post.php' )
			),
			'sfls_export'
		);
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
		$this->activity_panel( $id );
		echo '</div>';

		echo '<div class="sfls-edit-side">';
		$this->billing_panel( $lic );
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

	/**
	 * Paddle subscription status for people.
	 *
	 * @param string $status Paddle status.
	 */
	private static function billing_status_label( string $status ): string {
		$labels = array(
			'trialing' => __( 'Free trial', 'subtleforms-license-server' ),
			'active'   => __( 'Active', 'subtleforms-license-server' ),
			'past_due' => __( 'Past due', 'subtleforms-license-server' ),
			'paused'   => __( 'Paused', 'subtleforms-license-server' ),
			'canceled' => __( 'Canceled', 'subtleforms-license-server' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Billing side panel of the license editor.
	 *
	 * @param array $lic License.
	 */
	private function billing_panel( array $lic ): void {
		$source = SFLS_Licenses::billing_source( $lic );
		$labels = array(
			'paddle'      => __( 'Paddle subscription', 'subtleforms-license-server' ),
			'woocommerce' => __( 'WooCommerce order', 'subtleforms-license-server' ),
			'manual'      => __( 'Issued by hand', 'subtleforms-license-server' ),
		);
		$fmt    = get_option( 'date_format' );
		$date   = static fn( $gmt ) => $gmt ? wp_date( $fmt, strtotime( $gmt . ' UTC' ) ) : '';
		$claims = SFLS_Trials::claims_of( (int) $lic['id'] );
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Billing', 'subtleforms-license-server' ) . '</h2><ul class="sfls-meta-list">';
		echo '<li><span>' . esc_html__( 'Source', 'subtleforms-license-server' ) . '</span><span>' . esc_html( $labels[ $source ] ) . '</span></li>';
		if ( 'paddle' === $source ) {
			$rows = array(
				__( 'Status', 'subtleforms-license-server' )     => self::billing_status_label( (string) $lic['billing_status'] ),
				__( 'Trial ends', 'subtleforms-license-server' ) => ! empty( $lic['trial'] ) ? $date( $lic['trial_ends_at'] ) : '',
				__( 'Renews', 'subtleforms-license-server' )     => $date( $lic['renews_at'] ),
				__( 'Cancels', 'subtleforms-license-server' )    => $date( $lic['cancels_at'] ),
				__( 'Amount', 'subtleforms-license-server' )     => '' !== (string) $lic['billing_amount'] && '' !== (string) $lic['billing_currency'] ? SFLS_Paddle::format_amount( (string) $lic['billing_amount'], (string) $lic['billing_currency'] ) : '',
				__( 'Customer', 'subtleforms-license-server' )   => (string) $lic['paddle_customer_id'],
			);
			foreach ( array_filter( $rows, 'strlen' ) as $label => $value ) {
				echo '<li><span>' . esc_html( $label ) . '</span><span>' . esc_html( $value ) . '</span></li>';
			}
			if ( '' !== (string) $lic['paddle_subscription_id'] ) {
				echo '<li><span>' . esc_html__( 'Subscription', 'subtleforms-license-server' ) . '</span><span><a href="' . esc_url( SFLS_Paddle::dashboard_url( (string) $lic['paddle_subscription_id'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $lic['paddle_subscription_id'] ) . '</a></span></li>';
			}
		}
		echo '</ul>';
		if ( $claims ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: list of emails, domains and sites */
					__( 'Used a free trial for: %s.', 'subtleforms-license-server' ),
					implode( ', ', wp_list_pluck( $claims, 'value' ) )
				)
			) . '</p>';
			echo '<p><a class="button-link" href="' . esc_url( $this->action_url( 'release_trial', (int) $lic['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Let this customer, company and site start another free trial?', 'subtleforms-license-server' ) ) . '\');">' . esc_html__( 'Allow another trial', 'subtleforms-license-server' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Activation and deactivation log of a license.
	 *
	 * @param int $id License ID.
	 */
	private function activity_panel( int $id ): void {
		$entries = SFLS_Licenses::log_entries( $id, 50 );
		$events  = array(
			'activate'   => __( 'Activated', 'subtleforms-license-server' ),
			'deactivate' => __( 'Deactivated', 'subtleforms-license-server' ),
		);
		$sources = array(
			'api'     => __( 'Plugin (API)', 'subtleforms-license-server' ),
			'connect' => __( 'Connect account', 'subtleforms-license-server' ),
			'account' => __( 'Customer, My Account', 'subtleforms-license-server' ),
			'admin'   => __( 'Admin', 'subtleforms-license-server' ),
			'cli'     => __( 'WP-CLI', 'subtleforms-license-server' ),
		);
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Activity', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $entries ) {
			echo '<p class="description">' . esc_html__( 'No activations or deactivations recorded yet. Logging started with version 1.3.0.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<table class="widefat striped sfls-acts sfls-log"><thead><tr><th>' . esc_html__( 'Time', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Event', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Site', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'IP address', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'By', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $row ) {
			$tone = 'activate' === $row['event'] ? 'ok' : 'neutral';
			echo '<tr><td>' . esc_html( wp_date( $fmt, strtotime( $row['created_at'] . ' UTC' ) ) ) . '</td>';
			echo '<td><span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tone ) . '">' . esc_html( $events[ $row['event'] ] ?? $row['event'] ) . '</span></td>';
			echo '<td><strong>' . esc_html( $row['domain'] ) . '</strong></td>';
			echo '<td class="sfls-mono">' . esc_html( '' !== $row['ip'] ? $row['ip'] : '–' ) . '</td>';
			echo '<td>' . esc_html( $sources[ $row['source'] ] ?? ( '' !== $row['source'] ? $row['source'] : '–' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
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
		echo '<tr><th>' . esc_html__( 'Email', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="send_email" value="1"> ' . esc_html__( 'Email the key to the customer', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'Sends the key and a link to the license page and Pro download (valid for 30 days, no account needed). Leave it off for test keys.', 'subtleforms-license-server' ) . '</p></td></tr>';
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
		$mapping = SFLS_Products::mapping();
		foreach ( SFLS_Plans::all() as $slug => $plan ) {
			$v = $values[ $slug ];
			echo '<tr><th scope="row"><strong>' . esc_html( $plan['label'] ) . '</strong><span class="sfls-plan-feats">' . esc_html( SFLS_Plans::feature_line( $slug ) ) . '</span></th>';
			echo '<td data-colname="' . esc_attr__( 'Sites', 'subtleforms-license-server' ) . '"><input type="number" min="0" class="small-text" name="plans[' . esc_attr( $slug ) . '][sites]" value="' . esc_attr( $v['sites'] ) . '" aria-label="' . esc_attr( $plan['label'] . ' ' . __( 'sites', 'subtleforms-license-server' ) ) . '"><span class="description">' . esc_html__( '0 = unlimited', 'subtleforms-license-server' ) . '</span></td>';
			foreach ( array(
				'yearly'   => __( 'Yearly price', 'subtleforms-license-server' ),
				'renewal'  => __( 'Renewal price', 'subtleforms-license-server' ),
				'lifetime' => __( 'Lifetime price', 'subtleforms-license-server' ),
			) as $type => $label ) {
				echo '<td data-colname="' . esc_attr( $label ) . '"><span class="sfls-money"><span class="sfls-money-symbol">' . esc_html( html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) ) . '</span><input type="text" inputmode="decimal" name="plans[' . esc_attr( $slug ) . '][' . esc_attr( $type ) . ']" value="' . esc_attr( $v[ $type ] ) . '" placeholder="' . esc_attr( 'lifetime' === $type ? __( 'not sold', 'subtleforms-license-server' ) : '0.00' ) . '" aria-label="' . esc_attr( $plan['label'] . ' ' . $label ) . '"></span>';
				$this->mapping_chip( $mapping[ $slug ][ $type ] ?? array() );
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Renewal products are hidden from the shop; customers reach them from the Renew button in their account. Clearing a price moves that product to draft so it is no longer sold. Under each price you see the WooCommerce product it maps to. Saving again updates those products and never creates a second one; a product marked Missing was deleted and is created again.', 'subtleforms-license-server' ) . '</p>';
		submit_button( __( 'Save products', 'subtleforms-license-server' ) );
		echo '</form>';

		$this->paddle_create_panel( $values );

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
		echo '</ul><p class="description">' . esc_html__( 'In the block editor, search for SubtleForms to add the same pricing, changelog and version as blocks, plus the Account link block for your header.', 'subtleforms-license-server' ) . '</p></div>';
		echo '</div>';
	}

	/**
	 * Products wizard: create the same plans in Paddle.
	 *
	 * @param array $values Wizard values (WooCommerce yearly prices).
	 */
	private function paddle_create_panel( array $values ): void {
		$plans = SFLS_Paddle::plans();
		echo '<div class="sfls-panel sfls-paddle-create"><h2>' . esc_html__( 'Paddle products and prices', 'subtleforms-license-server' ) . '</h2>';
		if ( ! SFLS_Paddle::has_api_key() ) {
			echo '<p class="description">' . esc_html__( 'To sell with Paddle, add your API key in Licenses › Settings › Paddle. This panel can then create a Paddle product per plan with a yearly price and an optional 7 or 14 day free trial.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Creates one Paddle product per plan with a yearly price at the yearly price above, plus a second yearly price with a free trial that requires a payment method. Plans that are already mapped are left alone. Saving the WooCommerce products never changes Paddle.', 'subtleforms-license-server' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sfls_paddle_create' );
		echo '<input type="hidden" name="action" value="sfls_paddle_create">';
		echo '<table class="widefat sfls-wizard-table"><thead><tr><th scope="col">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</th><th scope="col">' . esc_html__( 'Yearly price', 'subtleforms-license-server' ) . '</th><th scope="col">' . esc_html__( 'Free trial', 'subtleforms-license-server' ) . '</th><th scope="col">' . esc_html__( 'In Paddle', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
		foreach ( $plans as $plan => $row ) {
			$price = (string) ( $values[ $plan ]['yearly'] ?? '' );
			echo '<tr><th scope="row"><strong>' . esc_html( SFLS_Plans::label( $plan ) ) . '</strong></th>';
			echo '<td>' . esc_html( '' !== $price ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES, 'UTF-8' ) : __( 'No yearly price', 'subtleforms-license-server' ) ) . '</td>';
			$id = 'sfls-paddle-trial-' . $plan;
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( SFLS_Plans::label( $plan ) . ' ' . __( 'Free trial', 'subtleforms-license-server' ) ) . '</label><select id="' . esc_attr( $id ) . '" name="trial[' . esc_attr( $plan ) . ']"' . disabled( '' !== $row['trial_price_id'], true, false ) . '>';
			foreach ( array(
				0  => __( 'No trial', 'subtleforms-license-server' ),
				7  => __( '7 days', 'subtleforms-license-server' ),
				14 => __( '14 days', 'subtleforms-license-server' ),
			) as $days => $label ) {
				echo '<option value="' . esc_attr( (string) $days ) . '"' . selected( '' !== $row['trial_price_id'] ? $row['trial_days'] : 14, $days, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td><td>';
			if ( '' !== $row['price_id'] ) {
				echo '<span class="sfls-admin-pill sfls-admin-pill--ok">' . esc_html__( 'Mapped', 'subtleforms-license-server' ) . '</span> <code>' . esc_html( $row['price_id'] ) . '</code>';
				if ( '' !== $row['trial_price_id'] ) {
					echo '<br><code>' . esc_html( $row['trial_price_id'] ) . '</code>';
				}
			} else {
				echo '<span class="sfls-admin-pill sfls-admin-pill--neutral">' . esc_html__( 'Not created', 'subtleforms-license-server' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		echo '<p><label for="sfls-paddle-currency">' . esc_html__( 'Currency', 'subtleforms-license-server' ) . '</label> <input id="sfls-paddle-currency" type="text" name="currency" value="' . esc_attr( $currency ) . '" class="small-text sfls-mono" maxlength="3"> <span class="description">' . esc_html__( 'Paddle converts to local currencies at checkout.', 'subtleforms-license-server' ) . '</span></p>';
		submit_button( __( 'Create in Paddle', 'subtleforms-license-server' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * "#3002 · Published" under a wizard price, linking to the product.
	 *
	 * @param array $map Row from SFLS_Products::mapping().
	 */
	private function mapping_chip( array $map ): void {
		$status = $map['status'] ?? 'none';
		$labels = array(
			'publish' => __( 'Published', 'subtleforms-license-server' ),
			'draft'   => __( 'Draft', 'subtleforms-license-server' ),
			'private' => __( 'Private', 'subtleforms-license-server' ),
			'pending' => __( 'Pending', 'subtleforms-license-server' ),
			'missing' => __( 'Missing', 'subtleforms-license-server' ),
			'none'    => __( 'Not created', 'subtleforms-license-server' ),
		);
		$tones  = array(
			'publish' => 'ok',
			'missing' => 'bad',
		);
		echo '<span class="sfls-map">';
		if ( ! empty( $map['id'] ) && 'missing' !== $status ) {
			/* translators: %s: product name */
			echo '<a class="sfls-map-link" href="' . esc_url( (string) get_edit_post_link( (int) $map['id'] ) ) . '" title="' . esc_attr( sprintf( __( 'Edit %s', 'subtleforms-license-server' ), (string) $map['name'] ) ) . '">#' . esc_html( (string) $map['id'] ) . '</a>';
		} elseif ( ! empty( $map['id'] ) ) {
			echo '<span class="sfls-map-link">#' . esc_html( (string) $map['id'] ) . '</span>';
		}
		echo '<span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tones[ $status ] ?? 'neutral' ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
		echo '</span>';
	}

	// ── Releases ────────────────────────────────────────────────────────

	/**
	 * Pro releases (see SFLS_Admin_Releases).
	 */
	public function page_release(): void {
		$this->releases()->page();
	}

	// ── Settings ────────────────────────────────────────────────────────

	/**
	 * Licenses › Settings.
	 */
	public function page_settings(): void {
		$this->guard();
		$s = SFLS_Settings::all();
		$this->header( __( 'License settings', 'subtleforms-license-server' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switch only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tab = in_array( $tab, array( 'paddle', 'releases' ), true ) ? $tab : 'general';
		$this->settings_tabs( $tab );
		if ( 'paddle' === $tab ) {
			$this->page_paddle();
			echo '</div>';
			return;
		}
		if ( 'releases' === $tab ) {
			$this->releases()->settings_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_settings' );
		echo '<input type="hidden" name="action" value="sfls_settings"><table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="sfls-pricing-url">' . esc_html__( 'Pricing page URL', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-pricing-url" type="url" name="pricing_url" value="' . esc_attr( $s['pricing_url'] ) . '" class="regular-text" placeholder="' . esc_attr( home_url( '/pricing/' ) ) . '"><p class="description">' . esc_html__( 'Where "See plans" links point. Leave empty for /pricing/.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-days">' . esc_html__( 'Renewal reminders', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-days" type="text" name="reminder_days" value="' . esc_attr( $s['reminder_days'] ) . '" class="regular-text" placeholder="30,7"><p class="description">' . esc_html__( 'Days before expiry to email the customer, separated by commas. Leave empty to turn reminders off.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'After expiry', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="lapsed_reminder" value="1"' . checked( (int) $s['lapsed_reminder'], 1, false ) . '> ' . esc_html__( 'Send one more reminder the day after a license expires', 'subtleforms-license-server' ) . '</label></td></tr>';

		$support = SFLS_Settings::support_email();
		echo '<tr><th scope="row"><label for="sfls-support">' . esc_html__( 'Support email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-support" type="email" name="support_email" value="' . esc_attr( $s['support_email'] ) . '" class="regular-text" placeholder="' . esc_attr( '' !== SFLS_Settings::default_support_email() ? SFLS_Settings::default_support_email() : (string) get_option( 'admin_email' ) ) . '"><p class="description">' . esc_html__( 'Shown to customers and used as Reply-To on every license email (order emails with keys and reminders). When it is on this site\'s domain it is also the From address.', 'subtleforms-license-server' ) . '</p>';
		if ( '' === (string) $s['support_email'] ) {
			echo '<p class="sfls-field-warning">' . esc_html(
				sprintf(
					/* translators: %s: email address */
					__( 'Not set, so customers see the site admin address %s. Enter an address on your own domain.', 'subtleforms-license-server' ),
					$support
				)
			) . '</p>';
		}
		if ( SFLS_Settings::is_free_mail( $support ) ) {
			echo '<div class="notice notice-warning inline sfls-free-mail"><p>' . esc_html(
				sprintf(
					/* translators: %s: email address */
					__( '%s is a free mail address. Customers see it on receipts, reminders and the connect page, and mail this site sends from it is often marked as spam. Use an address on your own domain instead.', 'subtleforms-license-server' ),
					$support
				)
			) . '</p></div>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sfls-staging">' . esc_html__( 'Staging hosts', 'subtleforms-license-server' ) . '</label></th><td><textarea id="sfls-staging" name="staging_hosts" rows="6" class="large-text code">' . esc_textarea( (string) $s['staging_hosts'] ) . '</textarea><p class="description">' . esc_html__( 'Sites on these hosts do not use a seat. One per line. *.example.com matches every subdomain of example.com, staging.* matches a staging subdomain of any site, and a * inside a name matches any letters, as in *stg.wpengine.com. Production hosts such as *.wpengine.com and *.kinsta.cloud should not be listed. localhost, *.local, *.test, *.example and *.invalid are always free.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'My Account', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="simplify_account" value="1"' . checked( (int) $s['simplify_account'], 1, false ) . '> ' . esc_html__( 'Simplify My Account for license-only stores', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'Hides the Addresses and Downloads tabs for customers whose orders only contain license products. Orders, Licenses, Pro downloads, Payment methods and Account details stay.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Checkout', 'subtleforms-license-server' ) . '</th><td><label><input type="checkbox" name="simple_checkout" value="1"' . checked( (int) $s['simple_checkout'], 1, false ) . '> ' . esc_html__( 'Ask only for name, email and country when the cart holds only licenses', 'subtleforms-license-server' ) . '</label><p class="description">' . esc_html__( 'Street, city, postcode, state, company and phone are hidden. Country stays because tax depends on it.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="sfls-retention">' . esc_html__( 'Keep activity for', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-retention" type="number" min="0" max="3650" step="1" name="retention_days" value="' . esc_attr( (string) (int) $s['retention_days'] ) . '" class="small-text"> ' . esc_html__( 'days', 'subtleforms-license-server' ) . '<p class="description">' . esc_html__( 'The activation log (with IP addresses) and handled Paddle webhook events older than this are deleted every day. 0 keeps them forever.', 'subtleforms-license-server' ) . '</p></td></tr>';

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

		$me = wp_get_current_user();
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Test reminder', 'subtleforms-license-server' ) . '</h2><p class="description">' . esc_html__( 'Sends an example renewal reminder or Paddle billing email so you can check how it looks. No license is changed and no customer is emailed.', 'subtleforms-license-server' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-test-reminder">';
		wp_nonce_field( 'sfls_test_reminder' );
		echo '<input type="hidden" name="action" value="sfls_test_reminder">';
		echo '<label for="sfls-test-to">' . esc_html__( 'Send to', 'subtleforms-license-server' ) . '</label> <input id="sfls-test-to" type="email" name="to" class="regular-text" value="' . esc_attr( $me->user_email ) . '" required> ';
		echo '<label for="sfls-test-stage" class="screen-reader-text">' . esc_html__( 'Reminder', 'subtleforms-license-server' ) . '</label><select id="sfls-test-stage" name="stage">';
		echo '<optgroup label="' . esc_attr__( 'Renewal reminders', 'subtleforms-license-server' ) . '"><option value="before">' . esc_html__( 'Before expiry', 'subtleforms-license-server' ) . '</option><option value="lapsed">' . esc_html__( 'After expiry', 'subtleforms-license-server' ) . '</option></optgroup>';
		echo '<optgroup label="' . esc_attr__( 'Paddle billing emails', 'subtleforms-license-server' ) . '">';
		foreach ( SFLS_Billing_Emails::templates() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</optgroup></select> ';
		submit_button( __( 'Send test reminder', 'subtleforms-license-server' ), 'secondary', 'submit', false );
		echo '</form></div>';
		echo '</div>';
	}

	// ── Paddle ──────────────────────────────────────────────────────────

	/**
	 * General | Paddle tabs on Licenses › Settings.
	 *
	 * @param string $current Current tab.
	 */
	private function settings_tabs( string $current ): void {
		$tabs = array(
			'general'  => __( 'General', 'subtleforms-license-server' ),
			'paddle'   => __( 'Paddle', 'subtleforms-license-server' ),
			'releases' => __( 'Releases', 'subtleforms-license-server' ),
		);
		echo '<nav class="nav-tab-wrapper sfls-tabs" aria-label="' . esc_attr__( 'Settings sections', 'subtleforms-license-server' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url = admin_url( 'admin.php?page=sfls-settings' . ( 'general' === $slug ? '' : '&tab=' . $slug ) );
			echo '<a class="nav-tab' . ( $current === $slug ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $current === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * "Saved, ends in 3f9a" for a stored secret.
	 *
	 * @param string $value Secret.
	 */
	private static function secret_hint( string $value ): string {
		/* translators: %s: last four characters */
		return '' === $value ? '' : sprintf( __( 'Saved, ends in %s. Leave empty to keep it.', 'subtleforms-license-server' ), substr( $value, -4 ) );
	}

	/**
	 * Licenses › Settings › Paddle.
	 */
	private function page_paddle(): void {
		$config  = SFLS_Paddle::config();
		$s       = SFLS_Settings::all();
		$plans   = SFLS_Paddle::plans();
		$prices  = get_transient( 'sfls_paddle_prices' );
		$prices  = is_array( $prices ) ? $prices : null;
		$missing = array();
		if ( '' === $config['api_key'] ) {
			$missing[] = __( 'API key', 'subtleforms-license-server' );
		}
		if ( '' === $config['client_token'] ) {
			$missing[] = __( 'client-side token', 'subtleforms-license-server' );
		}
		if ( '' === $config['webhook_secret'] ) {
			$missing[] = __( 'webhook secret', 'subtleforms-license-server' );
		}
		if ( ! array_filter( wp_list_pluck( $plans, 'price_id' ) ) ) {
			$missing[] = __( 'a price for at least one plan', 'subtleforms-license-server' );
		}

		$mode    = SFLS_Settings::sell_with();
		$gateway = (array) get_option( 'woocommerce_sfls_paddle_settings', array() );
		$gw_link = ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sfls_paddle' ) ) . '">' . esc_html__( 'Pay with Paddle gateway settings', 'subtleforms-license-server' ) . '</a>';
		if ( in_array( $mode, array( 'paddle', 'woo_paddle' ), true ) && $missing ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Paddle is selected but not ready, so licenses are still sold through WooCommerce with your other payment methods.', 'subtleforms-license-server' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: %s: list of missing settings */
					__( 'Missing: %s.', 'subtleforms-license-server' ),
					wp_sprintf_l( '%l', $missing )
				)
			) . '</p></div>';
		} elseif ( SFLS_Paddle::woo_selling() && 'no' === ( $gateway['enabled'] ?? 'yes' ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'The Pay with Paddle payment method is turned off in WooCommerce, so license carts cannot be paid with Paddle.', 'subtleforms-license-server' ) . '</strong>' . wp_kses_post( $gw_link ) . '</p></div>';
		} elseif ( SFLS_Paddle::woo_selling() ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				'live' === $config['env']
					? __( 'Selling through the WooCommerce checkout with Paddle payments (live). Pricing buttons go to the cart, and license-only carts are paid with Paddle.', 'subtleforms-license-server' )
					: __( 'Selling through the WooCommerce checkout with Paddle payments in the sandbox. No real money is taken. Switch the environment to Live when your tests pass.', 'subtleforms-license-server' )
			) . wp_kses_post( $gw_link ) . '</p></div>';
		} elseif ( SFLS_Paddle::selling() ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				'live' === $config['env']
					? __( 'Selling with the legacy Paddle overlay (live). Purchases do not create WooCommerce orders.', 'subtleforms-license-server' )
					: __( 'Selling with the legacy Paddle overlay in the sandbox. No real money is taken.', 'subtleforms-license-server' )
			) . '</p></div>';
		}

		// Selling and connection.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_paddle_settings' );
		echo '<input type="hidden" name="action" value="sfls_paddle_settings"><h2>' . esc_html__( 'Selling', 'subtleforms-license-server' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Sell licenses with', 'subtleforms-license-server' ) . '</th><td><fieldset class="sfls-choice-list"><legend class="screen-reader-text">' . esc_html__( 'Sell licenses with', 'subtleforms-license-server' ) . '</legend>';
		$modes = array(
			'woo_paddle'  => array(
				__( 'WooCommerce checkout with Paddle payments (recommended)', 'subtleforms-license-server' ),
				__( 'Customers use the WooCommerce cart and checkout, and pay with Pay with Paddle: card, PayPal, Apple Pay or Google Pay. Every purchase, renewal and refund is a WooCommerce order. Paddle is the merchant of record, adds sales tax or VAT, and renews yearly subscriptions automatically, with optional 7 or 14 day free trials.', 'subtleforms-license-server' ),
			),
			'woocommerce' => array(
				__( 'WooCommerce with other gateways', 'subtleforms-license-server' ),
				__( 'The license products from Licenses › Products, paid with your other WooCommerce payment methods once per year, with manual renewals and WooCommerce tax.', 'subtleforms-license-server' ),
			),
			'paddle'      => array(
				__( 'Paddle overlay (legacy)', 'subtleforms-license-server' ),
				__( 'The 1.4 flow: pricing buttons open the Paddle overlay directly and the license is issued without a WooCommerce order. Kept for existing setups.', 'subtleforms-license-server' ),
			),
		);
		foreach ( $modes as $value => [ $label, $help ] ) {
			echo '<label><input type="radio" name="sell_with" value="' . esc_attr( $value ) . '"' . checked( $mode, $value, false ) . '> <strong>' . esc_html( $label ) . '</strong></label><p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-trial-notice">' . esc_html__( 'Trial ending email', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-trial-notice" type="number" min="0" max="30" class="small-text" name="trial_notice" value="' . esc_attr( (string) (int) $s['trial_notice'] ) . '"> ' . esc_html__( 'days before the trial ends', 'subtleforms-license-server' ) . '<p class="description">' . esc_html__( 'Tells the customer the amount, the date and how to cancel. 0 turns it off.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-renewal-notice">' . esc_html__( 'Renewal emails', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-renewal-notice" type="text" class="regular-text" name="renewal_notice" value="' . esc_attr( (string) $s['renewal_notice'] ) . '" placeholder="14,3"><p class="description">' . esc_html__( 'Days before each yearly renewal, separated by commas.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Connection', 'subtleforms-license-server' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sfls-paddle-env">' . esc_html__( 'Environment', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-paddle-env" name="env"><option value="sandbox"' . selected( $config['env'], 'sandbox', false ) . '>' . esc_html__( 'Sandbox (test payments)', 'subtleforms-license-server' ) . '</option><option value="live"' . selected( $config['env'], 'live', false ) . '>' . esc_html__( 'Live', 'subtleforms-license-server' ) . '</option></select><p class="description">' . esc_html__( 'Sandbox and live accounts have different keys, tokens, prices and webhook secrets.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-paddle-key">' . esc_html__( 'API key', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-paddle-key" type="password" autocomplete="new-password" class="regular-text" name="api_key" value="" placeholder="' . esc_attr( '' !== $config['api_key'] ? '••••••••' . substr( $config['api_key'], -4 ) : 'pdl_sdbx_apikey_…' ) . '"><p class="description">' . esc_html( '' !== $config['api_key'] ? self::secret_hint( $config['api_key'] ) : __( 'Paddle › Developer tools › Authentication › API keys. Permissions: subscription read and write, customer read and write, address write, customer portal session write, transaction read and write, adjustment write, product and price read and write.', 'subtleforms-license-server' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-paddle-token">' . esc_html__( 'Client-side token', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-paddle-token" type="text" class="regular-text sfls-mono" name="client_token" value="' . esc_attr( $config['client_token'] ) . '" placeholder="test_…"><p class="description">' . esc_html__( 'Paddle › Developer tools › Authentication › Client-side tokens. Used by the checkout in the browser.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-paddle-secret">' . esc_html__( 'Webhook secret', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-paddle-secret" type="password" autocomplete="new-password" class="regular-text" name="webhook_secret" value="" placeholder="' . esc_attr( '' !== $config['webhook_secret'] ? '••••••••' . substr( $config['webhook_secret'], -4 ) : 'pdl_ntfset_…' ) . '"><p class="description">' . esc_html( '' !== $config['webhook_secret'] ? self::secret_hint( $config['webhook_secret'] ) : __( 'The secret key of the notification destination below.', 'subtleforms-license-server' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="sfls-paddle-url">' . esc_html__( 'Webhook URL', 'subtleforms-license-server' ) . '</label></th><td><input id="sfls-paddle-url" type="text" readonly class="large-text sfls-mono" value="' . esc_attr( SFLS_Paddle::webhook_url() ) . '" onfocus="this.select();"><p class="description">' . esc_html__( 'In Paddle › Developer tools › Notifications, add a destination with this URL, type Webhook, and subscribe to: subscription.created, subscription.trialing, subscription.activated, subscription.updated, subscription.past_due, subscription.paused, subscription.resumed, subscription.canceled, transaction.completed, transaction.payment_failed, adjustment.created, adjustment.updated.', 'subtleforms-license-server' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save Paddle settings', 'subtleforms-license-server' ) );
		echo '</form>';

		echo '<div class="sfls-panel sfls-paddle-tools"><h2>' . esc_html__( 'Check and sync', 'subtleforms-license-server' ) . '</h2><p class="description">' . esc_html__( 'Test connection checks the API key against the selected environment. Sync products lists your Paddle products and prices so you can map them to plans below.', 'subtleforms-license-server' ) . '</p><div class="sfls-button-row">';
		foreach ( array(
			'sfls_paddle_test' => __( 'Test connection', 'subtleforms-license-server' ),
			'sfls_paddle_sync' => __( 'Sync products', 'subtleforms-license-server' ),
		) as $action => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( $action );
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
			submit_button( $label, 'secondary', 'submit', false, '' === $config['api_key'] ? array( 'disabled' => 'disabled' ) : array() );
			echo '</form>';
		}
		echo '</div></div>';

		// Plans.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfls-panel">';
		wp_nonce_field( 'sfls_paddle_map' );
		echo '<input type="hidden" name="action" value="sfls_paddle_map"><h2>' . esc_html__( 'Plans', 'subtleforms-license-server' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each plan needs a yearly price without a trial (for Buy now and upgrades). Add a second yearly price with a 7 or 14 day trial that requires a payment method to offer a free trial. Prices without a product in the list can be pasted as pri_ IDs.', 'subtleforms-license-server' ) . '</p>';
		if ( 'woo_paddle' === SFLS_Settings::sell_with() ) {
			echo '<p class="description">' . esc_html__( 'With the WooCommerce checkout, keep each yearly license product in Licenses › Products at the same price as its Paddle price. Paddle charges the Paddle price, and Place order is refused when the two differ.', 'subtleforms-license-server' ) . '</p>';
		}
		if ( null === $prices && '' !== $config['api_key'] ) {
			echo '<p class="description">' . esc_html__( 'Click Sync products to pick prices from a list.', 'subtleforms-license-server' ) . '</p>';
		}
		echo '<table class="widefat sfls-wizard-table sfls-paddle-plans"><thead><tr><th scope="col">' . esc_html__( 'Plan', 'subtleforms-license-server' ) . '</th><th scope="col">' . esc_html__( 'Yearly price', 'subtleforms-license-server' ) . '</th><th scope="col">' . esc_html__( 'Trial price', 'subtleforms-license-server' ) . ' <span class="sfls-optional">' . esc_html__( 'optional', 'subtleforms-license-server' ) . '</span></th><th scope="col">' . esc_html__( 'Trial days', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
		foreach ( $plans as $plan => $row ) {
			echo '<tr><th scope="row"><strong>' . esc_html( SFLS_Plans::label( $plan ) ) . '</strong><span class="sfls-plan-feats">' . esc_html( SFLS_Plans::sites_label( (int) ( SFLS_Plans::get( $plan )['sites'] ?? 1 ) ) );
			if ( '' !== $row['amount'] && '' !== $row['currency'] ) {
				echo ' · ' . esc_html( SFLS_Paddle::format_amount( $row['amount'], $row['currency'] ) ) . esc_html__( '/year', 'subtleforms-license-server' );
			}
			echo '</span></th>';
			foreach ( array(
				'price_id'       => false,
				'trial_price_id' => true,
			) as $field => $wants_trial ) {
				$id = 'sfls-paddle-' . $plan . '-' . $field;
				echo '<td data-colname="' . esc_attr( $wants_trial ? __( 'Trial price', 'subtleforms-license-server' ) : __( 'Yearly price', 'subtleforms-license-server' ) ) . '"><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( SFLS_Plans::label( $plan ) . ' ' . ( $wants_trial ? __( 'Trial price', 'subtleforms-license-server' ) : __( 'Yearly price', 'subtleforms-license-server' ) ) ) . '</label>';
				if ( $prices ) {
					echo '<select id="' . esc_attr( $id ) . '" name="plans[' . esc_attr( $plan ) . '][' . esc_attr( $field ) . ']"><option value="">' . esc_html( $wants_trial ? __( 'No trial', 'subtleforms-license-server' ) : __( 'Not sold', 'subtleforms-license-server' ) ) . '</option>';
					$found = false;
					foreach ( $prices as $price ) {
						$days = SFLS_Paddle::trial_days_of( $price );
						if ( $wants_trial !== ( $days > 0 ) && $price['id'] !== $row[ $field ] ) {
							continue;
						}
						$found = $found || $price['id'] === $row[ $field ];
						echo '<option value="' . esc_attr( $price['id'] ) . '"' . selected( $row[ $field ], $price['id'], false ) . '>' . esc_html( self::price_label( $price ) ) . '</option>';
					}
					if ( '' !== $row[ $field ] && ! $found ) {
						echo '<option value="' . esc_attr( $row[ $field ] ) . '" selected>' . esc_html( $row[ $field ] ) . '</option>';
					}
					echo '</select>';
				} else {
					echo '<input id="' . esc_attr( $id ) . '" type="text" class="regular-text sfls-mono" name="plans[' . esc_attr( $plan ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( $row[ $field ] ) . '" placeholder="pri_…">';
				}
				echo '</td>';
			}
			$tid = 'sfls-paddle-' . $plan . '-trial-days';
			echo '<td data-colname="' . esc_attr__( 'Trial days', 'subtleforms-license-server' ) . '"><label class="screen-reader-text" for="' . esc_attr( $tid ) . '">' . esc_html( SFLS_Plans::label( $plan ) . ' ' . __( 'Trial days', 'subtleforms-license-server' ) ) . '</label><select id="' . esc_attr( $tid ) . '" name="plans[' . esc_attr( $plan ) . '][trial_days]">';
			$days = array(
				0  => __( 'No trial', 'subtleforms-license-server' ),
				7  => __( '7 days', 'subtleforms-license-server' ),
				14 => __( '14 days', 'subtleforms-license-server' ),
			);
			if ( ! isset( $days[ $row['trial_days'] ] ) ) {
				/* translators: %d: number of days */
				$days[ $row['trial_days'] ] = sprintf( _n( '%d day', '%d days', $row['trial_days'], 'subtleforms-license-server' ), $row['trial_days'] );
			}
			foreach ( $days as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $row['trial_days'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Trial days are read from the trial price when you save and are shown on the pricing page. Paddle decides the real length, so keep them the same. Licenses › Products can also create these products and prices in Paddle for you.', 'subtleforms-license-server' ) . '</p>';
		submit_button( __( 'Save plans', 'subtleforms-license-server' ) );
		echo '</form>';

		// Recent events.
		$events = SFLS_Paddle_REST::recent_events( 15 );
		echo '<div class="sfls-panel"><h2>' . esc_html__( 'Recent webhook events', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $events ) {
			echo '<p class="description">' . esc_html__( 'None yet. After a sandbox checkout, the events Paddle sends show up here.', 'subtleforms-license-server' ) . '</p></div>';
			return;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<table class="widefat striped sfls-acts sfls-log"><thead><tr><th>' . esc_html__( 'Received', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Event', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Result', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'License', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Note', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$tone = in_array( $event['status'], array( 'created', 'updated', 'emailed' ), true ) ? 'ok' : ( in_array( $event['status'], array( 'rejected', 'failed' ), true ) ? 'bad' : 'neutral' );
			echo '<tr><td>' . esc_html( wp_date( $fmt, strtotime( $event['received_at'] . ' UTC' ) ) ) . '</td><td class="sfls-mono">' . esc_html( $event['event_type'] ) . '<br><span class="description">' . esc_html( $event['event_id'] ) . '</span></td>';
			echo '<td><span class="sfls-admin-pill sfls-admin-pill--' . esc_attr( $tone ) . '">' . esc_html( $event['status'] ) . '</span></td>';
			echo '<td>' . ( (int) $event['license_id'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=sfls&edit=' . (int) $event['license_id'] ) ) . '">#' . esc_html( (string) $event['license_id'] ) . '</a>' : '–' ) . '</td><td>' . esc_html( (string) $event['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * "SubtleForms Pro · $79.00/year · 14 day trial (pri_01…)".
	 *
	 * @param array $price Paddle price with its product.
	 */
	private static function price_label( array $price ): string {
		$parts = array( (string) ( $price['product']['name'] ?? $price['name'] ?? $price['description'] ?? '' ) );
		if ( isset( $price['unit_price']['amount'], $price['unit_price']['currency_code'] ) ) {
			$cycle   = $price['billing_cycle'] ?? null;
			$parts[] = SFLS_Paddle::format_amount( (string) $price['unit_price']['amount'], (string) $price['unit_price']['currency_code'] ) . ( is_array( $cycle ) ? '/' . ( 1 === (int) $cycle['frequency'] ? $cycle['interval'] : $cycle['frequency'] . ' ' . $cycle['interval'] ) : ' ' . __( 'one-time', 'subtleforms-license-server' ) );
		}
		$days = SFLS_Paddle::trial_days_of( $price );
		if ( $days ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( __( '%d day trial', 'subtleforms-license-server' ), $days );
		}
		return implode( ' · ', array_filter( $parts, 'strlen' ) ) . ' (' . $price['id'] . ')';
	}

	/**
	 * Save the Paddle selling and connection settings.
	 */
	public function handle_paddle_settings(): void {
		$this->guard();
		check_admin_referer( 'sfls_paddle_settings' );
		SFLS_Settings::save_selling(
			array(
				'sell_with'      => isset( $_POST['sell_with'] ) ? sanitize_key( wp_unslash( $_POST['sell_with'] ) ) : 'woocommerce',
				'renewal_notice' => isset( $_POST['renewal_notice'] ) ? sanitize_text_field( wp_unslash( $_POST['renewal_notice'] ) ) : '',
				'trial_notice'   => absint( $_POST['trial_notice'] ?? 3 ),
			)
		);
		$values = array(
			'env'          => isset( $_POST['env'] ) && 'live' === $_POST['env'] ? 'live' : 'sandbox',
			'client_token' => isset( $_POST['client_token'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['client_token'] ) ) ) : '',
		);
		foreach ( array( 'api_key', 'webhook_secret' ) as $secret ) {
			$value = isset( $_POST[ $secret ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $secret ] ) ) ) : '';
			if ( '' !== $value ) {
				$values[ $secret ] = $value; // Empty keeps the saved secret.
			}
		}
		if ( SFLS_Paddle::config()['env'] !== $values['env'] ) {
			delete_transient( 'sfls_paddle_prices' ); // Prices belong to one environment.
		}
		SFLS_Paddle::save_config( $values );
		$this->flash( __( 'Paddle settings saved.', 'subtleforms-license-server' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings&tab=paddle' ) );
		exit;
	}

	/**
	 * Test connection.
	 */
	public function handle_paddle_test(): void {
		$this->guard();
		check_admin_referer( 'sfls_paddle_test' );
		$result = SFLS_Paddle::test_connection();
		if ( is_wp_error( $result ) ) {
			$this->flash( $result->get_error_message(), 'error' );
		} else {
			$this->flash(
				'live' === SFLS_Paddle::config()['env']
					? __( 'Connected to Paddle (live).', 'subtleforms-license-server' )
					: __( 'Connected to the Paddle sandbox.', 'subtleforms-license-server' )
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings&tab=paddle' ) );
		exit;
	}

	/**
	 * Sync products: cache the Paddle price list for the plan pickers.
	 */
	public function handle_paddle_sync(): void {
		$this->guard();
		check_admin_referer( 'sfls_paddle_sync' );
		$prices = SFLS_Paddle::list_prices();
		if ( is_wp_error( $prices ) ) {
			$this->flash( $prices->get_error_message(), 'error' );
		} else {
			set_transient( 'sfls_paddle_prices', $prices, DAY_IN_SECONDS );
			$this->flash(
				sprintf(
					/* translators: %d: number of prices */
					_n( 'Found %d active price in Paddle. Pick the prices for each plan below.', 'Found %d active prices in Paddle. Pick the prices for each plan below.', count( $prices ), 'subtleforms-license-server' ),
					count( $prices )
				)
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings&tab=paddle' ) );
		exit;
	}

	/**
	 * Save the plan to price mapping. Amounts, currency, product and trial
	 * days are read from Paddle when the prices are known.
	 */
	public function handle_paddle_map(): void {
		$this->guard();
		check_admin_referer( 'sfls_paddle_map' );
		$raw    = isset( $_POST['plans'] ) && is_array( $_POST['plans'] ) ? wp_unslash( $_POST['plans'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$prices = get_transient( 'sfls_paddle_prices' );
		if ( ! is_array( $prices ) && SFLS_Paddle::has_api_key() ) {
			$prices = SFLS_Paddle::list_prices();
			$prices = is_wp_error( $prices ) ? array() : $prices;
		}
		$by_id = array();
		foreach ( (array) $prices as $price ) {
			$by_id[ (string) ( $price['id'] ?? '' ) ] = $price;
		}
		$current  = SFLS_Paddle::plans();
		$plans    = array();
		$warnings = array();
		foreach ( array_keys( SFLS_Plans::all() ) as $plan ) {
			$in    = isset( $raw[ $plan ] ) && is_array( $raw[ $plan ] ) ? $raw[ $plan ] : array();
			$clean = static fn( $v ) => preg_match( '/^pri_[a-z0-9]+$/', (string) $v ) ? (string) $v : '';
			$row   = array(
				'price_id'       => $clean( sanitize_text_field( (string) ( $in['price_id'] ?? '' ) ) ),
				'trial_price_id' => $clean( sanitize_text_field( (string) ( $in['trial_price_id'] ?? '' ) ) ),
				'trial_days'     => min( 365, absint( $in['trial_days'] ?? 0 ) ),
				'product_id'     => $current[ $plan ]['product_id'],
				'amount'         => $current[ $plan ]['price_id'] === $clean( $in['price_id'] ?? '' ) ? $current[ $plan ]['amount'] : '',
				'currency'       => $current[ $plan ]['price_id'] === $clean( $in['price_id'] ?? '' ) ? $current[ $plan ]['currency'] : '',
			);
			$buy = $by_id[ $row['price_id'] ] ?? null;
			if ( $buy ) {
				$row['amount']     = (string) ( $buy['unit_price']['amount'] ?? '' );
				$row['currency']   = (string) ( $buy['unit_price']['currency_code'] ?? '' );
				$row['product_id'] = (string) ( $buy['product_id'] ?? $row['product_id'] );
				if ( SFLS_Paddle::trial_days_of( $buy ) > 0 ) {
					/* translators: %s: plan name */
					$warnings[] = sprintf( __( 'The %s yearly price has a trial. Pick a price without a trial for Buy now and upgrades.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
				}
				if ( 'year' !== ( $buy['billing_cycle']['interval'] ?? '' ) ) {
					/* translators: %s: plan name */
					$warnings[] = sprintf( __( 'The %s price does not renew yearly.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
				}
			}
			$trial = $by_id[ $row['trial_price_id'] ] ?? null;
			if ( $trial ) {
				$days = SFLS_Paddle::trial_days_of( $trial );
				if ( $days > 0 ) {
					$row['trial_days'] = $days;
				} else {
					/* translators: %s: plan name */
					$warnings[] = sprintf( __( 'The %s trial price has no trial period in Paddle.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
				}
				if ( isset( $trial['trial_period']['requires_payment_method'] ) && ! $trial['trial_period']['requires_payment_method'] ) {
					/* translators: %s: plan name */
					$warnings[] = sprintf( __( 'The %s trial price does not require a payment method, so Paddle cannot charge when the trial ends.', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
				}
			}
			if ( '' === $row['trial_price_id'] ) {
				$row['trial_days'] = 0;
			}
			$plans[ $plan ] = $row;
		}
		SFLS_Paddle::save_config( array( 'plans' => $plans ) );
		$this->flash( trim( __( 'Plans saved.', 'subtleforms-license-server' ) . ' ' . implode( ' ', $warnings ) ), $warnings ? 'warning' : 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings&tab=paddle' ) );
		exit;
	}

	/**
	 * Products wizard: create the Paddle products and yearly prices (with
	 * and without a trial) from the WooCommerce yearly prices.
	 */
	public function handle_paddle_create(): void {
		$this->guard();
		check_admin_referer( 'sfls_paddle_create' );
		$back     = admin_url( 'admin.php?page=sfls-products' );
		$currency = isset( $_POST['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) ) : 'USD';
		$trials   = isset( $_POST['trial'] ) && is_array( $_POST['trial'] ) ? array_map( 'absint', wp_unslash( $_POST['trial'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint on every value.
		$values   = SFLS_Products::wizard_values();
		$plans    = SFLS_Paddle::plans();
		$created  = 0;
		$errors   = array();

		foreach ( $plans as $plan => $row ) {
			$amount = (string) ( $values[ $plan ]['yearly'] ?? '' );
			if ( '' === $amount ) {
				continue;
			}
			$name  = 'pro' === $plan ? __( 'SubtleForms Pro', 'subtleforms-license-server' ) : sprintf( /* translators: %s: plan name */ __( 'SubtleForms Pro %s', 'subtleforms-license-server' ), SFLS_Plans::label( $plan ) );
			$minor = SFLS_Paddle::to_minor( $amount, $currency );
			$days  = in_array( (int) ( $trials[ $plan ] ?? 0 ), array( 7, 14 ), true ) ? (int) $trials[ $plan ] : 0;

			if ( '' === $row['product_id'] ) {
				$product = SFLS_Paddle::create_product( $name, SFLS_Plans::feature_line( $plan ) );
				if ( is_wp_error( $product ) ) {
					$errors[] = $product->get_error_message();
					continue;
				}
				$row['product_id'] = (string) $product['id'];
			}
			if ( '' === $row['price_id'] ) {
				$price = SFLS_Paddle::create_yearly_price( $row['product_id'], $name, $minor, $currency, 0 );
				if ( is_wp_error( $price ) ) {
					$errors[] = $price->get_error_message();
				} else {
					$row['price_id'] = (string) $price['id'];
					$row['amount']   = $minor;
					$row['currency'] = $currency;
					++$created;
				}
			}
			if ( $days && '' === $row['trial_price_id'] ) {
				$price = SFLS_Paddle::create_yearly_price( $row['product_id'], $name, $minor, $currency, $days );
				if ( is_wp_error( $price ) ) {
					$errors[] = $price->get_error_message();
				} else {
					$row['trial_price_id'] = (string) $price['id'];
					$row['trial_days']     = $days;
					++$created;
				}
			}
			$plans[ $plan ] = $row;
		}
		SFLS_Paddle::save_config( array( 'plans' => $plans ) );
		delete_transient( 'sfls_paddle_prices' );
		if ( $errors ) {
			$this->flash( implode( ' ', array_unique( $errors ) ), 'error' );
		} else {
			$this->flash(
				sprintf(
					/* translators: %d: number of prices */
					_n( '%d price created in Paddle and mapped to its plan.', '%d prices created in Paddle and mapped to their plans.', $created, 'subtleforms-license-server' ),
					$created
				)
			);
		}
		wp_safe_redirect( $back );
		exit;
	}

	// ── Handlers ────────────────────────────────────────────────────────

	/**
	 * Issue keys.
	 */
	public function handle_create(): void {
		$this->guard();
		check_admin_referer( 'sfls_create' );
		$result = self::issue_from_form( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in issue_from_form().
		$keys   = wp_list_pluck( $result['licenses'], 'license_key' );
		/* translators: %s: keys */
		$message = sprintf( __( 'Issued: %s', 'subtleforms-license-server' ), implode( ', ', $keys ) );
		$type    = 'success';
		if ( true === $result['emailed'] ) {
			/* translators: %s: email address */
			$message .= ' ' . sprintf( __( 'Emailed to %s.', 'subtleforms-license-server' ), $result['email'] );
		} elseif ( false === $result['emailed'] ) {
			$message .= ' ' . ( is_email( $result['email'] )
				? __( 'The email could not be sent; copy the key to the customer by hand.', 'subtleforms-license-server' )
				: __( 'No email was sent: enter a customer email to send the key.', 'subtleforms-license-server' ) );

			$type = 'warning';
		}
		$this->flash( $message, $type );
		wp_safe_redirect( admin_url( 'admin.php?page=sfls' ) );
		exit;
	}

	/**
	 * Issue licenses from the Issue license form, and email the keys when
	 * asked (LS-18).
	 *
	 * @param array $input Unslashed form fields.
	 * @return array{licenses:array[],email:string,emailed:bool|null} emailed is null when no email was asked for.
	 */
	public static function issue_from_form( array $input ): array {
		$count    = min( 50, max( 1, absint( $input['count'] ?? 1 ) ) );
		$sites    = isset( $input['sites'] ) && '' !== $input['sites'] ? absint( $input['sites'] ) : null;
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$plan     = sanitize_key( (string) ( $input['plan'] ?? 'pro' ) );
		$plan     = SFLS_Plans::get( $plan ) ? $plan : 'pro';
		$licenses = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$args = array(
				'plan'           => $plan,
				'customer_email' => $email,
				'months'         => absint( $input['months'] ?? 12 ),
				'notes'          => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
			);
			if ( null !== $sites ) {
				$args['sites_allowed'] = $sites;
			}
			$licenses[] = SFLS_Licenses::create( $args );
		}
		$emailed = null;
		if ( ! empty( $input['send_email'] ) ) {
			$emailed = is_email( $email ) && SFLS_Delivery::email_issued( $licenses, $email );
		}
		return array(
			'licenses' => $licenses,
			'email'    => $email,
			'emailed'  => $emailed,
		);
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
				SFLS_Licenses::reset_activations( $id, 'admin' );
				$msg = __( 'All sites deactivated.', 'subtleforms-license-server' );
				break;
			case 'deactivate':
				$domain = SFLS_Licenses::normalize_domain( sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) ) );
				SFLS_Licenses::remove_activation( $id, $domain, 'admin' );
				/* translators: %s: domain */
				$msg = sprintf( __( '%s deactivated.', 'subtleforms-license-server' ), $domain );
				break;
			case 'regenerate':
				$key = SFLS_Licenses::regenerate_key( $id );
				/* translators: %s: key */
				$msg = sprintf( __( 'New key: %s', 'subtleforms-license-server' ), $key );
				break;
			case 'release_trial':
				SFLS_Trials::release( $id );
				$msg = __( 'This customer can start another free trial.', 'subtleforms-license-server' );
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
		$extra = array();
		if ( isset( $_POST['staging_hosts'] ) ) {
			$extra['staging_hosts'] = sanitize_textarea_field( wp_unslash( $_POST['staging_hosts'] ) );
		}
		if ( isset( $_POST['retention_days'] ) ) {
			$extra['retention_days'] = absint( $_POST['retention_days'] );
		}
		SFLS_Settings::save(
			$extra + array(
				'pricing_url'      => isset( $_POST['pricing_url'] ) ? sanitize_url( wp_unslash( $_POST['pricing_url'] ) ) : '',
				'reminder_days'    => isset( $_POST['reminder_days'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_days'] ) ) : '',
				'lapsed_reminder'  => ! empty( $_POST['lapsed_reminder'] ),
				'support_email'    => isset( $_POST['support_email'] ) ? sanitize_email( wp_unslash( $_POST['support_email'] ) ) : '',
				'simplify_account' => ! empty( $_POST['simplify_account'] ),
				'simple_checkout'  => ! empty( $_POST['simple_checkout'] ),
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
	 * Send an example reminder to the admin.
	 */
	public function handle_test_reminder(): void {
		$this->guard();
		check_admin_referer( 'sfls_test_reminder' );
		$to    = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$stage = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : 'before';
		$sent  = false;
		if ( is_email( $to ) ) {
			$sent = isset( SFLS_Billing_Emails::templates()[ $stage ] )
				? SFLS_Billing_Emails::send_test( $to, $stage )
				: SFLS_Reminders::send_test( $to, 'lapsed' === $stage ? 'lapsed' : 'before' );
		}
		if ( ! is_email( $to ) ) {
			$this->flash( __( 'Enter a valid email address for the test.', 'subtleforms-license-server' ), 'error' );
		} elseif ( $sent ) {
			/* translators: %s: email address */
			$this->flash( sprintf( __( 'Test reminder sent to %s.', 'subtleforms-license-server' ), $to ) );
		} else {
			$this->flash( __( 'The test reminder could not be sent. Check that this site can send email.', 'subtleforms-license-server' ), 'error' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sfls-settings' ) );
		exit;
	}

	/**
	 * Download the filtered license list as CSV.
	 */
	public function handle_export(): void {
		$this->guard();
		check_admin_referer( 'sfls_export' );
		[ $search, $status, $plan ] = self::list_filters();
		[ $rows ]                   = SFLS_Licenses::query(
			array(
				'search' => $search,
				'status' => $status,
				'plan'   => $plan,
				'per'    => 0,
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="licenses-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::csv( SFLS_Licenses::csv_rows( $rows ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; formulas are neutralised by csv_rows().
		exit;
	}

	/**
	 * Build CSV text (RFC 4180 quoting).
	 *
	 * @param array[] $rows Rows.
	 */
	public static function csv( array $rows ): string {
		$out = '';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell    = (string) $cell;
				$cells[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
			}
			$out .= implode( ',', $cells ) . "\r\n";
		}
		return $out;
	}
}
