<?php
/**
 * Reports screens, CSV export and REST route.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom report builder UI.
 */
final class SFLS_Reports_Admin {

	const PAGE = 'sfls-reports';

	/**
	 * Hooks. Safe outside wp-admin: the REST route lives here too.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_sfls_report_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_sfls_report_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_sfls_report_duplicate', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_sfls_report_csv', array( $this, 'handle_csv' ) );
		add_action( 'admin_post_sfls_std_csv', array( $this, 'handle_std_csv' ) );
		add_action( 'admin_post_sfls_std_save', array( $this, 'handle_std_save' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Submenu.
	 */
	public function menu(): void {
		add_submenu_page( 'sfls', __( 'Reports', 'subtleforms-license-server' ), __( 'Reports', 'subtleforms-license-server' ), SFLS_Reports::capability(), self::PAGE, array( $this, 'page' ) );
	}

	/**
	 * REST route sfls/v1/reports/<id>.
	 */
	public function register_rest(): void {
		register_rest_route(
			'sfls/v1',
			'/reports/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_run' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/**
	 * REST permission: capability and report visibility.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function rest_permission( $request ) {
		if ( ! current_user_can( SFLS_Reports::capability() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to view reports.', 'subtleforms-license-server' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$report = SFLS_Reports::find( (int) $request['id'] );
		if ( ! $report || ! SFLS_Reports::can_view( $report, get_current_user_id() ) ) {
			return new WP_Error( 'rest_not_found', __( 'Report not found.', 'subtleforms-license-server' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * REST: run a report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_run( $request ) {
		$report = SFLS_Reports::find( (int) $request['id'] );
		$result = $report ? SFLS_Reports::run( $report ) : new WP_Error( 'rest_not_found', __( 'Report not found.', 'subtleforms-license-server' ), array( 'status' => 404 ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response(
			array(
				'id'         => $report['id'],
				'name'       => $report['name'],
				'definition' => $report['definition'],
			) + $result
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Stop unless the user has the capability.
	 */
	private function guard(): void {
		if ( ! current_user_can( SFLS_Reports::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view reports.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Report the user may see, or stop.
	 *
	 * @param int $id Report ID.
	 */
	private function visible( int $id ): array {
		$report = SFLS_Reports::find( $id );
		if ( ! $report || ! SFLS_Reports::can_view( $report, get_current_user_id() ) ) {
			wp_die( esc_html__( 'Report not found.', 'subtleforms-license-server' ), '', array( 'response' => 404 ) );
		}
		return $report;
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Extra query args.
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect with a notice.
	 *
	 * @param string $url     Target.
	 * @param string $message Notice.
	 * @param string $type    Notice type.
	 */
	private function back( string $url, string $message, string $type = 'success' ): void {
		( new SFLS_Admin() )->flash( $message, $type );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Definition from the builder form, cleaned of fields the dataset does not have.
	 *
	 * @param array $in Unslashed POST.
	 */
	public static function definition_from_input( array $in ): array {
		$dataset = sanitize_key( $in['dataset'] ?? 'licenses' );
		$fields  = SFLS_Report_Query::fields( $dataset );
		if ( ! $fields ) {
			$dataset = 'licenses';
			$fields  = SFLS_Report_Query::fields( $dataset );
		}
		$text = static fn( $v ): string => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
		$def  = array(
			'dataset'  => $dataset,
			'group_by' => array(),
			'measures' => array(),
			'filters'  => array(),
			'from'     => $text( $in['from'] ?? '' ),
			'to'       => $text( $in['to'] ?? '' ),
			'sort'     => $text( $in['sort'] ?? '' ),
			'limit'    => max( 1, min( SFLS_Report_Query::MAX_GROUPS, (int) ( $in['limit'] ?? SFLS_Report_Query::DEFAULT_LIMIT ) ) ),
			'display'  => in_array( $in['display'] ?? '', SFLS_Reports::DISPLAYS, true ) ? $in['display'] : 'table',
		);
		foreach ( array_slice( (array) ( $in['group_by'] ?? array() ), 0, 2 ) as $key ) {
			$key = $text( $key );
			if ( isset( $fields[ $key ] ) && $fields[ $key ]['groupable'] && ! in_array( $key, $def['group_by'], true ) ) {
				$def['group_by'][] = $key;
			}
		}
		foreach ( (array) ( $in['measures'] ?? array() ) as $m ) {
			$fn    = $text( $m['fn'] ?? '' );
			$field = $text( $m['field'] ?? '' );
			if ( 'count' === $fn ) {
				$def['measures'][] = array( 'fn' => 'count' );
			} elseif ( in_array( $fn, array( 'sum', 'avg' ), true ) && isset( $fields[ $field ] ) && $fields[ $field ]['measurable'] ) {
				$def['measures'][] = array(
					'fn'    => $fn,
					'field' => $field,
				);
			}
		}
		foreach ( (array) ( $in['filters'] ?? array() ) as $f ) {
			$field = $text( $f['field'] ?? '' );
			$value = $text( $f['value'] ?? '' );
			if ( isset( $fields[ $field ] ) && $fields[ $field ]['filterable'] && '' !== $value ) {
				$def['filters'][] = array(
					'field' => $field,
					'op'    => $text( $f['op'] ?? 'eq' ),
					'value' => $value,
				);
			}
		}
		return $def;
	}

	// ── Handlers ────────────────────────────────────────────────────────

	/**
	 * Save a report.
	 */
	public function handle_save(): void {
		$this->guard();
		check_admin_referer( 'sfls_report_save' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id  = absint( $in['id'] ?? 0 );
		$def = self::definition_from_input( $in );
		if ( $id ) {
			$this->visible( $id );
		}
		$check = SFLS_Report_Query::run( $def );
		$name  = sanitize_text_field( (string) ( $in['name'] ?? '' ) );
		if ( is_wp_error( $check ) || '' === $name ) {
			$this->back( $this->url( array( 'action' => 'edit' ) + ( $id ? array( 'id' => $id ) : array() ) ), is_wp_error( $check ) ? $check->get_error_message() : __( 'Give the report a name.', 'subtleforms-license-server' ), 'error' );
		}
		$data = array(
			'id'         => $id,
			'name'       => $name,
			'shared'     => ! empty( $in['shared'] ),
			'definition' => $def,
		);
		// Only administrators set or change a schedule.
		if ( current_user_can( 'manage_options' ) && isset( $in['schedule'] ) ) {
			$to = SFLS_Report_Schedule::parse_recipients( (string) ( $in['recipients'] ?? '' ) );
			if ( is_wp_error( $to ) || ( 'none' !== $in['schedule'] && ! $to ) ) {
				$this->back( $this->url( array( 'action' => 'edit' ) + ( $id ? array( 'id' => $id ) : array() ) ), is_wp_error( $to ) ? $to->get_error_message() : __( 'Add at least one recipient to schedule the report.', 'subtleforms-license-server' ), 'error' );
			}
			$data['schedule']   = (string) $in['schedule'];
			$data['recipients'] = implode( ', ', $to );
		}
		$id = SFLS_Reports::save( $data );
		$this->back( $this->url( array( 'action' => 'view' ) + array( 'id' => $id ) ), __( 'Report saved.', 'subtleforms-license-server' ) );
	}

	/**
	 * Delete a report.
	 */
	public function handle_delete(): void {
		$this->guard();
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sfls_report_delete_' . $id );
		$this->visible( $id );
		SFLS_Reports::delete( $id );
		$this->back( $this->url(), __( 'Report deleted.', 'subtleforms-license-server' ) );
	}

	/**
	 * Duplicate a report as a private copy of the current user.
	 */
	public function handle_duplicate(): void {
		$this->guard();
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sfls_report_duplicate_' . $id );
		$report = $this->visible( $id );
		$new    = SFLS_Reports::save(
			array(
				/* translators: %s: report name */
				'name'       => sprintf( __( 'Copy of %s', 'subtleforms-license-server' ), $report['name'] ),
				'shared'     => 0,
				'definition' => $report['definition'],
			)
		);
		$this->back( $this->url( array( 'action' => 'edit' ) + array( 'id' => $new ) ), __( 'Report duplicated.', 'subtleforms-license-server' ) );
	}

	/**
	 * Stream a report as CSV.
	 */
	public function handle_csv(): void {
		$this->guard();
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sfls_report_csv_' . $id );
		$report = $this->visible( $id );
		$result = SFLS_Reports::run( $report );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'report-' . $id . '-' . gmdate( 'Ymd' ) . '.csv' ) . '"' );
		echo self::csv( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Stream a standard report as CSV.
	 */
	public function handle_std_csv(): void {
		$this->guard();
		$key = sanitize_key( $_GET['key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sfls_std_csv_' . $key );
		[ $from, $to ] = $this->range();
		$result        = SFLS_Standard_Reports::run( $key, $from, $to );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $key . '-' . gmdate( 'Ymd' ) . '.csv' ) . '"' );
		echo self::csv( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Save a standard report as a private custom report.
	 */
	public function handle_std_save(): void {
		$this->guard();
		$key = sanitize_key( $_GET['key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'sfls_std_save_' . $key );
		$report = SFLS_Standard_Reports::find( $key );
		if ( ! $report || ! $report['def'] ) {
			wp_die( esc_html__( 'This report cannot be saved as a custom report.', 'subtleforms-license-server' ), '', array( 'response' => 400 ) );
		}
		[ $from, $to ] = $this->range();
		$id            = SFLS_Reports::save(
			array(
				'name'       => $report['label'],
				'shared'     => 0,
				'definition' => SFLS_Standard_Reports::definition( $report, $from, $to ),
			)
		);
		$this->back( $this->url( array( 'action' => 'edit' ) + array( 'id' => $id ) ), __( 'Saved as a custom report.', 'subtleforms-license-server' ) );
	}

	/**
	 * Date range from the query string (Y-m-d or empty).
	 *
	 * @return string[]
	 */
	private function range(): array {
		$d = static function ( string $k ): string {
			$v = sanitize_text_field( wp_unslash( $_GET[ $k ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		return array( $d( 'from' ), $d( 'to' ) );
	}

	/**
	 * CSV text of a result. Text cells that start with = + - @ (or a tab or
	 * return) get a leading quote so a spreadsheet does not run them.
	 *
	 * @param array $result Query result.
	 */
	public static function csv( array $result ): string {
		$cell = static function ( $v ): string {
			if ( is_int( $v ) || is_float( $v ) ) {
				return (string) $v;
			}
			$v = (string) $v;
			return '' !== $v && str_contains( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
		};
		$out  = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array_map( $cell, wp_list_pluck( $result['columns'], 'label' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( $result['rows'] as $row ) {
			fputcsv( $out, array_map( $cell, array_values( $row ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		rewind( $out );
		$text = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $text;
	}

	// ── Screens ─────────────────────────────────────────────────────────

	/**
	 * Route the screens.
	 */
	public function page(): void {
		$this->guard();
		$action = sanitize_key( $_GET['action'] ?? 'list' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'view' === $action && $id ) {
			$this->screen_view( $this->visible( $id ) );
		} elseif ( 'standard' === $action && SFLS_Standard_Reports::find( sanitize_key( $_GET['key'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->screen_standard( sanitize_key( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( 'edit' === $action ) {
			$this->screen_builder( $id ? $this->visible( $id ) : null );
		} else {
			$this->screen_list();
		}
	}

	/**
	 * List of saved reports.
	 */
	private function screen_list(): void {
		$admin   = new SFLS_Admin();
		$reports = SFLS_Reports::visible_to( get_current_user_id() );
		$admin->header( __( 'Reports', 'subtleforms-license-server' ), '<a class="page-title-action" href="' . esc_url( $this->url( array( 'action' => 'edit' ) ) ) . '">' . esc_html__( 'New report', 'subtleforms-license-server' ) . '</a>' );
		echo '<div class="sfls-card"><h2>' . esc_html__( 'Standard reports', 'subtleforms-license-server' ) . '</h2>';
		$groups = array();
		foreach ( SFLS_Standard_Reports::all() as $key => $std ) {
			$groups[ $std['group'] ][] = '<a href="' . esc_url( $this->url( array( 'action' => 'standard' ) + array( 'key' => $key ) ) ) . '">' . esc_html( $std['label'] ) . '</a>';
		}
		echo '<table class="widefat striped sfls-table"><tbody>';
		foreach ( $groups as $group => $links ) {
			echo '<tr><th scope="row">' . esc_html( $group ) . '</th><td>' . implode( ' | ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		echo '<div class="sfls-card"><h2>' . esc_html__( 'Saved reports', 'subtleforms-license-server' ) . '</h2>';
		if ( ! $reports ) {
			echo '<p class="sfls-empty">' . esc_html__( 'No saved reports yet.', 'subtleforms-license-server' ) . ' <a class="button button-primary" href="' . esc_url( $this->url( array( 'action' => 'edit' ) ) ) . '">' . esc_html__( 'Build a report', 'subtleforms-license-server' ) . '</a></p></div></div>';
			return;
		}
		echo '<table class="widefat striped sfls-table"><thead><tr><th>' . esc_html__( 'Name', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Owner', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Visibility', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Last updated', 'subtleforms-license-server' ) . '</th><th>' . esc_html__( 'Actions', 'subtleforms-license-server' ) . '</th></tr></thead><tbody>';
		foreach ( $reports as $r ) {
			$owner = get_userdata( $r['user_id'] );
			$act   = array(
				__( 'View', 'subtleforms-license-server' ) => $this->url(
					array(
						'action' => 'view',
						'id'     => $r['id'],
					)
				),
				__( 'Edit', 'subtleforms-license-server' ) => $this->url(
					array(
						'action' => 'edit',
						'id'     => $r['id'],
					)
				),
				__( 'Duplicate', 'subtleforms-license-server' ) => wp_nonce_url( admin_url( 'admin-post.php?action=sfls_report_duplicate&id=' . $r['id'] ), 'sfls_report_duplicate_' . $r['id'] ),
				__( 'Delete', 'subtleforms-license-server' ) => wp_nonce_url( admin_url( 'admin-post.php?action=sfls_report_delete&id=' . $r['id'] ), 'sfls_report_delete_' . $r['id'] ),
			);
			$links = array();
			foreach ( $act as $label => $href ) {
				$links[] = '<a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '<tr><td><strong><a href="' . esc_url( $act[ __( 'View', 'subtleforms-license-server' ) ] ) . '">' . esc_html( $r['name'] ) . '</a></strong></td>';
			echo '<td>' . esc_html( $owner ? $owner->display_name : '—' ) . '</td>';
			echo '<td><span class="sfls-badge is-' . ( $r['shared'] ? 'active' : 'neutral' ) . '">' . esc_html( $r['shared'] ? __( 'Shared', 'subtleforms-license-server' ) : __( 'Private', 'subtleforms-license-server' ) ) . '</span></td>';
			echo '<td>' . esc_html( get_date_from_gmt( $r['updated_at'], get_option( 'date_format' ) ) ) . '</td>';
			echo '<td>' . implode( ' | ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Builder form with an optional preview.
	 *
	 * @param array|null $report Saved report being edited.
	 */
	private function screen_builder( ?array $report ): void {
		$admin  = new SFLS_Admin();
		$posted = ! empty( $_POST['sfls_report_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sfls_report_nonce'] ) ), 'sfls_report_builder' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input  = $posted ? wp_unslash( $_POST ) : ( $report ? $report['definition'] : array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$def    = self::definition_from_input( $input );
		$name   = $posted ? sanitize_text_field( (string) ( $input['name'] ?? '' ) ) : ( $report['name'] ?? '' );
		$shared = $posted ? ! empty( $input['shared'] ) : ! empty( $report['shared'] );
		$id     = $report['id'] ?? 0;
		$fields = SFLS_Report_Query::fields( $def['dataset'] );

		$admin->header( $id ? __( 'Edit report', 'subtleforms-license-server' ) : __( 'New report', 'subtleforms-license-server' ), '<a class="page-title-action" href="' . esc_url( $this->url() ) . '">' . esc_html__( 'All reports', 'subtleforms-license-server' ) . '</a>' );

		echo '<form method="post" action="' . esc_url( $this->url( array( 'action' => 'edit' ) + ( $id ? array( 'id' => $id ) : array() ) ) ) . '" class="sfls-card">';
		wp_nonce_field( 'sfls_report_builder', 'sfls_report_nonce' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="sfls-r-name">' . esc_html__( 'Name', 'subtleforms-license-server' ) . '</label></th><td><input type="text" class="regular-text" id="sfls-r-name" name="name" maxlength="190" value="' . esc_attr( $name ) . '"> <label><input type="checkbox" name="shared" value="1"' . checked( $shared, true, false ) . '> ' . esc_html__( 'Shared with everyone who can view reports', 'subtleforms-license-server' ) . '</label></td></tr>';

		echo '<tr><th><label for="sfls-r-dataset">' . esc_html__( 'Dataset', 'subtleforms-license-server' ) . '</label></th><td><select id="sfls-r-dataset" name="dataset">';
		foreach ( SFLS_Report_Query::datasets() as $key => $ds ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $def['dataset'], $key, false ) . '>' . esc_html( $ds['label'] ) . '</option>';
		}
		echo '</select> <button class="button" type="submit" name="sfls_do" value="reload">' . esc_html__( 'Update fields', 'subtleforms-license-server' ) . '</button></td></tr>';

		$options = static function ( array $set, string $current, string $flag ): string {
			$html = '<option value="">—</option>';
			foreach ( $set as $key => $spec ) {
				if ( $spec[ $flag ] ) {
					$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $spec['label'] ) . '</option>';
				}
			}
			return $html;
		};

		echo '<tr><th>' . esc_html__( 'Group by', 'subtleforms-license-server' ) . '</th><td>';
		for ( $i = 0; $i < 2; $i++ ) {
			echo '<select name="group_by[]">' . $options( $fields, $def['group_by'][ $i ] ?? '', 'groupable' ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p class="description">' . esc_html__( 'Dates group by month.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Measures', 'subtleforms-license-server' ) . '</th><td>';
		$fns      = array(
			'count' => __( 'Count', 'subtleforms-license-server' ),
			'sum'   => __( 'Sum', 'subtleforms-license-server' ),
			'avg'   => __( 'Average', 'subtleforms-license-server' ),
		);
		$measures = empty( $def['measures'] ) ? array( array( 'fn' => 'count' ) ) : $def['measures'];
		for ( $i = 0; $i < 3; $i++ ) {
			$m = $measures[ $i ] ?? array();
			echo '<p><select name="measures[' . (int) $i . '][fn]"><option value="">—</option>';
			foreach ( $fns as $fn => $label ) {
				echo '<option value="' . esc_attr( $fn ) . '"' . selected( $m['fn'] ?? '', $fn, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <select name="measures[' . (int) $i . '][field]">' . $options( $fields, $m['field'] ?? '', 'measurable' ) . '</select></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Filters', 'subtleforms-license-server' ) . '</th><td>';
		$ops       = array(
			'eq'      => __( 'is', 'subtleforms-license-server' ),
			'in'      => __( 'is one of (comma separated)', 'subtleforms-license-server' ),
			'gt'      => __( 'greater than', 'subtleforms-license-server' ),
			'lt'      => __( 'less than', 'subtleforms-license-server' ),
			'before'  => __( 'before (YYYY-MM-DD)', 'subtleforms-license-server' ),
			'after'   => __( 'after (YYYY-MM-DD)', 'subtleforms-license-server' ),
			'between' => __( 'between (two values, comma separated)', 'subtleforms-license-server' ),
		);
		$filters   = $def['filters'];
		$filters[] = array();
		foreach ( $filters as $i => $f ) {
			echo '<p><select name="filters[' . (int) $i . '][field]">' . $options( $fields, $f['field'] ?? '', 'filterable' ) . '</select> <select name="filters[' . (int) $i . '][op]">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $ops as $op => $label ) {
				echo '<option value="' . esc_attr( $op ) . '"' . selected( $f['op'] ?? 'eq', $op, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <input type="text" name="filters[' . (int) $i . '][value]" value="' . esc_attr( (string) ( $f['value'] ?? '' ) ) . '"></p>';
		}
		echo '<p class="description">' . esc_html__( 'Clear the value to remove a filter. Save or preview to get another empty row.', 'subtleforms-license-server' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Date range', 'subtleforms-license-server' ) . '</th><td><input type="date" name="from" value="' . esc_attr( $def['from'] ) . '"> – <input type="date" name="to" value="' . esc_attr( $def['to'] ) . '"></td></tr>';

		echo '<tr><th>' . esc_html__( 'Show as', 'subtleforms-license-server' ) . '</th><td><select name="display">';
		foreach ( SFLS_Reports::DISPLAYS as $d ) {
			echo '<option value="' . esc_attr( $d ) . '"' . selected( $def['display'], $d, false ) . '>' . esc_html( ucfirst( $d ) ) . '</option>';
		}
		echo '</select> <input type="hidden" name="limit" value="' . (int) $def['limit'] . '"><input type="hidden" name="sort" value="' . esc_attr( $def['sort'] ) . '"></td></tr>';
		if ( current_user_can( 'manage_options' ) ) {
			$sched = $posted ? (string) ( $input['schedule'] ?? 'none' ) : ( $report['schedule'] ?? 'none' );
			echo '<tr><th>' . esc_html__( 'Email schedule', 'subtleforms-license-server' ) . '</th><td><select name="schedule">';
			foreach ( SFLS_Report_Schedule::SCHEDULES as $opt ) {
				echo '<option value="' . esc_attr( $opt ) . '"' . selected( $sched, $opt, false ) . '>' . esc_html( ucfirst( $opt ) ) . '</option>';
			}
			echo '</select> <input type="text" class="regular-text" name="recipients" placeholder="a@example.com, b@example.com" value="' . esc_attr( $posted ? (string) ( $input['recipients'] ?? '' ) : (string) ( $report['recipients'] ?? '' ) ) . '"><p class="description">' . esc_html__( 'Sent with the first 50 rows and a CSV attachment, once per week or month by the daily check.', 'subtleforms-license-server' ) . '</p></td></tr>';
		}
		echo '</table>';

		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<p class="submit"><button class="button" type="submit" name="sfls_do" value="preview">' . esc_html__( 'Preview', 'subtleforms-license-server' ) . '</button> ';
		echo '<button class="button button-primary" type="submit" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" name="action" value="sfls_report_save">' . esc_html__( 'Save report', 'subtleforms-license-server' ) . '</button></p>';
		// The save handler has its own nonce.
		echo wp_nonce_field( 'sfls_report_save', '_wpnonce', false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';

		if ( $posted && 'preview' === ( $input['sfls_do'] ?? '' ) ) {
			$result = SFLS_Report_Query::run( $def );
			echo '<div class="sfls-card"><h2>' . esc_html__( 'Preview', 'subtleforms-license-server' ) . '</h2>';
			$this->render_result( $result, $def['display'] );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Saved report view.
	 *
	 * @param array $report Report.
	 */
	private function screen_view( array $report ): void {
		$admin = new SFLS_Admin();
		$csv   = wp_nonce_url( admin_url( 'admin-post.php?action=sfls_report_csv&id=' . $report['id'] ), 'sfls_report_csv_' . $report['id'] );
		$admin->header(
			$report['name'],
			'<a class="page-title-action" href="' . esc_url( $csv ) . '">' . esc_html__( 'Export CSV', 'subtleforms-license-server' ) . '</a> <a class="page-title-action" href="' . esc_url( $this->url( array( 'action' => 'edit' ) + array( 'id' => $report['id'] ) ) ) . '">' . esc_html__( 'Edit', 'subtleforms-license-server' ) . '</a> <a class="page-title-action" href="' . esc_url( $this->url() ) . '">' . esc_html__( 'All reports', 'subtleforms-license-server' ) . '</a>'
		);
		echo '<div class="sfls-card">';
		$this->render_result( SFLS_Reports::run( $report ), (string) ( $report['definition']['display'] ?? 'table' ) );
		echo '</div></div>';
	}

	/**
	 * Standard report page: date range, chart, table, CSV, save as custom.
	 *
	 * @param string $key Report key.
	 */
	private function screen_standard( string $key ): void {
		$std           = (array) SFLS_Standard_Reports::find( $key );
		[ $from, $to ] = $this->range();
		$args          = array(
			'key'  => $key,
			'from' => $from,
			'to'   => $to,
		);
		$links         = '<a class="page-title-action" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'sfls_std_csv' ) + $args, admin_url( 'admin-post.php' ) ), 'sfls_std_csv_' . $key ) ) . '">' . esc_html__( 'Export CSV', 'subtleforms-license-server' ) . '</a>';
		if ( $std['def'] ) {
			$links .= ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'sfls_std_save' ) + $args, admin_url( 'admin-post.php' ) ), 'sfls_std_save_' . $key ) ) . '">' . esc_html__( 'Save as custom report', 'subtleforms-license-server' ) . '</a>';
		}
		$links .= ' <a class="page-title-action" href="' . esc_url( $this->url() ) . '">' . esc_html__( 'All reports', 'subtleforms-license-server' ) . '</a>';
		( new SFLS_Admin() )->header( $std['label'], $links );
		echo '<form method="get" class="sfls-card"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="action" value="standard"><input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
		echo '<input type="date" name="from" value="' . esc_attr( $from ) . '"> – <input type="date" name="to" value="' . esc_attr( $to ) . '"> <button class="button" type="submit">' . esc_html__( 'Apply', 'subtleforms-license-server' ) . '</button></form>';
		echo '<div class="sfls-card">';
		$this->render_result( SFLS_Standard_Reports::run( $key, $from, $to ), (string) ( $std['def']['display'] ?? 'bar' ) );
		echo '</div></div>';
	}

	/**
	 * Chart (if asked) and table of a result.
	 *
	 * @param array|WP_Error $result  Query result.
	 * @param string         $display table|bar|line|pie.
	 */
	private function render_result( $result, string $display ): void {
		if ( is_wp_error( $result ) ) {
			echo '<p class="notice notice-error inline"><span>' . esc_html( $result->get_error_message() ) . '</span></p>';
			return;
		}
		if ( ! $result['rows'] ) {
			echo '<p class="sfls-empty">' . esc_html__( 'No data for this report.', 'subtleforms-license-server' ) . '</p>';
			return;
		}
		$cols   = $result['columns'];
		$groups = array_values( array_filter( $cols, static fn( $c ) => ! self::is_measure( $c ) ) );
		$vals   = array_values( array_filter( $cols, static fn( $c ) => self::is_measure( $c ) ) );

		if ( 'table' !== $display && in_array( $display, SFLS_Reports::DISPLAYS, true ) && $vals ) {
			$labels = array();
			foreach ( $result['rows'] as $row ) {
				$parts = array();
				foreach ( $groups as $g ) {
					$parts[] = (string) $row[ $g['key'] ];
				}
				$labels[] = $parts ? implode( ' / ', $parts ) : __( 'Total', 'subtleforms-license-server' );
			}
			$series = array();
			foreach ( $vals as $v ) {
				$series[] = array(
					'name' => $v['label'],
					'data' => array_map( static fn( $row ) => $row[ $v['key'] ], $result['rows'] ),
				);
			}
			$chart = array(
				'type'     => $display,
				'labels'   => $labels,
				'series'   => $series,
				'format'   => 'money' === $vals[0]['type'] ? 'money' : 'number',
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			);
			if ( wp_script_is( 'sfls-charts', 'registered' ) ) {
				wp_enqueue_script( 'sfls-charts' );
			}
			echo '<div class="sfls-chart" data-chart="' . esc_attr( (string) wp_json_encode( $chart ) ) . '"></div>';
		}

		echo '<table class="widefat striped sfls-table"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $result['rows'] as $row ) {
			echo '<tr>';
			foreach ( $cols as $c ) {
				$v   = $row[ $c['key'] ];
				$dec = ( 'money' === $c['type'] || fmod( (float) $v, 1.0 ) > 0.0 ) ? 2 : 0;
				echo '<td>' . esc_html( is_numeric( $v ) && in_array( $c['type'], array( 'number', 'money' ), true ) ? number_format_i18n( (float) $v, $dec ) : (string) $v ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $result['total_rows'] > count( $result['rows'] ) ) {
			/* translators: 1: rows shown, 2: total rows */
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing %1$d of %2$d rows.', 'subtleforms-license-server' ), count( $result['rows'] ), $result['total_rows'] ) ) . '</p>';
		}
	}

	/**
	 * Whether a result column key is a measure.
	 *
	 * @param array $col Column.
	 */
	private static function is_measure( array $col ): bool {
		$key = (string) $col['key'];
		return ! empty( $col['measure'] ) || 'count' === $key || str_starts_with( $key, 'sum_' ) || str_starts_with( $key, 'avg_' );
	}
}
