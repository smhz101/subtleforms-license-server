<?php
/**
 * Signed guest links: a buyer who checked out without an account gets a
 * link to a page where they can download Pro and, once signed in, add the
 * license to an account. The link is an HMAC of the license (ID and key)
 * and an expiry with a secret of this site, valid for 30 days. It is sent
 * in the order emails and shown on the order received page of guest orders.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guest download and claim page.
 */
final class SFLS_Guest {

	/**
	 * How long a guest link works.
	 */
	const TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Option with the site secret for guest links.
	 */
	const SECRET_OPTION = 'sfls_link_secret';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 3 );
	}

	/**
	 * Site secret (created on first use).
	 */
	private static function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Signature of a guest link.
	 *
	 * @param array $lic     License.
	 * @param int   $expires Expiry timestamp.
	 */
	private static function sign( array $lic, int $expires ): string {
		return hash_hmac( 'sha256', 'guest|' . (int) $lic['id'] . '|' . strtoupper( (string) $lic['license_key'] ) . '|' . $expires, self::secret() );
	}

	/**
	 * Signed guest link for a license.
	 *
	 * @param array    $lic     License.
	 * @param int|null $expires Expiry timestamp (default: 30 days from now).
	 */
	public static function url( array $lic, ?int $expires = null ): string {
		$expires = $expires ?? time() + self::TTL;
		return add_query_arg(
			array(
				'sfls_guest' => (int) $lic['id'],
				'expires'    => $expires,
				'sig'        => self::sign( $lic, $expires ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The license a guest link stands for, or null when the link is wrong,
	 * changed or expired.
	 *
	 * @param int    $id      License ID.
	 * @param int    $expires Expiry timestamp.
	 * @param string $sig     Signature.
	 */
	public static function verify( int $id, int $expires, string $sig ): ?array {
		if ( $id <= 0 || $expires < time() || $expires > time() + self::TTL + HOUR_IN_SECONDS || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return null;
		}
		$lic = SFLS_Licenses::find( $id );
		if ( ! $lic || ! hash_equals( self::sign( $lic, $expires ), $sig ) ) {
			return null;
		}
		return $lic;
	}

	/**
	 * Whether an order was placed without an account.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function is_guest_order( WC_Order $order ): bool {
		return 0 === (int) $order->get_customer_id();
	}

	/**
	 * The release a guest link downloads: the latest stable release this
	 * license may download, or null.
	 *
	 * @param array $lic License.
	 */
	public static function release_for( array $lic ): ?array {
		$latest = SFLS_Releases::latest( 'stable' );
		if ( $latest && SFLS_Releases::has_package( $latest ) && SFLS_Releases::allowed( $lic, $latest ) ) {
			return $latest;
		}
		foreach ( SFLS_Releases::allowed_for( $lic, 'stable' ) as $release ) {
			if ( SFLS_Releases::has_package( $release ) ) {
				return $release;
			}
		}
		return null;
	}

	/**
	 * Add a license (and the other unclaimed licenses of the same order) to
	 * an account. Holding the signed link proves the buyer received the order
	 * email. Returns how many licenses were added.
	 *
	 * @param array   $lic  License.
	 * @param WP_User $user Signed-in account.
	 */
	public static function claim( array $lic, WP_User $user ): int {
		global $wpdb;
		if ( ! $user->ID ) {
			return 0;
		}
		$rows = array( $lic );
		if ( (int) $lic['order_id'] > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfls_licenses WHERE order_id = %d AND user_id = 0", (int) $lic['order_id'] ), ARRAY_A );
		}
		$count = 0;
		foreach ( $rows as $row ) {
			if ( SFLS_Claims::link( $row, $user ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The guest page: ?sfls_guest=ID&expires&sig, with &download=1 for the
	 * zip and a POST to add the license to the signed-in account.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The signature is the credential.
		if ( ! isset( $_GET['sfls_guest'] ) ) {
			return;
		}
		$id      = absint( $_GET['sfls_guest'] );
		$expires = absint( $_GET['expires'] ?? 0 );
		$sig     = strtolower( sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) ) );
		$get_zip = ! empty( $_GET['download'] );
		// phpcs:enable

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$lic = self::verify( $id, $expires, $sig );
		if ( ! $lic ) {
			status_header( 403 );
			SFLS_Connect::standalone_page(
				__( 'This link has expired', 'subtleforms-license-server' ),
				static function () {
					echo '<h1>' . esc_html__( 'This link has expired', 'subtleforms-license-server' ) . '</h1>';
					echo '<p class="sfls-connect-lead">' . esc_html__( 'Download links in order emails work for 30 days. Contact support with your order number and we will send a new one.', 'subtleforms-license-server' ) . '</p>';
				}
			);
			exit;
		}
		$self = self::url( $lic, $expires );

		if ( 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) && is_user_logged_in() ) {
			check_admin_referer( 'sfls_guest_claim_' . $lic['id'] );
			$count = self::claim( $lic, wp_get_current_user() );
			if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
				wc_add_notice(
					$count
						/* translators: %d: number of licenses */
						? sprintf( _n( '%d license was added to your account.', '%d licenses were added to your account.', $count, 'subtleforms-license-server' ), $count )
						: __( 'This license is already in an account.', 'subtleforms-license-server' ),
					$count ? 'success' : 'notice'
				);
			}
			wp_safe_redirect( SFLS_Licenses::manage_url() );
			exit;
		}

		if ( $get_zip ) {
			$release = self::release_for( $lic );
			if ( $release ) {
				wp_safe_redirect( SFLS_Releases::signed_url( (string) $lic['license_key'], 'guest', (string) $release['version'] ) );
				exit;
			}
		}

		status_header( 200 );
		SFLS_Connect::standalone_page(
			__( 'Your SubtleForms Pro license', 'subtleforms-license-server' ),
			static function () use ( $lic, $self ) {
				echo self::page_html( $lic, $self ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in page_html().
			}
		);
		exit;
	}

	/**
	 * Card content of the guest page.
	 *
	 * @param array  $lic  License.
	 * @param string $self This page's signed URL.
	 */
	public static function page_html( array $lic, string $self ): string {
		$user    = wp_get_current_user();
		$release = self::release_for( $lic );
		$html    = '<h1>' . esc_html__( 'Your SubtleForms Pro license', 'subtleforms-license-server' ) . '</h1>';
		$html   .= '<p class="sfls-connect-lead">' . esc_html( SFLS_Delivery::license_line( $lic ) ) . '</p>';
		$html   .= '<div class="sfls-keyrow"><code class="sfls-key">' . esc_html( (string) $lic['license_key'] ) . '</code></div>';
		$html   .= '<div class="sfls-actions">';
		if ( $release ) {
			$html .= '<a class="sfls-btn sfls-btn--primary" href="' . esc_url( add_query_arg( 'download', 1, $self ) ) . '">' . esc_html(
				/* translators: %s: version */
				sprintf( __( 'Download Pro %s', 'subtleforms-license-server' ), $release['version'] )
			) . '</a>';
		}
		$html .= '</div>';
		if ( ! $release ) {
			$html .= '<p class="sfls-muted">' . esc_html__( 'The Pro download appears here as soon as a release is available for this license.', 'subtleforms-license-server' ) . '</p>';
		}

		if ( 0 === (int) $lic['user_id'] ) {
			if ( $user->ID ) {
				$html .= '<form method="post" action="' . esc_url( $self ) . '">' . wp_nonce_field( 'sfls_guest_claim_' . $lic['id'], '_wpnonce', true, false );
				$html .= '<p class="sfls-muted">' . esc_html(
					sprintf(
						/* translators: %s: email address */
						__( 'Add this license to the account %s to manage sites and renewals there.', 'subtleforms-license-server' ),
						$user->user_email
					)
				) . '</p><button type="submit" class="sfls-btn sfls-btn--secondary">' . esc_html__( 'Add to my account', 'subtleforms-license-server' ) . '</button></form>';
			} else {
				$html .= '<p class="sfls-muted">' . esc_html__( 'You do not need an account to use Pro. To manage sites and renewals online, sign in and add the license to your account.', 'subtleforms-license-server' ) . ' <a href="' . esc_url( SFLS_Connect::login_url( $self ) ) . '">' . esc_html__( 'Sign in', 'subtleforms-license-server' ) . '</a></p>';
			}
		} elseif ( (int) $lic['user_id'] === (int) $user->ID ) {
			$html .= '<p class="sfls-muted"><a href="' . esc_url( SFLS_Licenses::manage_url() ) . '">' . esc_html__( 'This license is in your account.', 'subtleforms-license-server' ) . '</a></p>';
		}
		return $html . SFLS_Delivery::steps_html( null !== $release );
	}
}
