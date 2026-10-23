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
				'sites'    => 25,
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
				'csv_export'        => __( 'CSV export of entries', 'subtleforms-license-server' ),
				'webhooks'          => __( 'Webhooks with retries', 'subtleforms-license-server' ),
				'integrations'      => __( 'WooCommerce checkout, Mailchimp, Brevo, Kit, HubSpot, Salesforce, Pipedrive, Close, Zapier, Make and n8n', 'subtleforms-license-server' ),
				'conditional_logic' => __( 'Advanced conditional logic', 'subtleforms-license-server' ),
				'white_label'       => __( "Branding portal for your clients' sites, white label and WPML/Polylang translation", 'subtleforms-license-server' ),
			)
		);
	}

	/**
	 * Feature slugs that the common items already describe, so pricing cards
	 * do not list them twice.
	 *
	 * @return string[]
	 */
	public static function common_slugs(): array {
		return (array) apply_filters( 'sfls_common_feature_slugs', array( 'conditional_logic' ) );
	}

	/**
	 * Human labels of a plan's features, without empty entries.
	 *
	 * @param string $plan          Plan slug.
	 * @param bool   $skip_common   Leave out slugs the common items cover.
	 * @return string[]
	 */
	public static function feature_label_list( string $plan, bool $skip_common = false ): array {
		$labels = self::feature_labels();
		$out    = array();
		foreach ( self::features( $plan ) as $slug ) {
			if ( $skip_common && in_array( $slug, self::common_slugs(), true ) ) {
				continue;
			}
			$label = $labels[ $slug ] ?? ucwords( str_replace( '_', ' ', (string) $slug ) );
			$out[] = trim( (string) $label );
		}
		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * A plan's features as one line ("A · B · C"). Labels contain commas
	 * themselves, so a middle dot keeps the items apart; empty labels can never
	 * leave a stray separator.
	 *
	 * @param string $plan Plan slug.
	 */
	public static function feature_line( string $plan ): string {
		return implode( ' · ', self::feature_label_list( $plan ) );
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
				__( 'Stripe checkout with coupons', 'subtleforms-license-server' ),
				__( 'Advanced conditional logic and conditional emails', 'subtleforms-license-server' ),
				__( 'Calculation, Signature and Picture Choice fields', 'subtleforms-license-server' ),
				__( 'Multi-file uploads', 'subtleforms-license-server' ),
				__( 'Entry PDFs and form analytics', 'subtleforms-license-server' ),
				__( 'Pro templates', 'subtleforms-license-server' ),
				__( 'MCP server for AI assistants', 'subtleforms-license-server' ),
				__( 'Updates and priority support', 'subtleforms-license-server' ),
			)
		);
	}
}
