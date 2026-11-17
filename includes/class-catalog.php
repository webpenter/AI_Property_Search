<?php
/**
 * What the site actually has: types, features, statuses and locations come from the active theme's real
 * taxonomies (through the adapter), so the AI can only ever pick things that exist on this site. Also builds
 * example prompts from real listings so every suggested search returns results.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Catalog {

	const CACHE_KEY = 'spot_ai_catalog_v3';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'flush_for_post' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'flush' ) );
		add_action( 'created_term', array( __CLASS__, 'flush' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'apply' ) );
	}

	public static function flush_for_post( $post_id, $post ) {
		if ( $post && $post->post_type === Spot_AI_Adapter::get( 'post_type', 'post' ) ) {
			self::flush();
		}
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'spot_ai_cities_demo' );
		delete_transient( 'spot_ai_cities_all' );
		delete_transient( 'spot_ai_examples_' . Spot_AI_Adapter::get( 'id', 'x' ) );
	}

	/** Push the real terms into the validator. */
	public static function apply() {
		$c = self::get();
		if ( $c['types'] || $c['features'] ) {
			Spot_AI_Criteria::configure( array_keys( $c['types'] ), array_keys( $c['features'] ), $c['types'], $c['features'] );
		} else {
			Spot_AI_Criteria::configure( array(), array() );
		}
	}

	/**
	 * @return array{types:array,features:array,status:array} slug => name maps; status maps sale/rent to a term slug.
	 */
	public static function get() {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$key    = self::CACHE_KEY . '_' . Spot_AI_Adapter::get( 'id', 'x' ) . '_' . md5( wp_json_encode( Spot_AI_Adapter::get( 'tax', array() ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['types'] ) ) {
			return $memo = $cached;
		}
		$terms = function ( $tax ) {
			$out = array();
			if ( ! $tax || ! taxonomy_exists( $tax ) ) {
				return $out;
			}
			$list = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 80 ) );
			if ( ! is_wp_error( $list ) ) {
				foreach ( $list as $t ) {
					$out[ $t->slug ] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			return $out;
		};
		$t        = Spot_AI_Adapter::get( 'tax', array() );
		$types    = $terms( isset( $t['type'] ) ? $t['type'] : '' );
		$features = $terms( isset( $t['feature'] ) ? $t['feature'] : '' );
		$status   = array();
		foreach ( $terms( isset( $t['status'] ) ? $t['status'] : '' ) as $slug => $name ) {
			$k = strtolower( $slug . ' ' . $name );
			if ( ! isset( $status['sale'] ) && preg_match( '/\bsale\b|buy/', $k ) ) {
				$status['sale'] = $slug;
			} elseif ( ! isset( $status['rent'] ) && preg_match( '/rent|lease/', $k ) ) {
				$status['rent'] = $slug;
			}
		}
		// "Near the lake" needs a configured lake point or the sample data; otherwise it is not offered.
		if ( Spot_AI_Adapter::supports( 'radius' ) && ( defined( 'SPOT_AI_LAKE_LAT' ) || Spot_AI_Search::has_sample_data() ) ) {
			$features['near_lake'] = 'Near the lake';
		}
		$memo = array( 'types' => $types, 'features' => $features, 'status' => $status );
		set_transient( $key, $memo, HOUR_IN_SECONDS );
		return $memo;
	}

	/** Example prompts that are guaranteed to return listings, taken from real data. */
	public static function examples() {
		$ckey   = 'spot_ai_examples_' . Spot_AI_Adapter::get( 'id', 'x' );
		$cached = get_transient( $ckey );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$t   = Spot_AI_Adapter::get( 'tax', array() );
		$ids = get_posts( array(
			'post_type' => Spot_AI_Adapter::get( 'post_type', 'post' ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 60, 'orderby' => 'ID', 'no_found_rows' => true,
			'meta_query' => Spot_AI_Search::demo_only() ? array( array( 'key' => 'spot_demo_sample', 'value' => '1' ) ) : array(),
		) );
		$cat  = self::get();
		$beds = Spot_AI_Adapter::get( 'meta.beds' );
		$one  = function ( $tax, $id, $field = 'names' ) {
			if ( ! $tax || ! taxonomy_exists( $tax ) ) {
				return null;
			}
			$r = wp_get_post_terms( $id, $tax, array( 'fields' => $field ) );
			return ( ! is_wp_error( $r ) && $r ) ? $r[0] : null;
		};
		$rows = array();
		foreach ( $ids as $id ) {
			$stat   = ( ! empty( $t['status'] ) && taxonomy_exists( $t['status'] ) ) ? wp_get_post_terms( $id, $t['status'], array( 'fields' => 'slugs' ) ) : array();
			$rows[] = array(
				'city'  => $one( isset( $t['location'] ) ? $t['location'] : '', $id ),
				'type'  => ( $ty = $one( isset( $t['type'] ) ? $t['type'] : '', $id ) ) ? strtolower( $ty ) : null,
				'rent'  => $stat && ! is_wp_error( $stat ) && isset( $cat['status']['rent'] ) && in_array( $cat['status']['rent'], $stat, true ),
				'beds'  => $beds ? (int) get_post_meta( $id, $beds, true ) : 0,
				'price' => Spot_AI_Adapter::price( $id ),
			);
		}
		$noun = Spot_AI_Adapter::noun();
		$ex   = array();
		$used = null;
		foreach ( $rows as $r ) { // 1: bedrooms + type + place + a budget that fits (real-estate style data).
			if ( ! $r['rent'] && $r['beds'] > 0 && $r['type'] && $r['city'] && $r['price'] >= 20000 ) {
				$cap  = (int) ( ceil( $r['price'] * 1.1 / 50000 ) * 50000 );
				$ex[] = $r['beds'] . '-bed ' . $r['type'] . ' in ' . $r['city'] . ' under ' . Spot_AI_Criteria::format_price( $cap );
				$used = $r['city'];
				break;
			}
		}
		foreach ( $rows as $r ) { // 2: a rental in a different city.
			if ( $r['rent'] && $r['type'] && $r['city'] && $r['city'] !== $used ) {
				$ex[] = $r['type'] . ' for rent in ' . $r['city'];
				break;
			}
		}
		if ( ! $ex ) { // Other kinds of sites (directories, rentals): "<category> in <place>", then "<category> under $X".
			foreach ( $rows as $r ) {
				if ( $r['type'] && $r['city'] ) {
					$ex[] = $r['type'] . ' in ' . $r['city'];
					$used = $r['city'];
					break;
				}
			}
			foreach ( $rows as $r ) {
				if ( $r['type'] && $r['price'] > 0 && count( $ex ) < 2 && ( ! isset( $ex[0] ) || stripos( $ex[0], $r['type'] ) === false ) ) {
					$ex[] = $r['type'] . ' under ' . Spot_AI_Criteria::format_price( (int) ceil( $r['price'] * 1.5 / 10 ) * 10 );
				}
			}
			if ( ! $ex && $rows && $rows[0]['type'] ) {
				$ex[] = $rows[0]['type'];
			}
		}
		if ( Spot_AI_Adapter::get( 'seller', false ) ) {
			$ex[] = 'I want to sell my house';
		}
		if ( ! $ex ) {
			$ex[] = 'Show me ' . Spot_AI_Adapter::noun( true );
		}
		set_transient( $ckey, $ex, HOUR_IN_SECONDS );
		return $ex;
	}
}
