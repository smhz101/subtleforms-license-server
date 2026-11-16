<?php
/**
 * Email templates. A theme can override any of them by copying the file from
 * this plugin's templates/emails/ to <theme>/subtleforms-license-server/emails/.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locate and render email templates, and the admin preview.
 */
final class SFLS_Templates {

	const PREVIEW_ACTION = 'sfls_preview_email';

	/**
	 * Hook the preview.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( self::class, 'handle_preview' ) );
	}

	/**
	 * Path of a template: the child theme, then the parent theme, then the plugin.
	 *
	 * @param string $name Template slug, e.g. welcome.
	 */
	public static function locate( string $name ): string {
		$name = preg_replace( '/[^a-z0-9_-]/i', '', $name );
		$path = locate_template( 'subtleforms-license-server/emails/' . $name . '.php' );
		if ( ! $path ) {
			$path = SFLS_DIR . 'templates/emails/' . $name . '.php';
		}
		/**
		 * Filters the path of an email template.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template slug.
		 */
		return (string) apply_filters( 'sfls_email_template', $path, $name );
	}

	/**
	 * Render a template to a string ('' when the file is missing).
	 *
	 * @param string $name Template slug.
	 * @param array  $args Data for the template, see the file header.
	 */
	public static function render( string $name, array $args ): string {
		$path = self::locate( $name );
		if ( ! is_file( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( string $__path, array $args ) {
			include $__path;
		} )( $path, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Slug => label of every template that can be previewed.
	 *
	 * @return array<string,string>
	 */
	public static function previews(): array {
		return SFLS_Billing_Emails::templates() + array( 'issued' => __( 'License issued by hand', 'subtleforms-license-server' ) );
	}

	/**
	 * Preview URL of a template.
	 *
	 * @param string $name Template slug.
	 */
	public static function preview_url( string $name ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => self::PREVIEW_ACTION, 'template' => $name ), admin_url( 'admin-post.php' ) ), self::PREVIEW_ACTION );
	}

	/**
	 * Output a template with sample data.
	 */
	public static function handle_preview(): void {
		if ( ! current_user_can( SFLS_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'subtleforms-license-server' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PREVIEW_ACTION );
		$name = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : '';
		if ( ! isset( self::previews()[ $name ] ) ) {
			wp_die( esc_html__( 'Unknown template.', 'subtleforms-license-server' ), '', array( 'response' => 404 ) );
		}
		echo self::preview_html( $name ); // phpcs:ignore WordPress.Security.EscapeOutput -- Rendered templates escape their data.
		exit;
	}

	/**
	 * A template rendered with sample data.
	 *
	 * @param string $name Template slug.
	 */
	public static function preview_html( string $name ): string {
		if ( 'issued' === $name ) {
			$lic  = SFLS_Billing_Emails::sample_license( 'welcome', 'customer@example.com' );
			$body = self::render(
				'issued',
				array(
					'licenses' => array( $lic ),
					'many'     => false,
					'url'      => home_url( '/' ),
					'support'  => SFLS_Settings::support_email(),
				)
			);
		} else {
			$lic  = SFLS_Billing_Emails::sample_license( $name, 'customer@example.com' );
			$body = SFLS_Billing_Emails::build( $name, $lic, array( 'amount' => SFLS_Paddle::format_amount( '7900', 'USD' ), 'new_account' => false ), 'Sam' )[2];
		}
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html( self::previews()[ $name ] ) . '</title></head><body style="font-family:sans-serif;max-width:600px;margin:24px auto;">' . $body . '</body></html>';
	}
}
