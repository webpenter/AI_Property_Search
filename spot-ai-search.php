<?php
/**
 * Plugin Name: SPOT AI Property Search (Demo)
 * Description: Natural-language property search on top of Houzez. An AI model interprets the request; WordPress runs a fixed, validated query and sends the visitor to the stock Houzez results page. Shows on the homepage automatically; also available as [spot_ai_search].
 * Version:     0.8.0
 * Requires PHP: 7.4
 * Text Domain: spot-ai-search
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPOT_AI_VERSION', '0.8.0' );
define( 'SPOT_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPOT_AI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'criteria', 'groq', 'fallback-parser', 'settings', 'adapter', 'catalog', 'search', 'sample-data', 'inquiry', 'houzez-bridge', 'rest' ) as $f ) {
	require_once SPOT_AI_DIR . 'includes/class-' . $f . '.php';
}

register_activation_hook( __FILE__, array( 'Spot_AI_Inquiry', 'install' ) );
add_action( 'rest_api_init', array( 'Spot_AI_REST', 'register' ) );
add_action( 'wp_loaded', array( 'Spot_AI_Houzez_Bridge', 'init' ) );
Spot_AI_Catalog::init();
Spot_AI_Settings::init();
add_action( 'admin_menu', function () {
	add_menu_page( 'SPOT AI Search', 'SPOT AI Search', 'manage_options', 'spot-ai-search', array( 'Spot_AI_Inquiry', 'admin_page' ), 'dashicons-search', 58 );
} );
add_action( 'plugins_loaded', function () { // Ensure the table exists after a plain file copy (no activation).
	if ( get_option( 'spot_ai_db_version' ) !== SPOT_AI_VERSION ) {
		Spot_AI_Inquiry::install();
		update_option( 'spot_ai_db_version', SPOT_AI_VERSION );
	}
} );

function spot_ai_enqueue( $mode ) {
	wp_enqueue_style( 'spot-ai-search', SPOT_AI_URL . 'assets/app.css', array(), SPOT_AI_VERSION );
	wp_enqueue_script( 'spot-ai-search', SPOT_AI_URL . 'assets/app.js', array(), SPOT_AI_VERSION, true );
	wp_localize_script( 'spot-ai-search', 'SPOT_AI', array(
		'restUrl'    => esc_url_raw( rest_url( 'spot-ai/v1/' ) ),
		'sellerUrl'  => defined( 'SPOT_AI_SELLER_URL' ) ? SPOT_AI_SELLER_URL : Spot_AI_Settings::get( 'seller_url', 'https://spot.realty/' ),
		'brand'      => defined( 'SPOT_AI_BRAND' ) ? SPOT_AI_BRAND : 'SPOT Realty',
		'mode'       => $mode,
		'resultsUrl' => Spot_AI_Houzez_Bridge::results_page_url(),
		'examples'   => Spot_AI_Catalog::examples(),
		'placeholder' => Spot_AI_Adapter::get( 'seller', false ) ? 'Describe your dream home…' : 'What are you looking for?',
	) );
}

/** Stand-alone page / any post: results shown inline (original behaviour). */
add_shortcode( 'spot_ai_search', function ( $atts ) {
	$atts = shortcode_atts( array( 'mode' => 'inline' ), $atts );
	$mode = $atts['mode'] === 'redirect' ? 'redirect' : 'inline';
	spot_ai_enqueue( $mode );
	return '<div id="spot-ai-root" class="spot-ai" data-mode="' . esc_attr( $mode ) . '"><noscript>Please enable JavaScript to use AI search.</noscript></div>';
} );

/**
 * Every public page: print a floating AI Search button + panel in the footer. A search goes to the
 * stock Houzez results page (or straight to the property if there is one match).
 * Disable with define( 'SPOT_AI_AUTO_PLACE', false ).
 */
add_action( 'wp_footer', function () {
	if ( defined( 'SPOT_AI_AUTO_PLACE' ) && ! SPOT_AI_AUTO_PLACE ) {
		return;
	}
	if ( is_admin() || is_feed() || wp_script_is( 'spot-ai-search', 'enqueued' ) ) {
		return;
	}
	spot_ai_enqueue( 'redirect' );
	echo '<div id="spot-ai-root" class="spot-ai spot-ai--float" data-mode="redirect" data-place="float" hidden></div>';
	wp_print_styles( 'spot-ai-search' );
	wp_print_scripts( 'spot-ai-search' );
}, 5 );

// Keep the cached city list fresh when listings change.
add_action( 'save_post_property', function () {
	delete_transient( 'spot_ai_cities_demo' );
	delete_transient( 'spot_ai_cities_all' );
} );
