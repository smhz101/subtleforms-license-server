<?php
/**
 * Plans: what each tier unlocks and how many sites it covers.
 *
 * Feature slugs must match SubtleForms Pro's LicenseManager::PLAN_FEATURES.
 *
 * @package SFLS
 */

defined( 'ABSPATH' ) || exit;

final class SFLS_Plans {

	/**
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

	public static function get( string $plan ): ?array {
		$all = self::all();
		return $all[ $plan ] ?? null;
	}

	public static function features( string $plan ): array {
		return self::get( $plan )['features'] ?? array();
	}

	public static function label( string $plan ): string {
		return self::get( $plan )['label'] ?? ucfirst( $plan );
	}
}
