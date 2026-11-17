<?php
/**
 * Fictional sample listings for the demo, clearly labelled "Sample data".
 * They are real Houzez `property` posts using the genuine fave_property_* meta,
 * so the demo exercises the same schema a live site would use.
 * Addresses, prices and coordinates are invented; none is a real or available home.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Sample_Data {

	const META = 'spot_demo_sample';

	private static $cities = array(
		// name => [lat, lng, price factor]
		'Lake Zurich'      => array( 42.1945, -88.0820, 1.00 ),
		'Barrington'       => array( 42.1537, -88.1362, 1.35 ),
		'Libertyville'     => array( 42.2831, -87.9531, 1.10 ),
		'Mundelein'        => array( 42.2631, -88.0034, 0.85 ),
		'Palatine'         => array( 42.1103, -88.0342, 0.80 ),
		'Arlington Heights' => array( 42.0884, -87.9806, 0.90 ),
	);

	private static $streets = array( 'Maple', 'Oak', 'Cedar', 'Willow', 'Birch', 'Elm', 'Prairie', 'Harbor', 'Sunset', 'Ridge', 'Meadow', 'Hickory' );
	private static $suffix  = array( 'Ln', 'Dr', 'Ct', 'Ave', 'Rd' );

	public static function seed() {
		if ( ! post_type_exists( 'property' ) ) {
			return new WP_Error( 'no_houzez', 'The Houzez property post type is not registered.' );
		}
		mt_srand( 20240611 ); // Deterministic dataset.

		$created = 0;
		$n       = 0;
		foreach ( self::$cities as $city => $info ) {
			$per_city = ( $city === 'Lake Zurich' ) ? 9 : ( $city === 'Barrington' ? 7 : 5 );
			for ( $i = 0; $i < $per_city; $i++ ) {
				$n++;
				$sample_id = 'SAMPLE-' . str_pad( $n, 3, '0', STR_PAD_LEFT );
				if ( self::exists( $sample_id ) ) {
					continue;
				}
				$created += self::create( $sample_id, $city, $info, $i ) ? 1 : 0;
			}
		}
		self::add_photos();
		return $created;
	}

	/** Demo photos bundled in sample-images/ (Houzez theme demo stock photos; for demonstration only). */
	private static $photo_sets = array(
		'house'     => array( '052', '053', '054', '055', '110', '111', '049', '050', '021', '024' ),
		'condo'     => array( '004', '006', '009', '016', '020', '030', '040', '045', '047', '026' ),
		'townhouse' => array( '049', '025', '050', '021', '024', '055' ),
	);
	private static $interior = array( '016', '020', '030', '040', '045', '047', '026', '006' );

	private static function import_photo( $code ) {
		$key = 'home-' . $code . '.jpg';
		$hit = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1,
			'meta_query' => array( array( 'key' => 'spot_demo_photo', 'value' => $key ) ) ) );
		if ( $hit ) {
			return (int) $hit[0];
		}
		$src = SPOT_AI_DIR . 'sample-images/' . $key;
		if ( ! file_exists( $src ) ) {
			return 0;
		}
		$up   = wp_upload_dir();
		$dest = trailingslashit( $up['path'] ) . 'spot-demo-' . $key;
		if ( ! copy( $src, $dest ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = wp_insert_attachment( array(
			'post_mime_type' => 'image/jpeg', 'post_title' => 'Sample home photo ' . $code, 'post_status' => 'inherit',
		), $dest );
		if ( is_wp_error( $att ) || ! $att ) {
			return 0;
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $dest ) );
		update_post_meta( $att, 'spot_demo_photo', $key );
		return (int) $att;
	}

	/** Give every sample listing a featured image and a small gallery (idempotent). */
	public static function add_photos() {
		$ids = get_posts( array( 'post_type' => 'property', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC',
			'meta_query' => array( array( 'key' => self::META, 'value' => '1' ) ) ) );
		$n = 0;
		foreach ( $ids as $i => $id ) {
			if ( has_post_thumbnail( $id ) ) {
				continue;
			}
			$t    = wp_get_post_terms( $id, 'property_type', array( 'fields' => 'slugs' ) );
			$kind = ( $t && ! is_wp_error( $t ) && isset( self::$photo_sets[ $t[0] ] ) ) ? $t[0] : 'house';
			$set  = self::$photo_sets[ $kind ];
			$main = self::import_photo( $set[ $i % count( $set ) ] );
			if ( ! $main ) {
				continue;
			}
			set_post_thumbnail( $id, $main );
			delete_post_meta( $id, 'fave_property_images' );
			add_post_meta( $id, 'fave_property_images', $main );
			foreach ( array( self::$interior[ $i % count( self::$interior ) ], self::$interior[ ( $i + 3 ) % count( self::$interior ) ] ) as $code ) {
				$extra = self::import_photo( $code );
				if ( $extra && $extra !== $main ) {
					add_post_meta( $id, 'fave_property_images', $extra );
				}
			}
			$n++;
		}
		return $n;
	}

	private static function exists( $sample_id ) {
		$q = get_posts( array( 'post_type' => 'property', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
			'meta_query' => array( array( 'key' => 'fave_property_id', 'value' => $sample_id ) ) ) );
		return ! empty( $q );
	}

	private static function create( $sample_id, $city, $info, $i ) {
		$type_roll = mt_rand( 1, 10 );
		$type      = $type_roll <= 4 ? 'House' : ( $type_roll <= 7 ? 'Condo' : 'Townhouse' );

		switch ( $type ) {
			case 'Condo':
				$beds = mt_rand( 1, 3 );
				$base = 210000 + $beds * 65000;
				$size = 750 + $beds * 280;
				break;
			case 'Townhouse':
				$beds = mt_rand( 2, 4 );
				$base = 290000 + $beds * 75000;
				$size = 1300 + $beds * 330;
				break;
			default:
				$beds = mt_rand( 3, 5 );
				$base = 380000 + $beds * 95000;
				$size = 1700 + $beds * 420;
		}
		$price = (int) ( round( $base * $info[2] * ( 0.9 + mt_rand( 0, 25 ) / 100 ) / 5000 ) * 5000 );
		$baths = max( 1, $beds - mt_rand( 0, 1 ) ) + ( mt_rand( 0, 3 ) === 0 ? 0.5 : 0 );

		$features = array();
		if ( $type !== 'Condo' && mt_rand( 1, 10 ) <= 8 ) {
			$features[] = 'Backyard';
		}
		if ( $type === 'Condo' ? mt_rand( 1, 10 ) <= 4 : mt_rand( 1, 10 ) <= 8 ) {
			$features[] = 'Garage';
		}
		if ( mt_rand( 1, 10 ) <= 4 ) {
			$features[] = 'Fireplace';
		}
		if ( $type === 'House' && mt_rand( 1, 10 ) <= 2 ) {
			$features[] = 'Pool';
		}
		if ( $type !== 'Condo' && mt_rand( 1, 10 ) <= 5 ) {
			$features[] = 'Basement';
		}
		if ( mt_rand( 1, 10 ) <= 4 ) {
			$features[] = 'Updated Kitchen';
		}

		// Keep Lake Zurich listings within ~2.5 miles of the lake point; others stay around their town.
		$spread = $city === 'Lake Zurich' ? 0.035 : 0.03;
		$lat    = $info[0] + ( mt_rand( -1000, 1000 ) / 1000 ) * $spread;
		$lng    = $info[1] + ( mt_rand( -1000, 1000 ) / 1000 ) * $spread * 1.3;

		$street  = mt_rand( 100, 2400 ) . ' ' . self::$streets[ mt_rand( 0, count( self::$streets ) - 1 ) ] . ' ' . self::$suffix[ mt_rand( 0, count( self::$suffix ) - 1 ) ];
		$label   = $beds . '-Bedroom ' . $type . ' in ' . $city;
		$content = 'SAMPLE DATA - fictional listing for demonstration only. Not a real or available property. '
			. 'Details such as price, address and features were generated to exercise the search demo.';

		$post_id = wp_insert_post( array(
			'post_type' => 'property', 'post_status' => 'publish', 'post_title' => $label . ' (Sample)', 'post_content' => $content,
			'post_author' => 1,
		) );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}

		$meta = array(
			self::META                  => '1',
			'fave_property_id'          => $sample_id,
			'fave_property_price'       => $price,
			'fave_property_bedrooms'    => $beds,
			'fave_property_bathrooms'   => $baths,
			'fave_property_size'        => $size,
			'fave_property_size_prefix' => 'sq ft',
			'fave_property_garage'      => in_array( 'Garage', $features, true ) ? 2 : 0,
			'fave_property_address'     => $street,
			'fave_property_map_address' => $street . ', ' . $city . ', IL',
			'fave_property_location'    => round( $lat, 6 ) . ',' . round( $lng, 6 ),
			'fave_property_map'         => '1',
			'fave_property_zip'         => '60' . mt_rand( 10, 99 ) . '0',
			'fave_property_country'     => 'US',
			// Houzez sorts by these; a missing fave_featured row would hide the listing from its results page.
			'fave_featured'             => '0',
			'fave_loggedintoview'       => '0',
			'houzez_total_property_views' => '0',
			'fave_agent_display_option' => 'author_info',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}

		wp_set_object_terms( $post_id, $city, 'property_city' );
		wp_set_object_terms( $post_id, 'Illinois', 'property_state' );
		wp_set_object_terms( $post_id, $type, 'property_type' );
		wp_set_object_terms( $post_id, 'For Sale', 'property_status' );
		wp_set_object_terms( $post_id, 'Sample Data', 'property_label' );
		if ( $features ) {
			wp_set_object_terms( $post_id, $features, 'property_feature' );
		}
		return true;
	}

	public static function remove() {
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1,
			'meta_query' => array( array( 'key' => 'spot_demo_photo', 'compare' => 'EXISTS' ) ) ) ) as $att ) {
			wp_delete_attachment( $att, true );
		}
		$ids = get_posts( array( 'post_type' => 'property', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
			'meta_query' => array( array( 'key' => self::META, 'value' => '1' ) ) ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		return count( $ids );
	}
}
