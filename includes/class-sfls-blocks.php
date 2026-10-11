<?php
/**
 * Server-rendered blocks for the marketing site:
 *
 *   sfls/pricing         Same as [sfls_pricing], with controls for the highlighted
 *                        plan, renewal note, yearly/lifetime default and button labels.
 *   sfls/changelog       Same as [sfls_changelog].
 *   sfls/latest-version  Same as [sfls_latest_version].
 *   sfls/account-link    "Sign in" for visitors, "My licenses" with an avatar
 *                        for signed-in customers.
 *
 * Each block has a block.json in blocks/<name>/. The editor script
 * (assets/js/sfls-blocks-editor.js) is plain JavaScript on the wp.* globals,
 * so there is no build step.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block registration and render callbacks.
 */
final class SFLS_Blocks {

	const EDITOR_HANDLE = 'sfls-blocks-editor';

	/**
	 * Hook.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register the editor script and the four blocks.
	 */
	public function register_blocks(): void {
		SFLS_Assets::register_handles();
		wp_register_script(
			self::EDITOR_HANDLE,
			SFLS_URL . 'assets/js/sfls-blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			SFLS_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'subtleforms-license-server', SFLS_DIR . 'languages' );
		wp_localize_script(
			self::EDITOR_HANDLE,
			'sflsBlocks',
			array(
				'plans' => array_map(
					static fn( $slug, $plan ) => array(
						'value' => $slug,
						'label' => $plan['label'],
					),
					array_keys( SFLS_Plans::all() ),
					SFLS_Plans::all()
				),
			)
		);

		$callbacks = array(
			'pricing'        => array( $this, 'render_pricing' ),
			'changelog'      => array( $this, 'render_changelog' ),
			'latest-version' => array( $this, 'render_latest_version' ),
			'account-link'   => array( $this, 'render_account_link' ),
		);
		foreach ( $callbacks as $dir => $callback ) {
			register_block_type( SFLS_DIR . 'blocks/' . $dir, array( 'render_callback' => $callback ) );
		}
	}

	/**
	 * Whether the block is being previewed in the editor.
	 */
	private static function in_editor(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
	}

	/**
	 * Placeholder shown only in the editor.
	 *
	 * @param string $message Message.
	 */
	private static function editor_note( string $message ): string {
		return self::in_editor() ? '<div ' . get_block_wrapper_attributes( array( 'class' => 'sfls sfls-notice-box' ) ) . '>' . esc_html( $message ) . '</div>' : '';
	}

	/**
	 * Pricing block.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_pricing( $attributes ): string {
		$attributes = wp_parse_args(
			(array) $attributes,
			array(
				'popular'             => 'pro',
				'period'              => 'yearly',
				'showRenewalNote'     => true,
				'buttonLabel'         => '',
				'lifetimeButtonLabel' => '',
			)
		);
		$html = ( new SFLS_Shortcodes() )->pricing(
			array(
				'popular'         => sanitize_key( (string) $attributes['popular'] ),
				'period'          => 'lifetime' === $attributes['period'] ? 'lifetime' : 'yearly',
				'renewal_note'    => $attributes['showRenewalNote'] ? 'yes' : 'no',
				'button'          => sanitize_text_field( (string) $attributes['buttonLabel'] ),
				'button_lifetime' => sanitize_text_field( (string) $attributes['lifetimeButtonLabel'] ),
			)
		);
		if ( '' === $html ) {
			return self::editor_note( __( 'No license products are on sale yet. Create them in Licenses › Products.', 'subtleforms-license-server' ) );
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Changelog block.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_changelog( $attributes ): string {
		$html = ( new SFLS_Shortcodes() )->changelog( array( 'limit' => absint( $attributes['limit'] ?? 0 ) ) );
		if ( '' === $html ) {
			return self::editor_note( __( 'No Pro releases yet. The changelog appears here after the first upload in Licenses › Pro releases.', 'subtleforms-license-server' ) );
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Latest version block.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_latest_version( $attributes ): string {
		$prefix = sanitize_text_field( (string) ( $attributes['prefix'] ?? '' ) );
		$text   = '' !== $prefix ? $prefix . ' ' . SFLS_Account::latest_version_label() : SFLS_Account::latest_version_label();
		return '<p ' . get_block_wrapper_attributes() . '>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Account link block.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_account_link( $attributes ): string {
		$attributes = wp_parse_args(
			(array) $attributes,
			array(
				'signInLabel'  => '',
				'accountLabel' => '',
				'showAvatar'   => true,
			)
		);
		$account = function_exists( 'wc_get_page_permalink' ) && wc_get_page_id( 'myaccount' ) > 0 ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

		if ( ! is_user_logged_in() ) {
			$label = '' !== trim( (string) $attributes['signInLabel'] ) ? (string) $attributes['signInLabel'] : __( 'Sign in', 'subtleforms-license-server' );
			return '<div ' . get_block_wrapper_attributes( array( 'class' => 'is-signed-out' ) ) . '><a class="sfls-account-link__link" href="' . esc_url( $account ) . '">' . esc_html( $label ) . '</a></div>';
		}

		$user  = wp_get_current_user();
		$label = '' !== trim( (string) $attributes['accountLabel'] ) ? (string) $attributes['accountLabel'] : __( 'My licenses', 'subtleforms-license-server' );
		$url   = function_exists( 'wc_get_account_endpoint_url' ) && wc_get_page_id( 'myaccount' ) > 0 ? wc_get_account_endpoint_url( SFLS_Account::EP_LICENSES ) : admin_url( 'profile.php' );
		$html  = '<div ' . get_block_wrapper_attributes( array( 'class' => 'is-signed-in' ) ) . '><a class="sfls-account-link__link" href="' . esc_url( $url ) . '">';
		if ( $attributes['showAvatar'] ) {
			$html .= get_avatar(
				$user->ID,
				24,
				'',
				'',
				array(
					'class'         => 'sfls-account-link__avatar',
					'force_display' => true,
					'extra_attr'    => 'aria-hidden="true"',
				)
			);
		}
		$html .= '<span class="sfls-account-link__label">' . esc_html( $label ) . '</span></a></div>';
		return $html;
	}
}
