<?php
/**
 * Fixed, parameterised property search over Houzez data.
 * Input is ONLY a validated criteria state; nothing from the model reaches a query string.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Search {

	const LAKE_RADIUS_MILES = 1.5;
	const PAGE_SIZE         = 12;

	/** Approximate centre of Lake Zurich, IL (override with SPOT_AI_LAKE_LAT / SPOT_AI_LAKE_LNG). */
	public static function lake_point() {
		return array(
			defined( 'SPOT_AI_LAKE_LAT' ) ? (float) SPOT_AI_LAKE_LAT : 42.1945,
			defined( 'SPOT_AI_LAKE_LNG' ) ? (float) SPOT_AI_LAKE_LNG : -88.0820,
		);
	}

	/** Restrict to labelled sample listings unless SPOT_AI_USE_ALL_LISTINGS is true. */
	public static function demo_only() {
		if ( defined( 'SPOT_AI_USE_ALL_LISTINGS' ) ) {
			return ! SPOT_AI_USE_ALL_LISTINGS;
		}
		$scope = get_option( 'spot_ai_scope', 'auto' );
		if ( $scope === 'all' ) {
			return false;
		}
		if ( $scope === 'sample' ) {
			return true;
		}
		return self::has_sample_data(); // auto: use only the labelled sample listings while they exist, otherwise every listing.
	}

	public static function has_sample_data() {
		static $memo = null;
		if ( $memo === null ) {
			$memo = (bool) get_posts( array( 'post_type' => Spot_AI_Adapter::get( 'post_type', 'post' ), 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true,
				'meta_query' => array( array( 'key' => 'spot_demo_sample', 'value' => '1' ) ) ) );
		}
		return $memo;
	}

	/** sale/rent: what the visitor asked for, else "sale" when a sale-sized price was given. */
	public static function effective_status( array $c ) {
		if ( ! empty( $c['listing_status'] ) ) {
			return $c['listing_status'];
		}
		$cat = Spot_AI_Catalog::get();
		if ( Spot_AI_Adapter::supports( 'status' ) && isset( $cat['status']['sale'] ) && ( ( $c['price_max'] && $c['price_max'] >= 20000 ) || ( $c['price_min'] && $c['price_min'] >= 20000 ) ) ) {
			return 'sale';
		}
		return null;
	}

	public static function known_cities() {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$key    = 'spot_ai_cities_' . ( self::demo_only() ? 'demo' : 'all' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $memo = $cached;
		}
		$memo = self::load_cities();
		set_transient( $key, $memo, 10 * MINUTE_IN_SECONDS );
		return $memo;
	}

	private static function load_cities() {
		$tax = Spot_AI_Adapter::get( 'tax.location' );
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$ids = get_posts( array(
			'post_type' => Spot_AI_Adapter::get( 'post_type', 'post' ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true,
			'meta_query' => self::demo_only() ? array( array( 'key' => 'spot_demo_sample', 'value' => '1' ) ) : array(),
		) );
		if ( ! $ids ) {
			return array();
		}
		$terms = wp_get_object_terms( $ids, $tax, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		usort( $terms, function ( $a, $b ) { return $b->count <=> $a->count; } ); // Busiest places first.
		return array_values( array_unique( array_map( function ( $o ) { return html_entity_decode( $o->name, ENT_QUOTES, 'UTF-8' ); }, $terms ) ) );
	}

	/** One price comparison; sites that keep a second price key (e.g. a range minimum) match on either. */
	private static function price_clause( $op, $val ) {
		$key = Spot_AI_Adapter::get( 'meta.price' );
		$alt = Spot_AI_Adapter::get( 'meta.price_alt' );
		$one = function ( $k ) use ( $op, $val ) {
			return array( 'key' => $k, 'value' => (int) $val, 'type' => 'NUMERIC', 'compare' => $op );
		};
		return $alt ? array( 'relation' => 'OR', $one( $key ), $one( $alt ) ) : $one( $key );
	}

	public static function query_ids( array $c, $skip_lake = false ) {
		$meta = array( 'relation' => 'AND' );
		$tax  = array( 'relation' => 'AND' );
		$m    = Spot_AI_Adapter::get( 'meta', array() );
		$t    = Spot_AI_Adapter::get( 'tax', array() );

		if ( self::demo_only() ) {
			$meta[] = array( 'key' => 'spot_demo_sample', 'value' => '1' );
		}
		if ( Spot_AI_Adapter::supports( 'price' ) ) {
			if ( $c['price_min'] ) {
				$meta[] = self::price_clause( '>=', $c['price_min'] );
			}
			if ( $c['price_max'] ) {
				$meta[] = self::price_clause( '<=', $c['price_max'] );
			}
		}
		$num = function ( $key, $op, $val ) {
			return array( 'key' => $key, 'value' => (int) $val, 'type' => 'NUMERIC', 'compare' => $op );
		};
		if ( Spot_AI_Adapter::supports( 'bedrooms' ) ) {
			if ( $c['bedrooms_min'] !== null ) {
				$meta[] = $num( $m['beds'], '>=', $c['bedrooms_min'] );
			}
			if ( $c['bedrooms_max'] !== null ) {
				$meta[] = $num( $m['beds'], '<=', $c['bedrooms_max'] );
			}
		}
		if ( Spot_AI_Adapter::supports( 'bathrooms' ) && $c['bathrooms_min'] !== null ) {
			$meta[] = $num( $m['baths'], '>=', $c['bathrooms_min'] );
		}
		if ( Spot_AI_Adapter::supports( 'garages' ) && ! empty( $c['garages_min'] ) ) {
			$meta[] = $num( $m['garage'], '>=', $c['garages_min'] );
		}
		$st_slug = ( Spot_AI_Adapter::supports( 'status' ) && ( $st = self::effective_status( $c ) ) ) ? ( Spot_AI_Catalog::get()['status'][ $st ] ?? null ) : null;
		if ( $st_slug ) {
			$tax[] = array( 'taxonomy' => $t['status'], 'field' => 'slug', 'terms' => array( $st_slug ) );
		}
		$center = ( Spot_AI_Adapter::supports( 'radius' ) && $c['location'] && ! empty( $c['radius_miles'] ) ) ? self::city_centroid( $c['location'] ) : null;
		if ( Spot_AI_Adapter::supports( 'location' ) && $c['location'] && ! $center ) {
			$tax[] = array( 'taxonomy' => $t['location'], 'field' => 'name', 'terms' => array( $c['location'] ) );
		}
		if ( Spot_AI_Adapter::supports( 'types' ) && $c['property_types'] ) {
			$tax[] = array( 'taxonomy' => $t['type'], 'field' => 'slug', 'terms' => array_map( 'sanitize_title', $c['property_types'] ) );
		}
		if ( Spot_AI_Adapter::supports( 'features' ) ) {
			foreach ( $c['features_required'] as $f ) {
				if ( $f === 'near_lake' ) {
					continue; // Evaluated by distance below.
				}
				$tax[] = array( 'taxonomy' => $t['feature'], 'field' => 'slug', 'terms' => array( str_replace( '_', '-', $f ) ) );
			}
		}

		$ids = get_posts( array(
			'post_type' => Spot_AI_Adapter::get( 'post_type', 'post' ), 'post_status' => 'publish', 'fields' => 'ids',
			'posts_per_page' => 200, 'no_found_rows' => true, 'orderby' => 'ID',
			'meta_query' => count( $meta ) > 1 ? $meta : array(),
			'tax_query'  => count( $tax ) > 1 ? $tax : array(),
		) );

		if ( $center ) { // "Within N miles of <city>": straight-line distance from the middle of that city's listings.
			$radius = (float) $c['radius_miles'];
			$ids    = array_values( array_filter( $ids, function ( $id ) use ( $center, $radius ) {
				$ll = Spot_AI_Search::listing_latlng( $id );
				return $ll && Spot_AI_Search::distance_miles( $ll[0], $ll[1], $center[0], $center[1] ) <= $radius;
			} ) );
		}

		if ( in_array( 'near_lake', $c['features_required'], true ) && ! $skip_lake ) {
			$ids = array_values( array_filter( $ids, function ( $id ) {
				$d = Spot_AI_Search::lake_distance( $id );
				return $d !== null && $d <= Spot_AI_Search::LAKE_RADIUS_MILES;
			} ) );
		}
		return $ids;
	}

	public static function listing_latlng( $post_id ) {
		return Spot_AI_Adapter::latlng( $post_id );
	}

	public static function distance_miles( $lat, $lng, $lat0, $lng0 ) {
		$dl = deg2rad( $lat - $lat0 );
		$dn = deg2rad( $lng - $lng0 );
		$a  = sin( $dl / 2 ) ** 2 + cos( deg2rad( $lat0 ) ) * cos( deg2rad( $lat ) ) * sin( $dn / 2 ) ** 2;
		return 3958.8 * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	public static function lake_distance( $post_id ) {
		$ll = self::listing_latlng( $post_id );
		if ( ! $ll ) {
			return null;
		}
		list( $lat0, $lng0 ) = self::lake_point();
		return self::distance_miles( $ll[0], $ll[1], $lat0, $lng0 );
	}

	/** Middle of a city's listings (average coordinates), or null if none have coordinates. */
	public static function city_centroid( $city ) {
		static $memo = array();
		if ( array_key_exists( $city, $memo ) ) {
			return $memo[ $city ];
		}
		$ids = get_posts( array(
			'post_type' => Spot_AI_Adapter::get( 'post_type', 'post' ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 200, 'no_found_rows' => true,
			'meta_query' => self::demo_only() ? array( array( 'key' => 'spot_demo_sample', 'value' => '1' ) ) : array(),
			'tax_query'  => array( array( 'taxonomy' => Spot_AI_Adapter::get( 'tax.location', 'category' ), 'field' => 'name', 'terms' => array( $city ) ) ),
		) );
		$sum = array( 0, 0 );
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( $ll = self::listing_latlng( $id ) ) {
				$sum[0] += $ll[0];
				$sum[1] += $ll[1];
				$n++;
			}
		}
		return $memo[ $city ] = $n ? array( $sum[0] / $n, $sum[1] / $n ) : null;
	}

	/** Run a search. Returns [ 'total' => int, 'results' => card[] ]. */
	public static function run( array $c ) {
		$cards = self::sorted_cards( $c );
		return array( 'total' => count( $cards ), 'results' => array_slice( $cards, 0, self::PAGE_SIZE ) );
	}

	/** Matching listing IDs, best match first (used to order the stock Houzez results page). */
	public static function ranked_ids( array $c ) {
		return array_column( self::sorted_cards( $c ), 'id' );
	}

	private static function sorted_cards( array $c ) {
		$ids   = self::query_ids( $c );
		$cards = array();
		foreach ( $ids as $id ) {
			$cards[] = self::card( $id, $c );
		}
		usort( $cards, function ( $a, $b ) use ( $c ) {
			switch ( $c['sort'] ) {
				case 'price_asc':
					return $a['price_raw'] <=> $b['price_raw'];
				case 'price_desc':
					return $b['price_raw'] <=> $a['price_raw'];
				case 'newest':
					return $b['id'] <=> $a['id'];
			}
			return ( $b['score'] <=> $a['score'] ) ?: ( $a['price_raw'] <=> $b['price_raw'] );
		} );
		return $cards;
	}

	public static function count( array $c ) {
		return count( self::query_ids( $c ) );
	}

	private static function card( $id, array $c ) {
		$t     = Spot_AI_Adapter::get( 'tax', array() );
		$m     = Spot_AI_Adapter::get( 'meta', array() );
		$terms = function ( $tax, $field ) use ( $id ) {
			if ( ! $tax || ! taxonomy_exists( $tax ) ) {
				return array();
			}
			$r = wp_get_post_terms( $id, $tax, array( 'fields' => $field ) );
			return is_wp_error( $r ) ? array() : $r;
		};
		$price = Spot_AI_Adapter::price( $id );
		$feat  = $terms( isset( $t['feature'] ) ? $t['feature'] : '', 'slugs' );
		$pick  = function ( $tax, $wanted ) use ( $terms ) {
			// Several terms may apply (e.g. state AND city): show the one the visitor asked for, else the most specific.
			$objs = $tax ? $terms( $tax, 'all' ) : array();
			$best = null;
			foreach ( $objs as $o ) {
				$name = html_entity_decode( $o->name, ENT_QUOTES, 'UTF-8' );
				if ( in_array( $o->slug, (array) $wanted, true ) || in_array( $name, (array) $wanted, true ) ) {
					return $name;
				}
				if ( $best === null || ( $o->parent && ! $best->parent ) ) {
					$best = $o;
				}
			}
			return $best ? html_entity_decode( $best->name, ENT_QUOTES, 'UTF-8' ) : null;
		};
		$type  = ( $v = $pick( isset( $t['type'] ) ? $t['type'] : '', $c['property_types'] ) ) ? array( $v ) : array();
		$city  = ( $v = $pick( isset( $t['location'] ) ? $t['location'] : '', $c['location'] ? array( $c['location'] ) : array() ) ) ? array( $v ) : array();
		$state = array();
		if ( Spot_AI_Adapter::get( 'id' ) === 'houzez' ) {
			$state = $terms( 'property_state', 'names' );
		}
		$num = function ( $key ) use ( $id ) {
			return $key ? get_post_meta( $id, $key, true ) : '';
		};

		$matched = array();
		$missed  = array();
		foreach ( $c['features_preferred'] as $f ) {
			if ( $f === 'near_lake' ) {
				$d = self::lake_distance( $id );
				if ( $d !== null && $d <= self::LAKE_RADIUS_MILES ) {
					$matched[] = sprintf( 'About %.1f mi from the lake (straight-line)', $d );
				} else {
					$missed[] = 'near the lake';
				}
			} elseif ( in_array( str_replace( '_', '-', $f ), $feat, true ) ) {
				$matched[] = 'Listed with ' . strtolower( Spot_AI_Criteria::feature_label( $f ) );
			} else {
				$missed[] = strtolower( Spot_AI_Criteria::feature_label( $f ) );
			}
		}

		$img = get_the_post_thumbnail_url( $id, 'medium_large' );
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'url'       => get_permalink( $id ),
			'price'     => $price ? Spot_AI_Criteria::format_price( $price ) . Spot_AI_Adapter::get( 'price_suffix', '' ) : '',
			'price_raw' => $price,
			'beds'      => (int) $num( isset( $m['beds'] ) ? $m['beds'] : '' ),
			'baths'     => (float) $num( isset( $m['baths'] ) ? $m['baths'] : '' ),
			'sqft'      => (int) $num( isset( $m['area'] ) ? $m['area'] : '' ),
			'type'      => $type ? $type[0] : '',
			'city'      => $city ? $city[0] : '',
			'state'     => $state ? $state[0] : '',
			'address'   => (string) $num( isset( $m['address'] ) ? $m['address'] : '' ),
			'image'     => $img ? $img : null,
			'features'  => array_map( function ( $s ) { return ucwords( str_replace( '-', ' ', $s ) ); }, $feat ),
			'matched'   => $matched,
			'missed'    => $missed,
			'score'     => count( $matched ),
			'is_sample' => get_post_meta( $id, 'spot_demo_sample', true ) === '1',
		);
	}

	private static $feature_words = array(
		'backyard' => 'a backyard', 'garage' => 'a garage', 'fireplace' => 'a fireplace', 'pool' => 'a pool',
		'basement' => 'a basement', 'updated_kitchen' => 'an updated kitchen', 'near_lake' => 'a spot near the lake',
	);

	/** "4+ bed house with a pool in Lake Zurich under $400,000" */
	public static function describe_search( array $c, $with_noun = true ) {
		$bits = array();
		if ( $c['bedrooms_min'] !== null ) {
			$bits[] = $c['bedrooms_min'] . '+ bed';
		}
		$bits[] = $c['property_types'] ? strtolower( implode( ' or ', array_map( array( 'Spot_AI_Criteria', 'type_label' ), $c['property_types'] ) ) ) : ( $with_noun ? Spot_AI_Adapter::noun() : '' );
		$with   = array();
		foreach ( $c['features_required'] as $f ) {
			$with[] = isset( self::$feature_words[ $f ] ) ? self::$feature_words[ $f ] : strtolower( Spot_AI_Criteria::feature_label( $f ) );
		}
		if ( ! empty( $c['garages_min'] ) ) {
			$with[] = $c['garages_min'] . '+ garage spaces';
		}
		$text = trim( implode( ' ', $bits ) );
		if ( $with ) {
			$text .= ' with ' . implode( ' and ', $with );
		}
		$text = trim( $text );
		if ( $c['location'] ) {
			$text .= ( $text === '' ? '' : ' ' ) . ( ! empty( $c['radius_miles'] ) ? 'within ' . $c['radius_miles'] . ' miles of ' : 'in ' ) . $c['location'];
		}
		if ( ! empty( $c['listing_status'] ) ) {
			$text .= $c['listing_status'] === 'rent' ? ' for rent' : ' for sale';
		}
		if ( $c['price_min'] && $c['price_max'] ) {
			$text .= ' between ' . Spot_AI_Criteria::format_price( $c['price_min'] ) . ' and ' . Spot_AI_Criteria::format_price( $c['price_max'] );
		} elseif ( $c['price_max'] ) {
			$text .= ' under ' . Spot_AI_Criteria::format_price( $c['price_max'] );
		} elseif ( $c['price_min'] ) {
			$text .= ' over ' . Spot_AI_Criteria::format_price( $c['price_min'] );
		}
		return $text;
	}

	/** Turn a relaxation level into a plain verb phrase for the visitor. */
	private static function phrase( $subject, $raw ) {
		switch ( $subject ) {
			case 'the location':
				return 'look ' . $raw;
			case 'the budget':
				return 'stretch the budget to ' . preg_replace( '/^a budget of /', '', $raw );
			case 'the number of bedrooms':
			case 'the garage':
				return strpos( $raw, 'no ' ) === 0 ? 'drop the garage requirement' : 'go down to ' . $raw;
			case 'the ' . Spot_AI_Adapter::get( 'type_word', 'home type' ):
				return 'include any ' . Spot_AI_Adapter::get( 'type_word', 'home type' );
		}
		return 'drop the ' . $raw . ' (still a preference)';
	}

	/** Merge two relaxation patches (they always touch different criteria). */
	private static function merge_patches( array $a, array $b ) {
		$clear = array_values( array_unique( array_merge( isset( $a['clear'] ) ? $a['clear'] : array(), isset( $b['clear'] ) ? $b['clear'] : array() ) ) );
		$out   = array_merge( $a, $b );
		if ( $clear ) {
			$out['clear'] = $clear;
		}
		return $out;
	}

	/**
	 * "Reality check": when a search finds nothing, work out which requirements block it and offer
	 * a few concrete trade-offs, each with the exact number of homes it would give. Pure counting,
	 * no extra AI call. Each option is a delta patch the client can apply.
	 *
	 * @return array{headline:string, question:?string, options:array}
	 */
	public static function compromise( array $c ) {
		// 1. Candidate relaxations, mildest level first: [ subject, [ [label, patch], ... ] ].
		$cands = array();
		if ( $c['location'] && empty( $c['radius_miles'] ) && self::city_centroid( $c['location'] ) ) {
			$lv = array();
			foreach ( array( 10, 20, 35 ) as $r ) {
				$lv[] = array( 'within ' . $r . ' miles of ' . $c['location'], array( 'radius_miles' => $r ) );
			}
			$cands[] = array( 'the location', $lv );
		}
		if ( $c['price_max'] ) {
			$lv = array();
			foreach ( array( 1.15, 1.3, 1.5 ) as $f ) {
				$new  = (int) ( ceil( $c['price_max'] * $f / 10000 ) * 10000 );
				$lv[] = array( 'a budget of ' . Spot_AI_Criteria::format_price( $new ), array( 'price_max' => $new ) );
			}
			$cands[] = array( 'the budget', $lv );
		}
		if ( $c['bedrooms_min'] > 1 ) {
			$lv = array();
			for ( $b = $c['bedrooms_min'] - 1; $b >= max( 1, $c['bedrooms_min'] - 3 ); $b-- ) {
				$lv[] = array( $b . ' bedroom' . ( $b > 1 ? 's' : '' ), array( 'bedrooms_min' => $b, 'clear' => array( 'bedrooms_max' ) ) );
			}
			$cands[] = array( 'the number of bedrooms', $lv );
		}
		if ( ! empty( $c['garages_min'] ) ) {
			$lv = array();
			for ( $g = $c['garages_min'] - 1; $g >= 0; $g-- ) {
				$lv[] = array( $g ? $g . ' garage space' . ( $g > 1 ? 's' : '' ) : 'no garage requirement', array( 'garages_min' => $g ) );
			}
			$cands[] = array( 'the garage', $lv );
		}
		foreach ( $c['features_required'] as $f ) {
			$rest  = array_values( array_diff( $c['features_required'], array( $f ) ) );
			$word  = isset( self::$feature_words[ $f ] ) ? self::$feature_words[ $f ] : strtolower( Spot_AI_Criteria::feature_label( $f ) );
			$cands[] = array( 'the ' . strtolower( Spot_AI_Criteria::feature_label( $f ) ), array( array( preg_replace( '/^(an?) /', '', $word ),
				array( 'clear' => array( 'features_required' ), 'features_required' => $rest, 'features_preferred' => array_values( array_unique( array_merge( $c['features_preferred'], array( $f ) ) ) ) ) ) ) );
		}
		if ( $c['property_types'] ) {
			$cands[] = array( 'the ' . Spot_AI_Adapter::get( 'type_word', 'home type' ), array( array( 'any ' . Spot_AI_Adapter::get( 'type_word', 'home type' ), array( 'clear' => array( 'property_types' ) ) ) ) );
		}

		$count_with = function ( array $patch ) use ( $c ) {
			list( $delta ) = Spot_AI_Criteria::sanitize_delta( $patch + array( 'intent' => 'search' ) );
			return self::count( Spot_AI_Criteria::merge( $c, $delta ) );
		};

		// 2. Single trade-offs: the mildest level of each that gives at least one home.
		$options = array();
		foreach ( $cands as $i => $cand ) {
			foreach ( $cand[1] as $lvl ) {
				if ( ( $n = $count_with( $lvl[1] ) ) > 0 ) {
					$options[] = array( 'subject' => $cand[0], 'raw' => $lvl[0], 'label' => '', 'patch' => $lvl[1], 'count' => $n );
					break;
				}
			}
		}
		foreach ( $options as &$o ) {
			$o['label'] = ucfirst( self::phrase( $o['subject'], $o['raw'] ) );
		}
		unset( $o );

		// 3. Not enough single options: try two trade-offs together.
		if ( count( $options ) < 2 && count( $cands ) >= 2 ) {
			$pairs = array();
			for ( $i = 0; $i < count( $cands ); $i++ ) {
				for ( $j = $i + 1; $j < count( $cands ); $j++ ) {
					$best = null;
					foreach ( $cands[ $i ][1] as $li => $a ) {
						foreach ( $cands[ $j ][1] as $lj => $b ) {
							$patch = self::merge_patches( $a[1], $b[1] );
							if ( ( $n = $count_with( $patch ) ) > 0 && ( $best === null || $li + $lj < $best[0] ) ) {
								$best = array( $li + $lj, $n, $patch, $a[0], $b[0] );
							}
						}
					}
					if ( $best ) {
						$pairs[] = array(
							'subject' => $cands[ $i ][0] . ' and ' . $cands[ $j ][0],
							'label'   => ucfirst( self::phrase( $cands[ $i ][0], $best[3] ) . ' and ' . self::phrase( $cands[ $j ][0], $best[4] ) ),
							'patch'   => $best[2],
							'count'   => $best[1],
							'cost'    => $best[0],
							'a'       => $cands[ $i ][0],
							'b'       => $cands[ $j ][0],
						);
					}
				}
			}
			usort( $pairs, function ( $x, $y ) { return array( $x['cost'], -$x['count'] ) <=> array( $y['cost'], -$y['count'] ); } );
			foreach ( array_slice( $pairs, 0, 2 - count( $options ) + 1 ) as $p ) {
				$options[] = $p;
			}
		}

		// 4. Last resort: relax everything step by step.
		if ( ! $options ) {
			$patch = array( 'clear' => array() );
			$steps = array(
				array( 'price_max', 'the budget', array( 'price_min', 'price_max' ) ),
				array( 'bedrooms_min', 'the bedroom count', array( 'bedrooms_min', 'bedrooms_max' ) ),
				array( 'garages_min', 'the garage', array( 'garages_min' ) ),
				array( 'features_required', 'must-have features', array( 'features_required' ) ),
				array( 'property_types', 'the ' . Spot_AI_Adapter::get( 'type_word', 'home type' ), array( 'property_types' ) ),
				array( 'location', 'the location', array( 'location', 'radius_miles' ) ),
			);
			$parts = array();
			foreach ( $steps as $st ) {
				if ( empty( $c[ $st[0] ] ) ) {
					continue;
				}
				$patch['clear'] = array_merge( $patch['clear'], $st[2] );
				$parts[]        = $st[1];
				if ( ( $n = $count_with( $patch ) ) > 0 ) {
					$options[] = array( 'subject' => 'a few things', 'raw' => '', 'label' => 'Relax ' . implode( ' and ', $parts ), 'patch' => $patch, 'count' => $n );
					break;
				}
			}
		}

		usort( $options, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
		$options = array_slice( $options, 0, 3 );

		// The question names the distinct things the top two options would change.
		$subjects = array();
		foreach ( array_slice( $options, 0, 2 ) as $o ) {
			foreach ( isset( $o['a'] ) ? array( $o['a'], $o['b'] ) : array( $o['subject'] ) as $sub ) {
				if ( ! in_array( $sub, $subjects, true ) ) {
					$subjects[] = $sub;
				}
			}
		}
		foreach ( $options as &$o ) {
			unset( $o['cost'], $o['a'], $o['b'], $o['raw'] );
		}
		unset( $o );

		$question = null;
		if ( count( $options ) >= 2 && count( $subjects ) === 2 ) {
			$question = 'Which matters more to you: ' . $subjects[0] . ' or ' . $subjects[1] . '?';
		} elseif ( count( $options ) >= 2 && count( $subjects ) >= 3 ) {
			$last     = array_pop( $subjects );
			$question = 'Which would you rather give up first: ' . implode( ', ', $subjects ) . ' or ' . $last . '?';
		}
		return array(
			'headline' => Spot_AI_Adapter::get( 'seller', false )
				? "I couldn't find a " . self::describe_search( $c ) . '.'
				: "I couldn't find any " . Spot_AI_Adapter::noun( true ) . ' for "' . self::describe_search( $c, false ) . '".',
			'question' => $question,
			'options'  => $options,
		);
	}

	/** Backwards-compatible flat list (label includes the count). */
	public static function broaden_options( array $c ) {
		$out = array();
		foreach ( self::compromise( $c )['options'] as $o ) {
			$out[] = array( 'label' => $o['label'] . ' (' . $o['count'] . ' ' . ( $o['count'] === 1 ? 'home' : 'homes' ) . ')', 'patch' => $o['patch'], 'count' => $o['count'] );
		}
		return $out;
	}
}
