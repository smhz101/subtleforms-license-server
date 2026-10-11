<?php
/**
 * Plans: what each tier unlocks and how many sites it covers.
 *
 * Feature slugs must match SubtleForms Pro's LicenseManager::PLAN_FEATURES.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan table.
 */
final class SFLS_Plans {

	/**
	 * All plans, smallest first.
	 *
	 * @return array<string,array{label:string,sites:int,features:string[]}>
	 */
	public static function all(): array {
		$plans = array(
			'starter' => array(
				'label'    => __( 'Starter', 'subtleforms-license-server' ),
				'sites'    => 1,
				'features' => array( 'csv_export' ),
			),
			'pro'     => array(
				'label'    => __( 'Pro', 'subtleforms-license-server' ),
				'sites'    => 5,
				'features' => array( 'csv_export', 'webhooks', 'integrations', 'conditional_logic' ),
			),
			'agency'  => array(
				'label'    => __( 'Agency', 'subtleforms-license-server' ),
				'sites'    => 0, // 0 = unlimited.
				'features' => array( 'csv_export', 'webhooks', 'integrations', 'conditional_logic', 'white_label' ),
			),
		);

		/**
		 * Filter the plan table.
		 *
		 * @param array $plans Plans keyed by slug.
		 */
		return (array) apply_filters( 'sfls_plans', $plans );
	}

	/**
	 * One plan.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function get( string $plan ): ?array {
		$all = self::all();
		return $all[ $plan ] ?? null;
	}

	/**
	 * Feature slugs of a plan.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function features( string $plan ): array {
		return self::get( $plan )['features'] ?? array();
	}

	/**
	 * Plan label.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function label( string $plan ): string {
		return self::get( $plan )['label'] ?? ucfirst( $plan );
	}

	/**
	 * Position of a plan in the table (higher = bigger plan), -1 if unknown.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function rank( string $plan ): int {
		$pos = array_search( $plan, array_keys( self::all() ), true );
		return false === $pos ? -1 : (int) $pos;
	}

	/**
	 * Plans ranked above the given one.
	 *
	 * @param string $plan Plan slug.
	 * @return string[]
	 */
	public static function higher( string $plan ): array {
		$rank = self::rank( $plan );
		return array_values(
			array_filter(
				array_keys( self::all() ),
				static fn( $slug ) => self::rank( $slug ) > $rank
			)
		);
	}

	/**
	 * "5 sites" / "1 site" / "Unlimited sites".
	 *
	 * @param int $sites Site count, 0 for unlimited.
	 */
	public static function sites_label( int $sites ): string {
		if ( $sites <= 0 ) {
			return __( 'Unlimited sites', 'subtleforms-license-server' );
		}
		/* translators: %d: number of sites */
		return sprintf( _n( '%d site', '%d sites', $sites, 'subtleforms-license-server' ), $sites );
	}

	/**
	 * Human labels for feature slugs.
	 *
	 * @return array<string,string>
	 */
	public static function feature_labels(): array {
		return (array) apply_filters(
			'sfls_feature_labels',
			array(
				'csv_export'        => __( 'CSV export', 'subtleforms-license-server' ),
				'webhooks'          => __( 'Webhooks', 'subtleforms-license-server' ),
				'integrations'      => __( 'CRM, email marketing and payment integrations', 'subtleforms-license-server' ),
				'conditional_logic' => __( 'Advanced conditional logic', 'subtleforms-license-server' ),
				'white_label'       => __( 'White label', 'subtleforms-license-server' ),
			)
		);
	}

	/**
	 * Items every Pro plan includes.
	 *
	 * @return string[]
	 */
	public static function common_items(): array {
		return (array) apply_filters(
			'sfls_common_items',
			array(
				__( 'Stripe payments', 'subtleforms-license-server' ),
				__( 'Coupons', 'subtleforms-license-server' ),
				__( 'Calculations', 'subtleforms-license-server' ),
				__( 'Signature field', 'subtleforms-license-server' ),
				__( 'Priority support', 'subtleforms-license-server' ),
			)
		);
	}
}
