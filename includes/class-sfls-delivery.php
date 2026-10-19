<?php
/**
 * Delivering a license after purchase: the order received page, the order
 * page in My Account and the order emails show the key, a Download Pro button
 * and three install steps. License emails use the support address as
 * Reply-To (and as From when it is on the site's own domain).
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Key delivery and license email sender.
 */
final class SFLS_Delivery {

	/**
	 * Orders whose panel was already printed in this request.
	 *
	 * @var array<int,bool>
	 */
	private static array $shown = array();

	/**
	 * True while the plugin sends one of its own emails (reminders).
	 *
	 * @var bool
	 */
	private static bool $own_mail = false;

	/**
	 * Hook in when WooCommerce is loaded.
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) && ! did_action( 'woocommerce_loaded' ) ) {
			add_action( 'woocommerce_loaded', array( $this, 'hooks' ) );
			return;
		}
		$this->hooks();
	}

	/**
	 * WooCommerce hooks.
	 */
	public function hooks(): void {
		// Block theme order confirmation: right under "Thank you".
		add_filter( 'render_block_woocommerce/order-confirmation-status', array( $this, 'after_status_block' ), 10, 2 );
		// Classic thank-you template, before the order table.
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou' ), 5 );
		// My Account › Orders › View.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_page' ) );

		add_action( 'woocommerce_email_after_order_table', array( $this, 'email' ), 10, 4 );
		add_filter( 'woocommerce_email_headers', array( $this, 'email_headers' ), 20, 4 );
		add_filter( 'woocommerce_email_from_address', array( $this, 'email_from' ), 20, 2 );
	}

	// ── Shared pieces ───────────────────────────────────────────────────

	/**
	 * The three install steps: title => text.
	 *
	 * @param bool $button A Download Pro button is shown with the steps. When
	 *                     not, the first step says where the zip is (UX2-30).
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function steps( bool $button = true ): array {
		return array(
			array(
				__( 'Download the zip', 'subtleforms-license-server' ),
				$button
					? __( 'Use the Download Pro button. Keep the file zipped.', 'subtleforms-license-server' )
					: __( 'Download it from your license page or My Account › Pro downloads. Keep the file zipped.', 'subtleforms-license-server' ),
			),
			array(
				__( 'Upload it to WordPress', 'subtleforms-license-server' ),
				__( 'Go to Plugins › Add New › Upload Plugin, choose the zip, click Install Now and then Activate.', 'subtleforms-license-server' ),
			),
			array(
				__( 'Connect your account', 'subtleforms-license-server' ),
				__( 'Open SubtleForms › Settings › License and click Connect account. You can also paste your license key there.', 'subtleforms-license-server' ),
			),
		);
	}

	/**
	 * "How to install Pro" panel HTML for the front end.
	 *
	 * @param bool $button A Download Pro button is shown with the steps.
	 */
	public static function steps_html( bool $button = true ): string {
		$html = '<div class="sfls-install"><h3 class="sfls-install-title">' . esc_html__( 'How to install Pro', 'subtleforms-license-server' ) . '</h3><ol class="sfls-steps">';
		foreach ( self::steps( $button ) as $i => $step ) {
			$html .= '<li class="sfls-step"><span class="sfls-step-num" aria-hidden="true">' . esc_html( (string) ( $i + 1 ) ) . '</span><span class="sfls-step-body"><strong>' . esc_html( $step[0] ) . '</strong><span>' . esc_html( $step[1] ) . '</span></span></li>';
		}
		return $html . '</ol></div>';
	}

	/**
	 * Where Download Pro goes. Orders placed without an account get the signed
	 * guest link (SFLS_Guest), which works without signing in: the guest page
	 * in emails, the zip itself on the order received page. Otherwise, in
	 * emails or for visitors who are not signed in, that is My Account › Pro
	 * downloads; signed-in customers with an active license get the direct
	 * download. '' while no release exists.
	 *
	 * @param bool          $for_email Link for an email.
	 * @param WC_Order|null $order     Order the link is for.
	 */
	public static function download_link( bool $for_email = false, ?WC_Order $order = null ): string {
		if ( ! SFLS_Releases::has_package() ) {
			return '';
		}
		$guest = $order ? self::guest_link( $order ) : '';
		if ( '' !== $guest ) {
			return $for_email ? $guest : add_query_arg( 'download', 1, $guest );
		}
		if ( ! $for_email && is_user_logged_in() && SFLS_Account::can_download( wp_get_current_user() ) ) {
			return SFLS_Account::download_url();
		}
		return wc_get_account_endpoint_url( SFLS_Account::EP_DOWNLOADS );
	}

	/**
	 * Signed guest page link of an order placed without an account ('' for
	 * orders of customers with an account, or before a key exists).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function guest_link( WC_Order $order ): string {
		if ( ! SFLS_Guest::is_guest_order( $order ) ) {
			return '';
		}
		$licenses = SFLS_Woo::order_licenses( $order->get_id() );
		return $licenses ? SFLS_Guest::url( $licenses[0] ) : '';
	}

	/**
	 * "Pro plan · 5 sites · renews 11 October 2027".
	 *
	 * @param array $lic License.
	 */
	public static function license_line( array $lic ): string {
		$parts = array(
			/* translators: %s: plan name */
			sprintf( __( '%s plan', 'subtleforms-license-server' ), SFLS_Plans::label( $lic['plan'] ) ),
			SFLS_Plans::sites_label( (int) $lic['sites_allowed'] ),
			$lic['expires_at']
				/* translators: %s: date */
				? sprintf( __( 'renews %s', 'subtleforms-license-server' ), SFLS_Licenses::expiry_label( $lic ) )
				: __( 'lifetime', 'subtleforms-license-server' ),
		);
		return implode( ' · ', $parts );
	}

	/**
	 * Email licenses issued by hand to the customer: the keys, the plan, a
	 * signed link to the license page and download (30 days, no account
	 * needed), and the install steps (LS-18).
	 *
	 * @param array[] $licenses Licenses.
	 * @param string  $to       Recipient.
	 */
	public static function email_issued( array $licenses, string $to ): bool {
		$licenses = array_values( array_filter( $licenses ) );
		if ( ! $licenses || ! is_email( $to ) ) {
			return false;
		}
		$many    = count( $licenses ) > 1;
		$subject = $many ? __( 'Your SubtleForms Pro license keys', 'subtleforms-license-server' ) : __( 'Your SubtleForms Pro license key', 'subtleforms-license-server' );
		$body    = '<p>' . esc_html__( 'Hi,', 'subtleforms-license-server' ) . '</p>';
		$body   .= '<p>' . esc_html( $many ? __( 'Here are your SubtleForms Pro license keys.', 'subtleforms-license-server' ) : __( 'Here is your SubtleForms Pro license key.', 'subtleforms-license-server' ) ) . '</p>';
		$body   .= '<table cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;margin:16px 0;border:1px solid #e5e5e5;">';
		foreach ( $licenses as $lic ) {
			$body .= '<tr><td style="border-bottom:1px solid #e5e5e5;"><code style="font-size:15px;">' . esc_html( $lic['license_key'] ) . '</code><br><span style="color:#757575;">' . esc_html( self::license_line( $lic ) ) . '</span></td></tr>';
		}
		$body .= '</table>';
		$body .= SFLS_Reminders::button( SFLS_Guest::url( $licenses[0] ), __( 'Open your license page', 'subtleforms-license-server' ) );
		$body .= '<p>' . esc_html__( 'The link opens your license page and the Pro download for 30 days, no account needed.', 'subtleforms-license-server' ) . '</p>';
		$body .= '<h3>' . esc_html__( 'How to install Pro', 'subtleforms-license-server' ) . '</h3><ol>';
		foreach ( self::steps( false ) as $step ) {
			$body .= '<li><strong>' . esc_html( $step[0] ) . '</strong> ' . esc_html( $step[1] ) . '</li>';
		}
		$body .= '</ol>';
		$body .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: support email */
				__( 'Questions? Reply to this email or write to %s.', 'subtleforms-license-server' ),
				SFLS_Settings::support_email()
			)
		) . '</p>';
		return SFLS_Reminders::deliver( $to, $subject, $subject, $body );
	}

	/**
	 * What the order panel says before the license is ready. A renewal or
	 * upgrade keeps its key, so it never promises a new one (LS-11).
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $unpaid The customer left the payment unfinished.
	 */
	public static function waiting_text( WC_Order $order, bool $unpaid ): string {
		$kind = SFLS_Woo::order_kind( $order );
		if ( 'renewal' === $kind ) {
			return $unpaid
				? __( 'This order is waiting for payment. Your license is renewed once it is paid, with the same key.', 'subtleforms-license-server' )
				: __( 'Your license is renewed as soon as your payment is confirmed. Your key stays the same, and we email you when it is done.', 'subtleforms-license-server' );
		}
		if ( 'upgrade' === $kind ) {
			return $unpaid
				? __( 'This order is waiting for payment. Your license is upgraded once it is paid, with the same key.', 'subtleforms-license-server' )
				: __( 'Your license is upgraded as soon as your payment is confirmed. Your key stays the same, and we email you when it is done.', 'subtleforms-license-server' );
		}
		return $unpaid
			? __( 'This order is waiting for payment. Your license key appears here once it is paid.', 'subtleforms-license-server' )
			: __( 'Your license key appears here as soon as your payment is confirmed. We also email it to you.', 'subtleforms-license-server' );
	}

	/**
	 * Front-end panel for an order ('' when the order has no license items).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function panel_html( WC_Order $order ): string {
		if ( 0 === SFLS_Woo::order_mix( $order )['license'] ) {
			return '';
		}
		$licenses = SFLS_Woo::order_licenses( $order->get_id() );
		$html     = '<section class="sfls sfls-delivery" aria-labelledby="sfls-delivery-title-' . esc_attr( (string) $order->get_id() ) . '">';
		$html    .= '<h2 class="sfls-delivery-title" id="sfls-delivery-title-' . esc_attr( (string) $order->get_id() ) . '">' . esc_html( _n( 'Your SubtleForms Pro license', 'Your SubtleForms Pro licenses', max( 1, count( $licenses ) ), 'subtleforms-license-server' ) ) . '</h2>';

		if ( ! $licenses ) {
			if ( self::awaiting_paddle( $order ) ) {
				$received = function_exists( 'is_order_received_page' ) && is_order_received_page();
				if ( $received || ! $order->has_status( 'pending' ) ) {
					return $html . self::waiting_html( $order ) . '</section>';
				}
				// My Account › Orders: the customer closed the Paddle window without paying.
				$html .= '<p class="sfls-muted">' . esc_html( self::waiting_text( $order, true ) ) . '</p>';
				$html .= '<div class="sfls-actions"><a class="sfls-btn sfls-btn--primary" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay now', 'subtleforms-license-server' ) . '</a></div></section>';
				return $html;
			}
			$html .= '<p class="sfls-muted">' . esc_html( self::waiting_text( $order, false ) ) . '</p></section>';
			return $html;
		}

		foreach ( $licenses as $lic ) {
			$html .= '<div class="sfls-delivery-license"><div class="sfls-keyrow"><code class="sfls-key">' . esc_html( $lic['license_key'] ) . '</code>';
			$html .= '<button type="button" class="sfls-btn sfls-btn--ghost sfls-btn--sm sfls-copy" data-sfls-copy="' . esc_attr( $lic['license_key'] ) . '" data-sfls-copied="' . esc_attr__( 'Copied', 'subtleforms-license-server' ) . '"><span class="sfls-copy-label">' . esc_html__( 'Copy', 'subtleforms-license-server' ) . '</span></button></div>';
			$html .= '<p class="sfls-muted sfls-delivery-meta">' . esc_html( self::license_line( $lic ) ) . '</p></div>';
		}

		$download = self::download_link( false, $order );
		$guest    = self::guest_link( $order );
		$html    .= '<div class="sfls-actions sfls-delivery-actions">';
		if ( '' !== $download ) {
			$html .= '<a class="sfls-btn sfls-btn--primary" href="' . esc_url( $download ) . '">' . esc_html__( 'Download Pro', 'subtleforms-license-server' ) . '</a>';
		}
		if ( '' !== $guest ) {
			$html .= '<a class="sfls-btn sfls-btn--secondary" href="' . esc_url( $guest ) . '">' . esc_html__( 'Open your license page', 'subtleforms-license-server' ) . '</a></div>';
			$html .= '<p class="sfls-muted">' . esc_html__( 'Keep the email we sent you: its link opens your license page and download for 30 days, no account needed.', 'subtleforms-license-server' ) . '</p>';
		} else {
			$html .= '<a class="sfls-btn sfls-btn--secondary" href="' . esc_url( wc_get_account_endpoint_url( SFLS_Account::EP_LICENSES ) ) . '">' . esc_html__( 'Manage licenses', 'subtleforms-license-server' ) . '</a></div>';
		}
		if ( '' === $download ) {
			$html .= '<p class="sfls-muted">' . esc_html__( 'The Pro download appears in My Account › Pro downloads as soon as the first release is published.', 'subtleforms-license-server' ) . '</p>';
		}
		$html .= self::steps_html( '' !== $download );
		return $html . '</section>';
	}

	/**
	 * Whether an order paid with Paddle is waiting for Paddle to confirm the
	 * payment (the customer can be back before the webhook).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function awaiting_paddle( WC_Order $order ): bool {
		return SFLS_Woo_Paddle::GATEWAY === $order->get_payment_method()
			&& '' !== (string) $order->get_meta( '_sfls_paddle_txn_id' )
			&& $order->has_status( array( 'pending', 'completed', 'processing' ) );
	}

	/**
	 * "Confirming your payment" with a spinner; sfls-order-status.js polls
	 * GET /sfls/v1/paddle/checkout?order&key and swaps in the key.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function waiting_html( WC_Order $order ): string {
		SFLS_Woo_Paddle::register_assets();
		wp_enqueue_script( 'sfls-order-status' );
		wp_localize_script(
			'sfls-order-status',
			'sflsOrderStatus',
			array(
				'endpoint' => rest_url( SFLS_REST::NS . '/paddle/checkout' ),
				'interval' => 2000,
				'attempts' => 60,
				'slow'     => __( 'Paddle is still confirming your payment. Your license key will be emailed to you within a few minutes and will appear in My Account › Licenses.', 'subtleforms-license-server' ),
			)
		);
		$html  = '<div class="sfls-delivery-waiting" data-sfls-order-status data-order="' . esc_attr( (string) $order->get_id() ) . '" data-key="' . esc_attr( $order->get_order_key() ) . '" data-total="' . esc_attr( (string) $order->get_total() ) . '">';
		$html .= '<div class="sfls-welcome-spinner" aria-hidden="true"></div>';
		$html .= '<p class="sfls-delivery-waiting-title"><strong>' . esc_html__( 'Confirming your payment', 'subtleforms-license-server' ) . '</strong></p>';
		$html .= '<p class="sfls-muted" role="status">' . esc_html__( 'Paddle is confirming your payment. Your license key appears here in a few seconds. You can keep this page open.', 'subtleforms-license-server' ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * Inner HTML of the panel for the order status endpoint (escaped).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function panel_body( WC_Order $order ): string {
		$html = self::panel_html( $order );
		$html = (string) preg_replace( '#^<section[^>]*>|</section>$#', '', $html );
		return wp_kses( $html, self::kses() );
	}

	/**
	 * Allowed tags for the panel.
	 */
	private static function kses(): array {
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['button'] = array(
			'type'             => true,
			'class'            => true,
			'data-sfls-copy'   => true,
			'data-sfls-copied' => true,
		);
		$allowed['section'] = array(
			'class'           => true,
			'aria-labelledby' => true,
		);
		$allowed['div']     = array_merge(
			(array) ( $allowed['div'] ?? array() ),
			array(
				'class'                  => true,
				'aria-hidden'            => true,
				'data-sfls-order-status' => true,
				'data-order'             => true,
				'data-key'               => true,
				'data-total'             => true,
			)
		);
		$allowed['p']       = array_merge( (array) ( $allowed['p'] ?? array() ), array( 'role' => true ) );
		return $allowed;
	}

	/**
	 * Print the panel once per order and request.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function print_panel( WC_Order $order ): void {
		echo self::render_once( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_once() runs wp_kses().
	}

	/**
	 * Panel HTML the first time it is asked for in a request, '' afterwards.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_once( WC_Order $order ): string {
		if ( isset( self::$shown[ $order->get_id() ] ) ) {
			return '';
		}
		$html = self::panel_html( $order );
		if ( '' === $html ) {
			return '';
		}
		self::$shown[ $order->get_id() ] = true;
		SFLS_Assets::enqueue();
		return wp_kses( $html, self::kses() );
	}

	/**
	 * The order on the current order received page, if the visitor may see it.
	 */
	private static function received_order(): ?WC_Order {
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the credential.
		if ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return $order;
		}
		$user = get_current_user_id();
		return ( $user && (int) $order->get_customer_id() === $user ) ? $order : null;
	}

	// ── Pages ───────────────────────────────────────────────────────────

	/**
	 * Block order confirmation: add the panel after the status block.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function after_status_block( $content, $block = array() ) {
		unset( $block );
		$order = self::received_order();
		if ( ! $order ) {
			return $content;
		}
		// Wide alignment lines the panel up with the page in constrained layouts.
		return $content . str_replace( 'class="sfls sfls-delivery"', 'class="sfls sfls-delivery alignwide"', self::render_once( $order ) );
	}

	/**
	 * Classic thank-you page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order ) {
			self::print_panel( $order );
		}
	}

	/**
	 * My Account order view (and any other order details table).
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_page( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::print_panel( $order );
		}
	}

	// ── Emails ──────────────────────────────────────────────────────────

	/**
	 * Keys, Download Pro and install steps in order emails. Emails to the shop
	 * admin only list the keys.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin email.
	 * @param bool     $plain_text    Plain text email.
	 * @param mixed    $email         Email object.
	 */
	public function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$licenses = SFLS_Woo::order_licenses( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		$download = $sent_to_admin ? '' : self::download_link( true, $order );

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( _n( 'Your SubtleForms Pro license', 'Your SubtleForms Pro licenses', count( $licenses ), 'subtleforms-license-server' ) ) ) . "\n\n";
			foreach ( $licenses as $lic ) {
				echo esc_html( $lic['license_key'] ) . "\n" . esc_html( self::license_line( $lic ) ) . "\n\n";
			}
			if ( $sent_to_admin ) {
				return;
			}
			if ( '' !== $download ) {
				echo esc_html__( 'Download Pro:', 'subtleforms-license-server' ) . ' ' . esc_url_raw( $download ) . "\n\n";
			}
			echo esc_html__( 'How to install Pro', 'subtleforms-license-server' ) . "\n";
			foreach ( self::steps( '' !== $download ) as $i => $step ) {
				echo esc_html( ( $i + 1 ) . '. ' . $step[0] . ': ' . $step[1] ) . "\n";
			}
			echo "\n";
			return;
		}

		$box  = 'font-family:Menlo,Consolas,monospace;font-size:15px;letter-spacing:.02em;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;display:inline-block;word-break:break-all;';
		$html = '<div style="margin:0 0 32px;">';
		$html .= '<h2>' . esc_html( _n( 'Your SubtleForms Pro license', 'Your SubtleForms Pro licenses', count( $licenses ), 'subtleforms-license-server' ) ) . '</h2>';
		foreach ( $licenses as $lic ) {
			$html .= '<p style="margin:0 0 4px;"><code style="' . esc_attr( $box ) . '">' . esc_html( $lic['license_key'] ) . '</code></p>';
			$html .= '<p style="margin:0 0 16px;color:#646970;font-size:13px;">' . esc_html( self::license_line( $lic ) ) . '</p>';
		}
		if ( ! $sent_to_admin ) {
			if ( '' !== $download ) {
				$html .= '<p style="margin:20px 0;"><a href="' . esc_url( $download ) . '" style="display:inline-block;background:#1e1e1e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">' . esc_html__( 'Download Pro', 'subtleforms-license-server' ) . '</a></p>';
			}
			$html .= '<h3 style="margin:24px 0 8px;">' . esc_html__( 'How to install Pro', 'subtleforms-license-server' ) . '</h3><ol style="margin:0 0 0 20px;padding:0;">';
			foreach ( self::steps( '' !== $download ) as $step ) {
				$html .= '<li style="margin:0 0 8px;"><strong>' . esc_html( $step[0] ) . '.</strong> ' . esc_html( $step[1] ) . '</li>';
			}
			$html .= '</ol>';
		}
		$html .= '</div>';
		echo wp_kses_post( $html );
	}

	/**
	 * Whether a WooCommerce email goes to the customer of a license order.
	 *
	 * @param mixed $object Email object (order).
	 * @param mixed $email  WC_Email.
	 */
	private static function is_license_customer_email( $object, $email ): bool {
		return $object instanceof WC_Order
			&& $email instanceof WC_Email
			&& $email->is_customer_email()
			&& SFLS_Woo::order_mix( $object )['license'] > 0;
	}

	/**
	 * Replies to license emails go to the support address.
	 *
	 * @param string $headers  Headers.
	 * @param string $email_id Email ID.
	 * @param mixed  $object   Email object.
	 * @param mixed  $email    WC_Email.
	 * @return string
	 */
	public function email_headers( $headers, $email_id = '', $object = null, $email = null ) {
		unset( $email_id );
		if ( ! self::is_license_customer_email( $object, $email ) ) {
			return $headers;
		}
		return self::with_reply_to( (string) $headers );
	}

	/**
	 * Replace any Reply-To header with the support address.
	 *
	 * @param string $headers Header block.
	 */
	public static function with_reply_to( string $headers ): string {
		$headers = (string) preg_replace( '/^Reply-To:.*(\r\n|\n|$)/mi', '', $headers );
		$name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name    = str_replace( array( '"', "\r", "\n" ), '', $name );
		return $headers . 'Reply-To: "' . $name . '" <' . SFLS_Settings::support_email() . ">\r\n";
	}

	/**
	 * From address of license emails: the support address, when it is on this
	 * site's domain (another domain would fail SPF/DMARC checks).
	 *
	 * @param string $from  From address.
	 * @param mixed  $email WC_Email.
	 * @return string
	 */
	public function email_from( $from, $email = null ) {
		if ( ! SFLS_Settings::support_email_on_site_domain() ) {
			return $from;
		}
		$object = ( $email instanceof WC_Email ) ? $email->object : null;
		if ( self::$own_mail || self::is_license_customer_email( $object, $email ) ) {
			return SFLS_Settings::support_email();
		}
		return $from;
	}

	/**
	 * Run a callback that sends one of the plugin's own emails, so the
	 * support address becomes the sender.
	 *
	 * @param callable $send Sends the email, returns the result.
	 * @return mixed
	 */
	public static function as_license_mail( callable $send ) {
		self::$own_mail = true;
		try {
			return $send();
		} finally {
			self::$own_mail = false;
		}
	}
}
