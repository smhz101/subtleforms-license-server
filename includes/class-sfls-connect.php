<?php
/**
 * Account connection flow used by SubtleForms Pro.
 *
 *   1. Pro sends the admin to /connect-site/?site&return&state&name&v
 *   2. The customer signs in (WooCommerce My Account) and picks a license.
 *   3. The domain is activated on that license and the browser goes back to
 *      `return` with ?sfls_code=…&state=… (or ?sfls_error=cancelled&state=…).
 *   4. Pro swaps the one-time code for the key at POST /sfls/v1/connect/exchange.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * /connect-site/ route.
 */
final class SFLS_Connect {

	const QUERY_VAR = 'sfls_connect';
	const CODE_TTL  = 10 * MINUTE_IN_SECONDS;

	/**
	 * Error shown above the license list after a failed POST.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_handle' ), 0 );

		// Send people back here after signing in.
		add_filter( 'woocommerce_login_redirect', array( $this, 'login_redirect' ), 20 );
		add_filter( 'woocommerce_registration_redirect', array( $this, 'login_redirect' ), 20 );
		add_action( 'woocommerce_login_form_end', array( $this, 'login_hidden_field' ) );
		add_action( 'woocommerce_register_form_end', array( $this, 'login_hidden_field' ) );
		add_action( 'woocommerce_before_customer_login_form', array( $this, 'login_notice' ) );
	}

	/**
	 * Rewrite rule for /connect-site/.
	 */
	public static function rewrite(): void {
		add_rewrite_rule( '^connect-site/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Public URL of the connect page.
	 *
	 * @param array $args Query args (raw values, they are encoded here).
	 */
	public static function url( array $args = array() ): string {
		$base = get_option( 'permalink_structure' ) ? home_url( '/connect-site/' ) : add_query_arg( self::QUERY_VAR, 1, home_url( '/' ) );
		return add_query_arg( array_map( 'rawurlencode', array_filter( $args, 'strlen' ) ), $base );
	}

	// ── Request ─────────────────────────────────────────────────────────

	/**
	 * Take over the request when it is for /connect-site/.
	 *
	 * @param WP $wp Current request.
	 */
	public function maybe_handle( WP $wp ): void {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->handle();
		exit;
	}

	/**
	 * The arguments Pro sent, sanitized.
	 *
	 * @return array{site:string,return:string,state:string,name:string,v:string}
	 */
	private function args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing parameters; the POST is nonce-checked.
		$get = static fn( string $key ): string => isset( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per field.
		// phpcs:enable
		return array(
			'site'   => esc_url_raw( $get( 'site' ), array( 'http', 'https' ) ),
			'return' => esc_url_raw( $get( 'return' ), array( 'http', 'https' ) ),
			'state'  => substr( sanitize_text_field( $get( 'state' ) ), 0, 255 ),
			'name'   => substr( sanitize_text_field( $get( 'name' ) ), 0, 100 ),
			'v'      => substr( sanitize_text_field( $get( 'v' ) ), 0, 20 ),
		);
	}

	/**
	 * Why the arguments are unusable, or '' when they are fine.
	 *
	 * @param array $args Sanitized args.
	 */
	private function invalid_reason( array $args ): string {
		if ( '' === $args['site'] || '' === $args['return'] ) {
			return __( 'This link is missing information. Start the connection again from SubtleForms in your WordPress admin.', 'subtleforms-license-server' );
		}
		$scheme = strtolower( (string) wp_parse_url( $args['return'], PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return __( 'The return address is not a web address.', 'subtleforms-license-server' );
		}
		$site_host   = SFLS_Licenses::normalize_domain( $args['site'] );
		$return_host = SFLS_Licenses::normalize_domain( $args['return'] );
		if ( '' === $site_host || $site_host !== $return_host ) {
			return __( 'The return address does not belong to the site being connected. For your safety the connection was stopped.', 'subtleforms-license-server' );
		}
		return '';
	}

	/**
	 * Route the request.
	 */
	private function handle(): void {
		$args = $this->args();

		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );

		$reason = $this->invalid_reason( $args );
		if ( '' !== $reason ) {
			status_header( 400 );
			$this->render_error( $reason );
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( self::login_url( self::url( $args ) ) );
			return;
		}

		status_header( 200 );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			$this->process( $args );
			if ( '' === $this->error ) {
				return; // Redirected.
			}
		}

		$this->render_picker( $args );
	}

	/**
	 * Sign-in URL that comes back to $back.
	 *
	 * @param string $back Connect URL.
	 */
	public static function login_url( string $back ): string {
		if ( function_exists( 'wc_get_page_permalink' ) && wc_get_page_id( 'myaccount' ) > 0 ) {
			return add_query_arg( 'sfls_return', rawurlencode( $back ), wc_get_page_permalink( 'myaccount' ) );
		}
		return wp_login_url( $back );
	}

	/**
	 * Handle the license choice.
	 *
	 * @param array $args Sanitized args.
	 */
	private function process( array $args ): void {
		$nonce = isset( $_POST['_sfls_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfls_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sfls_connect' ) ) {
			$this->error = __( 'Your session expired. Choose the license again.', 'subtleforms-license-server' );
			return;
		}

		$user = wp_get_current_user();
		$lic  = SFLS_Licenses::find( absint( $_POST['license_id'] ?? 0 ) );
		if ( ! $lic || ! SFLS_Licenses::user_owns( $lic, $user ) ) {
			$this->error = __( 'Choose one of your licenses.', 'subtleforms-license-server' );
			return;
		}

		$domain = SFLS_Licenses::normalize_domain( $args['site'] );
		$result = SFLS_Licenses::activate_domain( $lic, $domain, $args['v'] );
		if ( is_wp_error( $result ) ) {
			$this->error = $result->get_error_message();
			return;
		}

		if ( 0 === (int) $lic['user_id'] ) {
			SFLS_Licenses::update( (int) $lic['id'], array( 'user_id' => (int) $user->ID ) ); // Claim a key bought as a guest.
		}

		$code = wp_generate_password( 40, false );
		set_transient(
			'sfls_code_' . hash( 'sha256', $code ),
			array(
				'license_id' => (int) $lic['id'],
				'domain'     => $domain,
				'user_id'    => (int) $user->ID,
			),
			self::CODE_TTL
		);

		$this->redirect_back(
			$args,
			array(
				'sfls_code' => $code,
				'state'     => $args['state'],
			)
		);
	}

	/**
	 * Redirect to the site's return URL, allowing only that one host.
	 *
	 * @param array $args  Sanitized args.
	 * @param array $query Query args to append.
	 */
	private function redirect_back( array $args, array $query ): void {
		$host  = (string) wp_parse_url( $args['return'], PHP_URL_HOST );
		$allow = static function ( $hosts ) use ( $host ) {
			$hosts[] = $host;
			$hosts[] = strtolower( $host );
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow );
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $query ), $args['return'] ), 302, 'SubtleForms' );
		remove_filter( 'allowed_redirect_hosts', $allow );
		exit;
	}

	/**
	 * Cancel link back to the site.
	 *
	 * @param array $args Sanitized args.
	 */
	private function cancel_url( array $args ): string {
		return add_query_arg(
			array(
				'sfls_error' => 'cancelled',
				'state'      => rawurlencode( $args['state'] ),
			),
			$args['return']
		);
	}

	/**
	 * Read and delete a one-time code. Returns null when unknown or already used.
	 *
	 * @param string $code Code from the redirect.
	 * @return array{license_id:int,domain:string,user_id:int}|null
	 */
	public static function consume_code( string $code ): ?array {
		$key  = 'sfls_code_' . hash( 'sha256', $code );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! delete_transient( $key ) ) {
			return null;
		}
		return $data;
	}

	// ── Login round trip ────────────────────────────────────────────────

	/**
	 * The validated connect URL carried through the login form, or ''.
	 */
	private function carried_return(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Only used as a redirect target, validated to this site.
		$raw = '';
		if ( isset( $_REQUEST['sfls_return'] ) ) {
			$raw = sanitize_url( wp_unslash( $_REQUEST['sfls_return'] ) );
		} elseif ( isset( $_REQUEST['redirect_to'] ) ) {
			$raw = sanitize_url( wp_unslash( $_REQUEST['redirect_to'] ) );
		}
		// phpcs:enable
		if ( '' === $raw ) {
			return '';
		}
		$valid = wp_validate_redirect( $raw, '' );
		return ( '' !== $valid && wp_parse_url( $valid, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) ? $valid : '';
	}

	/**
	 * Send the customer back to the connect page after login / registration.
	 *
	 * @param string $redirect Default redirect.
	 */
	public function login_redirect( $redirect ) {
		$back = $this->carried_return();
		return '' !== $back ? $back : $redirect;
	}

	/**
	 * Carry the connect URL in the WooCommerce login form.
	 */
	public function login_hidden_field(): void {
		$back = $this->carried_return();
		if ( '' !== $back ) {
			echo '<input type="hidden" name="redirect" value="' . esc_url( $back ) . '">';
		}
	}

	/**
	 * Explain why the customer is asked to sign in.
	 */
	public function login_notice(): void {
		$back = $this->carried_return();
		if ( '' === $back || ( ! str_contains( $back, 'connect-site' ) && ! str_contains( $back, self::QUERY_VAR ) ) ) {
			return;
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $query );
		$site = SFLS_Licenses::normalize_domain( (string) ( $query['site'] ?? '' ) );
		wp_enqueue_style( 'sfls-front' );
		echo '<div class="sfls sfls-login-intro"><span class="sfls-logo" aria-hidden="true">' . self::logo_svg() . '</span><div><strong>' . esc_html__( 'Sign in to connect your site', 'subtleforms-license-server' ) . '</strong><p>' . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
			esc_html(
				'' !== $site
					/* translators: %s: domain */
					? sprintf( __( 'Use the account you bought SubtleForms Pro with. You will then choose the license for %s.', 'subtleforms-license-server' ), $site )
					: __( 'Use the account you bought SubtleForms Pro with.', 'subtleforms-license-server' )
			) . '</p></div></div>';
	}

	// ── Views ───────────────────────────────────────────────────────────

	/**
	 * Logo mark: rounded dark square with three white bars.
	 */
	public static function logo_svg(): string {
		return '<svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" focusable="false"><rect width="32" height="32" rx="8" fill="#1e1e1e"/><rect x="8" y="9" width="16" height="3" rx="1.5" fill="#fff"/><rect x="8" y="14.5" width="16" height="3" rx="1.5" fill="#fff"/><rect x="8" y="20" width="10" height="3" rx="1.5" fill="#fff"/></svg>';
	}

	/**
	 * Is the license usable for this domain? Returns '' or the reason it is not.
	 *
	 * @param array  $lic    License.
	 * @param string $domain Domain being connected.
	 */
	public static function unusable_reason( array $lic, string $domain ): string {
		$state = SFLS_Licenses::state( $lic );
		if ( 'suspended' === $state['status'] ) {
			return __( 'This license is suspended. Contact support to restore it.', 'subtleforms-license-server' );
		}
		if ( ! $state['valid'] ) {
			return __( 'This license has expired. Renew it to connect sites.', 'subtleforms-license-server' );
		}
		if ( SFLS_Licenses::activation( (int) $lic['id'], $domain ) || SFLS_Licenses::is_local_domain( $domain ) ) {
			return '';
		}
		if ( ! SFLS_Licenses::has_free_slot( $lic ) ) {
			return __( 'Every site on this license is in use. Disconnect one in your account, or upgrade.', 'subtleforms-license-server' );
		}
		return '';
	}

	/**
	 * Standalone page shell.
	 *
	 * @param string   $title Document title.
	 * @param callable $body  Prints the card content.
	 */
	private function shell( string $title, callable $body ): void {
		wp_register_style( 'sfls-front', SFLS_URL . 'assets/css/sfls-front.css', array(), SFLS_VERSION );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' | ' . get_bloginfo( 'name' ) ); ?></title>
		<?php wp_print_styles( array( 'sfls-front' ) ); ?>
<style>
html,body{margin:0;padding:0}
body.sfls-connect-body{background:#f0f0f1;color:#1e1e1e;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;font-size:14px;line-height:1.5;min-height:100vh;display:flex;flex-direction:column;align-items:center;-webkit-font-smoothing:antialiased}
.sfls-connect{width:100%;max-width:560px;box-sizing:border-box;padding:48px 16px 32px}
.sfls-connect-brand{display:flex;align-items:center;gap:10px;margin:0 0 20px;font-weight:600;font-size:15px;color:#1e1e1e}
.sfls-connect-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 4px 16px rgba(0,0,0,.04);padding:28px}
.sfls-connect-card h1{font-size:22px;line-height:1.3;margin:0 0 6px;font-weight:600;letter-spacing:-.01em}
.sfls-connect-lead{margin:0 0 20px;color:#50575e}
.sfls-connect-foot{margin:18px 4px 0;color:#646970;font-size:13px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sfls-connect-foot a{color:inherit}
@media (max-width:480px){.sfls-connect{padding-top:24px}.sfls-connect-card{padding:20px}.sfls-connect-card h1{font-size:19px}}
</style>
</head>
<body class="sfls-connect-body">
<main class="sfls sfls-connect">
	<div class="sfls-connect-brand"><?php echo self::logo_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span><?php esc_html_e( 'SubtleForms', 'subtleforms-license-server' ); ?></span></div>
	<div class="sfls-connect-card">
		<?php $body(); ?>
	</div>
	<?php $this->footer(); ?>
</main>
</body>
</html>
		<?php
	}

	/**
	 * Account line under the card.
	 */
	private function footer(): void {
		$support = SFLS_Settings::support_email();
		echo '<div class="sfls-connect-foot">';
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			echo '<span>';
			printf(
				/* translators: %s: email address */
				esc_html__( 'Signed in as %s.', 'subtleforms-license-server' ),
				'<strong>' . esc_html( $user->user_email ) . '</strong>'
			);
			echo ' <a href="' . esc_url( wp_logout_url( self::url( $this->args() ) ) ) . '">' . esc_html__( 'Switch account', 'subtleforms-license-server' ) . '</a></span>';
		} else {
			echo '<span></span>';
		}
		if ( $support ) {
			echo '<a href="' . esc_url( 'mailto:' . $support ) . '">' . esc_html__( 'Need help?', 'subtleforms-license-server' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Error page.
	 *
	 * @param string $message Message.
	 */
	private function render_error( string $message ): void {
		$this->shell(
			__( 'Cannot connect this site', 'subtleforms-license-server' ),
			static function () use ( $message ) {
				echo '<div class="sfls-connect-icon sfls-connect-icon--error" aria-hidden="true">!</div>';
				echo '<h1>' . esc_html__( 'Cannot connect this site', 'subtleforms-license-server' ) . '</h1>';
				echo '<p class="sfls-connect-lead">' . esc_html( $message ) . '</p>';
				echo '<div class="sfls-actions"><a class="sfls-btn sfls-btn--secondary" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Go to SubtleForms', 'subtleforms-license-server' ) . '</a></div>';
			}
		);
	}

	/**
	 * License picker.
	 *
	 * @param array $args Sanitized args.
	 */
	private function render_picker( array $args ): void {
		$user     = wp_get_current_user();
		$domain   = SFLS_Licenses::normalize_domain( $args['site'] );
		$name     = '' !== $args['name'] ? $args['name'] : $domain;
		$licenses = SFLS_Licenses::for_user( $user );
		$error    = $this->error;
		/* translators: %s: site name */
		$title = sprintf( __( 'Connect %s', 'subtleforms-license-server' ), $name );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only re-selects the previous choice after a failed, nonce-checked POST.
		$posted = absint( $_POST['license_id'] ?? 0 );

		$this->shell(
			$title,
			function () use ( $args, $domain, $licenses, $error, $title, $posted ) {
				echo '<h1>' . esc_html( $title ) . '</h1>';
				echo '<p class="sfls-connect-lead">';
				if ( $licenses ) {
					esc_html_e( 'Choose the SubtleForms Pro license to use on this site.', 'subtleforms-license-server' );
				} else {
					esc_html_e( 'Connect a site to your SubtleForms account to unlock Pro features and updates.', 'subtleforms-license-server' );
				}
				echo '</p>';
				echo '<div class="sfls-domain"><span class="sfls-domain-dot" aria-hidden="true"></span><span>' . esc_html( $domain ) . '</span>';
				if ( '' !== $args['v'] ) {
					/* translators: %s: version */
					echo '<span class="sfls-domain-meta">' . esc_html( sprintf( __( 'Pro %s', 'subtleforms-license-server' ), $args['v'] ) ) . '</span>';
				}
				echo '</div>';

				if ( '' !== $error ) {
					echo '<div class="sfls-alert sfls-alert--error" role="alert">' . esc_html( $error ) . '</div>';
				}

				if ( ! $licenses ) {
					echo '<div class="sfls-empty"><p><strong>' . esc_html__( 'You do not have a SubtleForms Pro license yet.', 'subtleforms-license-server' ) . '</strong></p><p>' . esc_html__( 'Pick a plan, then come back here to connect your site in one click.', 'subtleforms-license-server' ) . '</p></div>';
					echo '<div class="sfls-actions">';
					echo '<a class="sfls-btn sfls-btn--secondary" href="' . esc_url( $this->cancel_url( $args ) ) . '">' . esc_html__( 'Cancel', 'subtleforms-license-server' ) . '</a>';
					echo '<a class="sfls-btn sfls-btn--primary" href="' . esc_url( SFLS_Settings::pricing_url() ) . '">' . esc_html__( 'See plans', 'subtleforms-license-server' ) . '</a>';
					echo '</div>';
					return;
				}

				$selected = 0;
				foreach ( $licenses as $lic ) {
					if ( '' === self::unusable_reason( $lic, $domain ) && ( ! $selected || (int) $lic['id'] === $posted ) ) {
						$selected = (int) $lic['id'];
					}
				}

				echo '<form method="post" action="' . esc_url( self::url( $args ) ) . '">';
				wp_nonce_field( 'sfls_connect', '_sfls_nonce', false );
				echo '<fieldset class="sfls-choices"><legend class="screen-reader-text">' . esc_html__( 'License', 'subtleforms-license-server' ) . '</legend>';
				foreach ( $licenses as $lic ) {
					$reason   = self::unusable_reason( $lic, $domain );
					$state    = SFLS_Licenses::state( $lic );
					$disabled = '' !== $reason;
					$here     = (bool) SFLS_Licenses::activation( (int) $lic['id'], $domain );
					$id       = 'sfls-lic-' . (int) $lic['id'];
					echo '<label class="sfls-choice' . ( $disabled ? ' is-disabled' : '' ) . '" for="' . esc_attr( $id ) . '">';
					echo '<input type="radio" name="license_id" id="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $lic['id'] ) . '"' . checked( $selected, (int) $lic['id'], false ) . disabled( $disabled, true, false ) . '>';
					echo '<span class="sfls-choice-body">';
					echo '<span class="sfls-choice-top"><span class="sfls-choice-plan">' . esc_html( SFLS_Plans::label( $lic['plan'] ) ) . '</span>' . self::pill( $state['status'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill() escapes.
					echo '<span class="sfls-choice-key">' . esc_html( SFLS_Licenses::mask_key( $lic['license_key'] ) ) . '</span>';
					echo '<span class="sfls-choice-meta"><span>' . esc_html( self::expiry_phrase( $lic ) ) . '</span><span>' . esc_html( SFLS_Licenses::usage_label( $lic ) ) . '</span></span>';
					if ( $disabled ) {
						echo '<span class="sfls-choice-note sfls-choice-note--warn">' . esc_html( $reason ) . '</span>';
					} elseif ( $here ) {
						echo '<span class="sfls-choice-note">' . esc_html__( 'Already active on this site.', 'subtleforms-license-server' ) . '</span>';
					}
					echo '</span><span class="sfls-choice-check" aria-hidden="true"></span></label>';
				}
				echo '</fieldset>';
				echo '<div class="sfls-actions">';
				echo '<a class="sfls-btn sfls-btn--secondary" href="' . esc_url( $this->cancel_url( $args ) ) . '">' . esc_html__( 'Cancel', 'subtleforms-license-server' ) . '</a>';
				echo '<button type="submit" class="sfls-btn sfls-btn--primary"' . disabled( 0, $selected, false ) . '>' . esc_html__( 'Connect site', 'subtleforms-license-server' ) . '</button>';
				echo '</div>';
				echo '<p class="sfls-fineprint">' . esc_html(
					sprintf(
						/* translators: %s: domain */
						__( 'Connecting activates SubtleForms Pro on %s and sends the license key to that site over a secure one-time code.', 'subtleforms-license-server' ),
						$domain
					)
				) . '</p>';
				echo '</form>';
			}
		);
	}

	/**
	 * "Expires …", "Expired …" or "Lifetime license".
	 *
	 * @param array $lic License.
	 */
	public static function expiry_phrase( array $lic ): string {
		if ( empty( $lic['expires_at'] ) ) {
			return __( 'Lifetime license', 'subtleforms-license-server' );
		}
		$past = strtotime( $lic['expires_at'] . ' UTC' ) <= time();
		return sprintf(
			/* translators: %s: date */
			$past ? __( 'Expired %s', 'subtleforms-license-server' ) : __( 'Expires %s', 'subtleforms-license-server' ),
			SFLS_Licenses::expiry_label( $lic )
		);
	}

	/**
	 * Status pill HTML.
	 *
	 * @param string $status State slug.
	 */
	public static function pill( string $status ): string {
		$tone = array(
			'valid'        => 'ok',
			'grace_period' => 'warn',
			'expired'      => 'bad',
			'suspended'    => 'bad',
		);
		return '<span class="sfls-pill sfls-pill--' . esc_attr( $tone[ $status ] ?? 'neutral' ) . '">' . esc_html( SFLS_Licenses::status_label( $status ) ) . '</span>';
	}
}
