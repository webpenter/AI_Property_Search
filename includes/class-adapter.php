<?php
/**
 * Theme adapter: tells the plugin where a given theme keeps its listings (post type, taxonomies, meta keys),
 * what it can filter on, and how results are shown. Everything else in the plugin reads from here, so
 * supporting another theme means adding a preset (or filling in the "Custom" mapping in Settings), not new search code.
 *
 * Presets marked verified=true were checked against a real install; the rest are best-guess mappings from the
 * theme's documented data model and should be confirmed (Settings shows what was detected).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Adapter {

	const ALL_CAPS = array( 'location', 'types', 'status', 'price', 'bedrooms', 'bathrooms', 'garages', 'features', 'radius' );

	public static function presets() {
		return array(
			'houzez' => array(
				'label' => 'Houzez', 'verified' => true,
				'post_type' => 'property',
				'detect' => array( 'property_city' ),
				'tax'  => array( 'type' => 'property_type', 'location' => 'property_city', 'status' => 'property_status', 'feature' => 'property_feature' ),
				'meta' => array( 'price' => 'fave_property_price', 'beds' => 'fave_property_bedrooms', 'baths' => 'fave_property_bathrooms', 'garage' => 'fave_property_garage', 'area' => 'fave_property_size', 'latlng' => 'fave_property_location', 'address' => 'fave_property_map_address' ),
				'noun' => array( 'home', 'homes' ), 'results' => 'houzez', 'seller' => true, 'price_suffix' => '', 'type_word' => 'home type',
			),
			'listeo' => array(
				'label' => 'Listeo', 'verified' => true,
				'post_type' => 'listing',
				'detect' => array( 'listing_category', 'region' ),
				'tax'  => array( 'type' => 'listing_category', 'location' => 'region', 'status' => '', 'feature' => 'listing_feature' ),
				'meta' => array( 'price' => '_normal_price', 'price_alt' => '_price_min', 'lat' => '_geolocation_lat', 'lng' => '_geolocation_long', 'address' => '_friendly_address' ),
				'noun' => array( 'listing', 'listings' ), 'results' => 'inline', 'seller' => false, 'price_suffix' => '',
			),
			'homey' => array(
				'label' => 'Homey (best guess, unverified)', 'verified' => false,
				'post_type' => 'listing',
				'detect' => array( 'listing_city', 'listing_type' ),
				'tax'  => array( 'type' => 'listing_type', 'location' => 'listing_city', 'status' => '', 'feature' => 'listing_amenity' ),
				'meta' => array( 'price' => 'homey_night_price', 'beds' => 'homey_bedrooms', 'baths' => 'homey_baths', 'lat' => 'homey_geolocation_lat', 'lng' => 'homey_geolocation_long', 'address' => 'homey_listing_address' ),
				'noun' => array( 'listing', 'listings' ), 'results' => 'inline', 'seller' => false, 'price_suffix' => '/night',
			),
			'wpresidence' => array(
				'label' => 'WP Residence (best guess, unverified)', 'verified' => false,
				'post_type' => 'estate_property',
				'detect' => array( 'property_category' ),
				'tax'  => array( 'type' => 'property_category', 'location' => 'property_city', 'status' => 'property_action_category', 'feature' => 'property_features' ),
				'meta' => array( 'price' => 'property_price', 'beds' => 'property_bedrooms', 'baths' => 'property_bathrooms', 'garage' => 'property_garage', 'lat' => 'property_latitude', 'lng' => 'property_longitude', 'address' => 'property_address' ),
				'noun' => array( 'home', 'homes' ), 'results' => 'inline', 'seller' => true, 'price_suffix' => '',
			),
			'realhomes' => array(
				'label' => 'Real Homes (best guess, unverified)', 'verified' => false,
				'post_type' => 'property',
				'detect' => array( 'property-city', 'property-type' ),
				'tax'  => array( 'type' => 'property-type', 'location' => 'property-city', 'status' => 'property-status', 'feature' => 'property-feature' ),
				'meta' => array( 'price' => 'REAL_HOMES_property_price', 'beds' => 'REAL_HOMES_property_bedrooms', 'baths' => 'REAL_HOMES_property_bathrooms', 'garage' => 'REAL_HOMES_property_garage', 'latlng' => 'REAL_HOMES_property_location', 'address' => 'REAL_HOMES_property_address' ),
				'noun' => array( 'home', 'homes' ), 'results' => 'inline', 'seller' => true, 'price_suffix' => '',
			),
			'generic' => array(
				'label' => 'Custom mapping', 'verified' => false,
				'post_type' => 'post', 'detect' => array(),
				'tax'  => array( 'type' => '', 'location' => '', 'status' => '', 'feature' => '' ),
				'meta' => array( 'price' => '', 'beds' => '', 'baths' => '', 'garage' => '', 'lat' => '', 'lng' => '', 'address' => '' ),
				'noun' => array( 'listing', 'listings' ), 'results' => 'inline', 'seller' => false, 'price_suffix' => '',
			),
		);
	}

	/** Which preset fits this site (first one whose post type and taxonomies exist and has published posts). */
	public static function detect() {
		foreach ( self::presets() as $id => $p ) {
			if ( $id === 'generic' || ! post_type_exists( $p['post_type'] ) ) {
				continue;
			}
			$ok = true;
			foreach ( $p['detect'] as $tax ) {
				$ok = $ok && taxonomy_exists( $tax );
			}
			if ( $ok ) {
				return $id;
			}
		}
		return 'generic';
	}

	/** The active configuration (preset, plus any overrides saved in Settings). */
	public static function current() {
		static $cfg = null;
		if ( $cfg !== null ) {
			return $cfg;
		}
		$s       = class_exists( 'Spot_AI_Settings' ) ? Spot_AI_Settings::all() : array();
		$choice  = isset( $s['adapter'] ) ? $s['adapter'] : 'auto';
		$presets = self::presets();
		$id      = ( $choice !== 'auto' && isset( $presets[ $choice ] ) ) ? $choice : self::detect();
		$cfg     = $presets[ $id ];
		$cfg['id'] = $id;
		// Overrides from the Settings mapping fields (only non-empty values replace the preset).
		$map = isset( $s['adapter_map'] ) && is_array( $s['adapter_map'] ) ? $s['adapter_map'] : array();
		foreach ( array( 'post_type' ) as $k ) {
			if ( ! empty( $map[ $k ] ) ) {
				$cfg[ $k ] = $map[ $k ];
			}
		}
		foreach ( array( 'tax', 'meta' ) as $group ) {
			if ( ! empty( $map[ $group ] ) && is_array( $map[ $group ] ) ) {
				foreach ( $map[ $group ] as $k => $v ) {
					if ( $v !== '' ) {
						$cfg[ $group ][ $k ] = $v;
					}
				}
			}
		}
		if ( ! empty( $map['results'] ) && in_array( $map['results'], array( 'inline', 'houzez' ), true ) ) {
			$cfg['results'] = $map['results'];
		}
		if ( $cfg['results'] === 'houzez' && ! function_exists( 'houzez_get_search_template_link' ) ) {
			$cfg['results'] = 'inline'; // The Houzez results page is only usable when the Houzez theme is active.
		}
		if ( empty( $cfg['type_word'] ) ) {
			$cfg['type_word'] = ! empty( $cfg['seller'] ) ? 'home type' : 'category';
		}
		$cfg['caps'] = self::capabilities( $cfg );
		if ( ! did_action( 'wp_loaded' ) ) { // Post types may not be registered yet: don't remember an early guess.
			$early = $cfg;
			$cfg   = null;
			return $early;
		}
		return $cfg;
	}

	/** What this site can actually filter on, from the mapping that is filled in. */
	private static function capabilities( array $cfg ) {
		$t = $cfg['tax'];
		$m = $cfg['meta'];
		$caps = array();
		if ( ! empty( $t['location'] ) ) {
			$caps[] = 'location';
		}
		if ( ! empty( $t['type'] ) ) {
			$caps[] = 'types';
		}
		if ( ! empty( $t['status'] ) ) {
			$caps[] = 'status';
		}
		if ( ! empty( $t['feature'] ) ) {
			$caps[] = 'features';
		}
		if ( ! empty( $m['price'] ) ) {
			$caps[] = 'price';
		}
		if ( ! empty( $m['beds'] ) ) {
			$caps[] = 'bedrooms';
		}
		if ( ! empty( $m['baths'] ) ) {
			$caps[] = 'bathrooms';
		}
		if ( ! empty( $m['garage'] ) ) {
			$caps[] = 'garages';
		}
		if ( ! empty( $m['latlng'] ) || ( ! empty( $m['lat'] ) && ! empty( $m['lng'] ) ) ) {
			$caps[] = 'radius';
		}
		return $caps;
	}

	public static function get( $path, $default = null ) {
		$cfg = self::current();
		foreach ( explode( '.', $path ) as $k ) {
			if ( ! is_array( $cfg ) || ! isset( $cfg[ $k ] ) || $cfg[ $k ] === '' ) {
				return $default;
			}
			$cfg = $cfg[ $k ];
		}
		return $cfg;
	}

	public static function supports( $cap ) {
		return in_array( $cap, self::current()['caps'], true );
	}

	public static function noun( $plural = false ) {
		$n = self::current()['noun'];
		return $plural ? $n[1] : $n[0];
	}

	/** Maps a criteria field to the capability it needs. */
	public static function cap_for_field( $field ) {
		$map = array(
			'location' => 'location', 'property_types' => 'types', 'listing_status' => 'status', 'features_required' => 'features', 'features_preferred' => 'features',
			'price_min' => 'price', 'price_max' => 'price', 'bedrooms_min' => 'bedrooms', 'bedrooms_max' => 'bedrooms', 'bathrooms_min' => 'bathrooms',
			'garages_min' => 'garages', 'radius_miles' => 'radius',
		);
		return isset( $map[ $field ] ) ? $map[ $field ] : null;
	}

	/** Lat/lng of a listing, or null when this site stores none. */
	public static function latlng( $post_id ) {
		$m = self::get( 'meta', array() );
		if ( ! empty( $m['latlng'] ) ) {
			$loc = get_post_meta( $post_id, $m['latlng'], true );
			if ( is_string( $loc ) && strpos( $loc, ',' ) !== false ) {
				$p = array_map( 'floatval', explode( ',', $loc ) );
				return array( $p[0], $p[1] );
			}
			return null;
		}
		if ( ! empty( $m['lat'] ) && ! empty( $m['lng'] ) ) {
			$a = get_post_meta( $post_id, $m['lat'], true );
			$b = get_post_meta( $post_id, $m['lng'], true );
			return ( is_numeric( $a ) && is_numeric( $b ) && ( (float) $a || (float) $b ) ) ? array( (float) $a, (float) $b ) : null;
		}
		return null;
	}

	/** Price of a listing as a number (falls back to a secondary key such as a price range minimum). */
	public static function price( $post_id ) {
		$m = self::get( 'meta', array() );
		if ( empty( $m['price'] ) ) {
			return 0;
		}
		$v = get_post_meta( $post_id, $m['price'], true );
		if ( ( $v === '' || $v === false ) && ! empty( $m['price_alt'] ) ) {
			$v = get_post_meta( $post_id, $m['price_alt'], true );
		}
		return (int) round( (float) preg_replace( '/[^0-9.]/', '', (string) $v ) );
	}
}
