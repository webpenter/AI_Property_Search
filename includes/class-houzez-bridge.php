<?php
/**
 * Bridge to the stock Houzez search-results page.
 *
 * The AI turns text into validated criteria; this class expresses those criteria
 * as plain `spot_*` URL params on Houzez's own results page and then narrows that
 * page's query (server render AND half-map ajax) to exactly the same listing IDs
 * our search computes. Nothing from the model is ever placed in a query string
 * unvalidated: params are re-parsed through Spot_AI_Criteria on every request.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Houzez_Bridge {

	const PARAMS = array( 'spot_ai', 'spot_loc', 'spot_types', 'spot_pmin', 'spot_pmax', 'spot_bmin', 'spot_bmax', 'spot_bath', 'spot_gar', 'spot_rad', 'spot_st', 'spot_req', 'spot_pref' );

	public static function init() {
		if ( Spot_AI_Adapter::get( 'results' ) !== 'houzez' ) {
			return; // Other themes show results inline in the panel.
		}
		add_filter( 'houzez20_search_filters', array( __CLASS__, 'apply_to_query' ), 20 );
		add_action( 'houzez_search_hidden_fields', array( __CLASS__, 'hidden_fields' ) );
	}

	public static function results_page_url() {
		return function_exists( 'houzez_get_search_template_link' ) ? houzez_get_search_template_link() : get_post_type_archive_link( 'property' );
	}

	/** Build the Houzez results URL for a criteria state. */
	public static function results_url( array $c ) {
		$a = array( 'spot_ai' => 1 );
		if ( $c['location'] ) {
			$a['spot_loc'] = $c['location'];
		}
		if ( $c['property_types'] ) {
			$a['spot_types'] = implode( ',', $c['property_types'] );
		}
		$map = array( 'price_min' => 'spot_pmin', 'price_max' => 'spot_pmax', 'bedrooms_min' => 'spot_bmin', 'bedrooms_max' => 'spot_bmax', 'bathrooms_min' => 'spot_bath', 'garages_min' => 'spot_gar', 'radius_miles' => 'spot_rad' );
		foreach ( $map as $field => $param ) {
			if ( $c[ $field ] !== null ) {
				$a[ $param ] = (int) $c[ $field ];
			}
		}
		if ( ! empty( $c['listing_status'] ) ) {
			$a['spot_st'] = $c['listing_status'];
		}
		if ( $c['features_required'] ) {
			$a['spot_req'] = implode( ',', $c['features_required'] );
		}
		if ( $c['features_preferred'] ) {
			$a['spot_pref'] = implode( ',', $c['features_preferred'] );
		}
		$a = $a + self::display_params( $c );
		$url = self::results_page_url();
		return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $a, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Stock Houzez params, so the visible filter fields on the results page show the same
	 * values as the AI criteria. (Houzez's own filters are neutralised while spot_ai is
	 * active; see apply_to_query. These only populate the form.)
	 */
	public static function display_params( array $c ) {
		$p  = array();
		$st = Spot_AI_Search::effective_status( $c );
		$ss = $st ? ( Spot_AI_Catalog::get()['status'][ $st ] ?? null ) : null;
		if ( $ss ) {
			$p['status'] = array( $ss ); // Also selects Houzez's sale or rent price range for the slider.
		}
		if ( $c['bedrooms_min'] !== null ) {
			$p['bedrooms'] = (int) $c['bedrooms_min'];
		}
		if ( $c['bathrooms_min'] !== null ) {
			$p['bathrooms'] = (int) $c['bathrooms_min'];
		}
		if ( ! empty( $c['garages_min'] ) ) {
			$p['garage'] = (int) $c['garages_min'];
		}
		if ( $c['price_min'] ) {
			$p['min-price'] = (int) $c['price_min'];
		}
		if ( $c['price_max'] ) {
			$p['max-price'] = (int) $c['price_max'];
		}
		if ( $c['property_types'] ) {
			$p['type'] = array( sanitize_title( $c['property_types'][0] ) ); // The form's type field holds one value.
		}
		if ( $c['location'] && empty( $c['radius_miles'] ) ) { // A radius search spans several cities, so the single-city field stays on its default.
			$term = get_term_by( 'name', $c['location'], 'property_city' );
			if ( $term ) {
				$p['location'] = array( $term->slug );
			}
		}
		$feat = array_values( array_diff( $c['features_required'], array( 'near_lake' ) ) );
		if ( $feat ) {
			$p['feature'] = array_map( function ( $f ) { return str_replace( '_', '-', $f ) === $f || strpos( $f, '-' ) !== false ? $f : str_replace( '_', '-', $f ); }, $feat );
		}
		return $p;
	}

	/** Re-parse params (untrusted) into a validated state. */
	public static function state_from_request( array $src ) {
		$csv = function ( $k ) use ( $src ) {
			return isset( $src[ $k ] ) && is_string( $src[ $k ] ) ? array_filter( explode( ',', $src[ $k ] ) ) : null;
		};
		$int = function ( $k ) use ( $src ) {
			return isset( $src[ $k ] ) && is_numeric( $src[ $k ] ) ? (int) $src[ $k ] : null;
		};
		$raw = array(
			'location'           => isset( $src['spot_loc'] ) && is_string( $src['spot_loc'] ) ? wp_unslash( $src['spot_loc'] ) : null,
			'property_types'     => $csv( 'spot_types' ),
			'price_min'          => $int( 'spot_pmin' ),
			'price_max'          => $int( 'spot_pmax' ),
			'bedrooms_min'       => $int( 'spot_bmin' ),
			'bedrooms_max'       => $int( 'spot_bmax' ),
			'bathrooms_min'      => $int( 'spot_bath' ),
			'garages_min'        => $int( 'spot_gar' ),
			'radius_miles'       => $int( 'spot_rad' ),
			'listing_status'     => isset( $src['spot_st'] ) && is_string( $src['spot_st'] ) ? $src['spot_st'] : null,
			'features_required'  => $csv( 'spot_req' ),
			'features_preferred' => $csv( 'spot_pref' ),
		);
		return Spot_AI_Criteria::sanitize_state( array_filter( $raw, function ( $v ) { return $v !== null; } ), Spot_AI_Search::known_cities() );
	}

	/** Restrict Houzez's query to the listings our search selects, ranked by preference match. */
	public static function apply_to_query( $q ) {
		if ( empty( $_GET['spot_ai'] ) ) {
			return $q;
		}
		$c   = self::state_from_request( $_GET );
		$ids = Spot_AI_Search::ranked_ids( $c );

		$q['post__in'] = $ids ? $ids : array( 0 );
		// post__in already encodes every criterion (with ">=" semantics); Houzez's own exact-match
		// bedroom/price/etc. filters would only make the page disagree with the AI count.
		if ( empty( $_GET['keyword'] ) ) {
			unset( $q['meta_query'], $q['tax_query'] );
		}
		if ( empty( $_GET['sortby'] ) ) {
			$q['orderby'] = 'post__in';
			unset( $q['order'] );
		}
		return $q;
	}

	/** Keep our params in the half-map form so its ajax requests carry them. */
	public static function hidden_fields() {
		if ( empty( $_GET['spot_ai'] ) ) {
			return;
		}
		foreach ( self::PARAMS as $p ) {
			if ( isset( $_GET[ $p ] ) && is_string( $_GET[ $p ] ) ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $p ), esc_attr( wp_unslash( $_GET[ $p ] ) ) );
			}
		}
	}

	public static function is_results_page() {
		return is_page_template( 'template/template-search.php' );
	}
}
