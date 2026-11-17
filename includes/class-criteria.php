<?php
/**
 * Search criteria: allowlisted schema, strict validation and merging.
 * Pure PHP (no WordPress calls) so it can be unit tested standalone.
 *
 * State  = the canonical criteria kept by the app and re-sent by the browser.
 * Delta  = what one user turn changes (from Groq or the fallback parser).
 *          null = unchanged, array/value = replace, `clear` = unset fields.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'SPOT_AI_TESTING' ) ) {
	exit;
}

class Spot_AI_Criteria {

	/** Allowed home types / features (slugs). Defaults suit the demo data; Spot_AI_Catalog::configure() replaces them with the site's real terms. */
	private static $types    = array( 'house', 'condo', 'townhouse' );
	private static $features = array( 'backyard', 'garage', 'fireplace', 'pool', 'basement', 'updated_kitchen', 'near_lake' );

	const STATUSES = array( 'sale', 'rent' );

	public static function types() {
		return self::$types;
	}

	public static function features() {
		return self::$features;
	}

	private static $type_names    = array();
	private static $feature_names = array();

	public static function configure( array $types, array $features, array $type_names = array(), array $feature_names = array() ) {
		self::$types         = array_values( array_unique( array_map( 'strval', $types ) ) );
		self::$features      = array_values( array_unique( array_map( 'strval', $features ) ) );
		self::$type_names    = $type_names;
		self::$feature_names = $feature_names;
	}

	/** Human label for a type slug ("single-family-home" -> "Single Family Home"). */
	public static function type_label( $slug ) {
		return isset( self::$type_names[ $slug ] ) ? self::$type_names[ $slug ] : ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/** Human label for a feature slug ("swimming-pool" -> "Swimming Pool"). */
	public static function feature_label( $slug ) {
		return isset( self::$feature_names[ $slug ] ) ? self::$feature_names[ $slug ] : ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
	}
	const SORTS     = array( 'relevance', 'price_asc', 'price_desc', 'newest' );
	const INTENTS   = array( 'search', 'sell', 'other' );
	const MAX_PRICE = 50000000;

	/** Fields that make up the canonical state. */
	const STATE_FIELDS = array(
		'location', 'property_types', 'bedrooms_min', 'bedrooms_max', 'bathrooms_min',
		'price_min', 'price_max', 'garages_min', 'radius_miles', 'listing_status', 'features_required', 'features_preferred', 'sort',
	);

	public static function empty_state() {
		return array(
			'location'           => null,
			'property_types'     => array(),
			'bedrooms_min'       => null,
			'bedrooms_max'       => null,
			'bathrooms_min'      => null,
			'garages_min'        => null,
			'radius_miles'       => null,
			'listing_status'     => null,
			'price_min'          => null,
			'price_max'          => null,
			'features_required'  => array(),
			'features_preferred' => array(),
			'sort'               => 'relevance',
		);
	}

	public static function empty_delta() {
		return array(
			'intent'                 => 'search',
			'reset'                  => false,
			'clear'                  => array(),
			'location'               => null,
			'property_types'         => null,
			'bedrooms_min'           => null,
			'bedrooms_max'           => null,
			'bathrooms_min'          => null,
			'garages_min'            => null,
			'radius_miles'           => null,
			'listing_status'         => null,
			'price_min'              => null,
			'price_max'              => null,
			'features_required'      => null,
			'features_preferred'     => null,
			'sort'                   => null,
			'unsupported'            => array(),
			'needs_clarification'    => false,
			'clarification_question' => null,
		);
	}

	/** JSON schema sent to Groq (strict mode: every property required, nullable via anyOf). */
	public static function json_schema() {
		$nullable = function ( $schema ) {
			return array( 'anyOf' => array( $schema, array( 'type' => 'null' ) ) );
		};
		$int  = array( 'type' => 'integer' );
		$feat = self::features() ? array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => self::features() ), 'maxItems' => 8 ) : array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 0 );
		$tarr = self::types() ? array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => self::types() ), 'maxItems' => 3 ) : array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 0 );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( self::empty_delta() ),
			'properties'           => array(
				'intent'                 => array( 'type' => 'string', 'enum' => self::INTENTS ),
				'reset'                  => array( 'type' => 'boolean' ),
				'clear'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => self::STATE_FIELDS ), 'maxItems' => 10 ),
				'location'               => $nullable( array( 'type' => 'string' ) ),
				'property_types'         => $nullable( $tarr ),
				'bedrooms_min'           => $nullable( $int ),
				'bedrooms_max'           => $nullable( $int ),
				'bathrooms_min'          => $nullable( $int ),
				'garages_min'            => $nullable( $int ),
				'radius_miles'           => $nullable( $int ),
				'listing_status'         => $nullable( array( 'type' => 'string', 'enum' => self::STATUSES ) ),
				'price_min'              => $nullable( $int ),
				'price_max'              => $nullable( $int ),
				'features_required'      => $nullable( $feat ),
				'features_preferred'     => $nullable( $feat ),
				'sort'                   => $nullable( array( 'type' => 'string', 'enum' => self::SORTS ) ),
				'unsupported'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 5 ),
				'needs_clarification'    => array( 'type' => 'boolean' ),
				'clarification_question' => $nullable( array( 'type' => 'string' ) ),
			),
		);
	}

	public static function clean_text( $value, $max = 120 ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = preg_replace( '/[^\p{L}\p{N}\s\.\,\-\'\&\/\?\!\$\%\(\)]/u', '', strip_tags( $value ) );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		if ( $value === '' ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function int_or_null( $v, $min, $max ) {
		if ( is_bool( $v ) || ! is_numeric( $v ) ) {
			return null;
		}
		$v = (int) round( (float) $v );
		return ( $v >= $min && $v <= $max ) ? $v : null;
	}

	private static function enum_list( $v, array $allowed, &$dropped = null ) {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$out = array();
		foreach ( $v as $item ) {
			if ( is_string( $item ) && in_array( $item, $allowed, true ) ) {
				$out[ $item ] = $item;
			} else {
				$dropped++;
			}
		}
		return array_values( $out );
	}

	/**
	 * Validate untrusted delta (model output). Unknown fields are ignored and
	 * counted; invalid values become null. Never throws.
	 *
	 * @return array{0: array, 1: array} [ delta, warnings ]
	 */
	public static function sanitize_delta( $raw, array $known_cities = array() ) {
		$d    = self::empty_delta();
		$warn = array();
		if ( ! is_array( $raw ) ) {
			return array( $d, array( 'The interpreter returned an unusable response.' ) );
		}

		$unknown = array_diff( array_keys( $raw ), array_keys( $d ) );
		if ( $unknown ) {
			$warn[] = 'Ignored unsupported fields: ' . implode( ', ', array_map( array( __CLASS__, 'safe_key' ), $unknown ) );
		}

		$d['intent'] = ( isset( $raw['intent'] ) && in_array( $raw['intent'], self::INTENTS, true ) ) ? $raw['intent'] : 'search';
		$d['reset']  = ! empty( $raw['reset'] ) && $raw['reset'] === true;

		$dropped = 0;
		$clear   = self::enum_list( isset( $raw['clear'] ) ? $raw['clear'] : array(), self::STATE_FIELDS, $dropped );
		$d['clear'] = $clear ? $clear : array();

		if ( isset( $raw['location'] ) && is_string( $raw['location'] ) ) {
			$loc = self::clean_text( $raw['location'], 60 );
			if ( $loc !== null && $known_cities ) {
				foreach ( $known_cities as $city ) {
					if ( strcasecmp( $city, $loc ) === 0 ) {
						$loc = $city;
						break;
					}
				}
			}
			$d['location'] = $loc;
		}

		$d['property_types']     = self::enum_list( isset( $raw['property_types'] ) ? $raw['property_types'] : null, self::types(), $dropped );
		$d['features_required']  = self::enum_list( isset( $raw['features_required'] ) ? $raw['features_required'] : null, self::features(), $dropped );
		$d['features_preferred'] = self::enum_list( isset( $raw['features_preferred'] ) ? $raw['features_preferred'] : null, self::features(), $dropped );

		foreach ( array( 'bedrooms_min', 'bedrooms_max' ) as $k ) {
			$d[ $k ] = self::int_or_null( isset( $raw[ $k ] ) ? $raw[ $k ] : null, 0, 20 );
		}
		$d['bathrooms_min'] = self::int_or_null( isset( $raw['bathrooms_min'] ) ? $raw['bathrooms_min'] : null, 0, 20 );
		$d['garages_min']   = self::int_or_null( isset( $raw['garages_min'] ) ? $raw['garages_min'] : null, 0, 10 );
		$d['radius_miles']  = self::int_or_null( isset( $raw['radius_miles'] ) ? $raw['radius_miles'] : null, 1, 60 );
		$d['listing_status'] = ( isset( $raw['listing_status'] ) && in_array( $raw['listing_status'], self::STATUSES, true ) ) ? $raw['listing_status'] : null;
		foreach ( array( 'price_min', 'price_max' ) as $k ) {
			$d[ $k ] = self::int_or_null( isset( $raw[ $k ] ) ? $raw[ $k ] : null, 1, self::MAX_PRICE );
			if ( isset( $raw[ $k ] ) && $raw[ $k ] !== null && $d[ $k ] === null ) {
				$warn[] = 'Ignored an invalid price value.';
			}
		}

		$d['sort'] = ( isset( $raw['sort'] ) && in_array( $raw['sort'], self::SORTS, true ) ) ? $raw['sort'] : null;

		if ( isset( $raw['unsupported'] ) && is_array( $raw['unsupported'] ) ) {
			foreach ( array_slice( $raw['unsupported'], 0, 5 ) as $u ) {
				$u = self::clean_text( $u, 80 );
				if ( $u !== null ) {
					$d['unsupported'][] = $u;
				}
			}
		}
		$d['needs_clarification']    = ! empty( $raw['needs_clarification'] ) && $raw['needs_clarification'] === true;
		$d['clarification_question'] = self::clean_text( isset( $raw['clarification_question'] ) ? $raw['clarification_question'] : null, 200 );
		if ( $d['needs_clarification'] && $d['clarification_question'] === null ) {
			$d['needs_clarification'] = false;
		}
		if ( $dropped ) {
			$warn[] = 'Dropped unsupported list values.';
		}
		return array( $d, $warn );
	}

	private static function safe_key( $k ) {
		return preg_replace( '/[^a-z0-9_]/i', '', (string) $k );
	}

	/** Re-validate state coming back from the browser. */
	public static function sanitize_state( $raw, array $known_cities = array() ) {
		if ( ! is_array( $raw ) ) {
			return self::empty_state();
		}
		$in    = array_intersect_key( $raw, array_flip( self::STATE_FIELDS ) );
		list( $d ) = self::sanitize_delta( $in + array( 'intent' => 'search' ), $known_cities );
		return self::merge( self::empty_state(), $d );
	}

	/** Apply a validated delta to a state. Returns the new state. */
	public static function merge( array $state, array $delta ) {
		$state = array_merge( self::empty_state(), $state );
		if ( ! empty( $delta['reset'] ) ) {
			$state = self::empty_state();
		}
		$empty = self::empty_state();
		foreach ( $delta['clear'] as $f ) {
			$state[ $f ] = $empty[ $f ];
		}
		foreach ( self::STATE_FIELDS as $f ) {
			if ( ! array_key_exists( $f, $delta ) || $delta[ $f ] === null ) {
				continue;
			}
			$state[ $f ] = $delta[ $f ];
		}
		// A feature cannot be both required and merely preferred.
		$state['features_preferred'] = array_values( array_diff( $state['features_preferred'], $state['features_required'] ) );

		// Resolve contradictory ranges in favour of the newest statement.
		if ( $state['price_min'] && $state['price_max'] && $state['price_min'] > $state['price_max'] ) {
			if ( $delta['price_max'] !== null ) {
				$state['price_min'] = null;
			} else {
				$state['price_max'] = null;
			}
		}
		if ( $state['bedrooms_min'] !== null && $state['bedrooms_max'] !== null && $state['bedrooms_min'] > $state['bedrooms_max'] ) {
			if ( $delta['bedrooms_max'] !== null ) {
				$state['bedrooms_min'] = null;
			} else {
				$state['bedrooms_max'] = null;
			}
		}
		return $state;
	}

	public static function format_price( $n ) {
		return '$' . number_format( (int) $n );
	}

	/** Human readable chips for the UI: [ field => label ]. */
	public static function describe( array $s ) {
		$out = array();
		if ( $s['location'] ) {
			$out['location'] = ! empty( $s['radius_miles'] ) ? 'Within ' . $s['radius_miles'] . ' mi of ' . $s['location'] : $s['location'];
		}
		if ( ! empty( $s['listing_status'] ) ) {
			$out['listing_status'] = $s['listing_status'] === 'rent' ? 'For rent' : 'For sale';
		}
		if ( $s['property_types'] ) {
			$out['property_types'] = implode( ' / ', array_map( array( __CLASS__, 'type_label' ), $s['property_types'] ) );
		}
		if ( $s['bedrooms_min'] !== null && $s['bedrooms_max'] !== null && $s['bedrooms_min'] !== $s['bedrooms_max'] ) {
			$out['bedrooms_min'] = $s['bedrooms_min'] . '–' . $s['bedrooms_max'] . ' beds';
		} elseif ( $s['bedrooms_min'] !== null ) {
			$out['bedrooms_min'] = $s['bedrooms_min'] . '+ beds';
		} elseif ( $s['bedrooms_max'] !== null ) {
			$out['bedrooms_max'] = 'up to ' . $s['bedrooms_max'] . ' beds';
		}
		if ( $s['bathrooms_min'] !== null ) {
			$out['bathrooms_min'] = $s['bathrooms_min'] . '+ baths';
		}
		if ( $s['garages_min'] !== null && $s['garages_min'] > 0 ) {
			$out['garages_min'] = $s['garages_min'] . '+ garage' . ( $s['garages_min'] > 1 ? 's' : '' );
		}
		if ( $s['price_min'] && $s['price_max'] ) {
			$out['price_max'] = self::format_price( $s['price_min'] ) . ' – ' . self::format_price( $s['price_max'] );
		} elseif ( $s['price_max'] ) {
			$out['price_max'] = 'under ' . self::format_price( $s['price_max'] );
		} elseif ( $s['price_min'] ) {
			$out['price_min'] = self::format_price( $s['price_min'] ) . '+';
		}
		foreach ( $s['features_required'] as $f ) {
			$out['feature_req_' . $f] = 'Must have: ' . strtolower( self::feature_label( $f ) );
		}
		foreach ( $s['features_preferred'] as $f ) {
			$out['feature_pref_' . $f] = 'Nice to have: ' . strtolower( self::feature_label( $f ) );
		}
		return $out;
	}
}
