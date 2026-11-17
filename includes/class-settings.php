<?php
/**
 * Admin settings (Settings screen under "SPOT AI Search"). Constants in wp-config.php still win,
 * so a developer can lock a value; otherwise the value saved here is used.
 * API keys are write-only: they are never printed back into the page.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Settings {

	const OPTION = 'spot_ai_settings';

	public static function defaults() {
		return array(
			'groq_key'     => '',
			'groq_model'   => '',
			'gemini_key'   => '',
			'gemini_model' => '',
			'scope'        => 'auto',
			'seller_url'   => 'https://spot.realty/',
			'seller_text'  => 'SPOT works for sellers on a 1% commission. See how much you could keep.',
			'seller_label' => "Explore SPOT's seller savings calculator",
			'notify_email' => '',
			'rate_limit'   => 40,
			'adapter'      => 'auto',
			'adapter_map'  => array(),
		);
	}

	public static function all() {
		$o = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $o ) ? $o : array() );
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) && $all[ $key ] !== '' ? $all[ $key ] : $default;
	}

	public static function init() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'spot-ai-search', 'SPOT AI Settings', 'Settings', 'manage_options', 'spot-ai-settings', array( __CLASS__, 'page' ) );
		}, 20 );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$saved = false;
		if ( isset( $_POST['spot_ai_save'] ) && check_admin_referer( 'spot_ai_settings' ) ) {
			$cur = self::all();
			$in  = wp_unslash( $_POST );
			$new = $cur;
			// Keys: blank keeps the current key; "clear" box removes it.
			foreach ( array( 'groq_key', 'gemini_key' ) as $k ) {
				if ( ! empty( $in[ $k . '_clear' ] ) ) {
					$new[ $k ] = '';
				} elseif ( ! empty( $in[ $k ] ) ) {
					$new[ $k ] = trim( sanitize_text_field( $in[ $k ] ) );
				}
			}
			foreach ( array( 'groq_model', 'gemini_model' ) as $k ) {
				$new[ $k ] = isset( $in[ $k ] ) ? preg_replace( '/[^A-Za-z0-9._\/:-]/', '', $in[ $k ] ) : '';
			}
			$new['scope']        = isset( $in['scope'] ) && in_array( $in['scope'], array( 'auto', 'all', 'sample' ), true ) ? $in['scope'] : 'auto';
			$new['seller_url']   = isset( $in['seller_url'] ) ? esc_url_raw( $in['seller_url'] ) : $cur['seller_url'];
			$new['seller_text']  = isset( $in['seller_text'] ) ? sanitize_text_field( $in['seller_text'] ) : $cur['seller_text'];
			$new['seller_label'] = isset( $in['seller_label'] ) ? sanitize_text_field( $in['seller_label'] ) : $cur['seller_label'];
			$new['notify_email'] = isset( $in['notify_email'] ) && is_email( $in['notify_email'] ) ? sanitize_email( $in['notify_email'] ) : '';
			$new['rate_limit']   = isset( $in['rate_limit'] ) ? max( 3, min( 200, (int) $in['rate_limit'] ) ) : 20;
			// Theme mapping: preset choice plus optional overrides (blank = use the preset's value).
			$presets        = Spot_AI_Adapter::presets();
			$new['adapter'] = isset( $in['adapter'] ) && ( $in['adapter'] === 'auto' || isset( $presets[ $in['adapter'] ] ) ) ? $in['adapter'] : 'auto';
			$clean          = function ( $v ) {
				return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
			};
			$map = array();
			if ( ! empty( $in['map_post_type'] ) ) {
				$map['post_type'] = $clean( $in['map_post_type'] );
			}
			foreach ( array( 'type', 'location', 'status', 'feature' ) as $k ) {
				if ( ! empty( $in[ 'map_tax_' . $k ] ) ) {
					$map['tax'][ $k ] = $clean( $in[ 'map_tax_' . $k ] );
				}
			}
			foreach ( array( 'price', 'price_alt', 'beds', 'baths', 'garage', 'area', 'latlng', 'lat', 'lng', 'address' ) as $k ) {
				if ( ! empty( $in[ 'map_meta_' . $k ] ) ) {
					$map['meta'][ $k ] = $clean( $in[ 'map_meta_' . $k ] );
				}
			}
			if ( isset( $in['map_results'] ) && in_array( $in['map_results'], array( 'inline', 'houzez' ), true ) ) {
				$map['results'] = $in['map_results'];
			}
			$new['adapter_map'] = $map;
			update_option( self::OPTION, $new, false );
			Spot_AI_Catalog::flush();
			$saved = true;
		}
		$o     = self::all();
		$groq  = Spot_AI_Groq::from_environment();
		$gem   = Spot_AI_Groq::gemini_from_environment();
		$state = function ( $p ) {
			return $p->is_configured() ? '<span style="color:#1a7f37">key saved</span>' : '<span style="color:#b00020">no key</span>';
		};
		echo '<div class="wrap"><h1>SPOT AI Search: Settings</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'spot_ai_settings' );
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th>Groq API key</th><td><input type="password" name="groq_key" class="regular-text" autocomplete="new-password" placeholder="%s"> %s <label><input type="checkbox" name="groq_key_clear" value="1"> remove</label></td></tr>', esc_attr( $groq->is_configured() ? 'leave blank to keep the saved key' : 'gsk_...' ), $state( $groq ) );
		printf( '<tr><th>Groq model</th><td><input type="text" name="groq_model" class="regular-text" value="%s" placeholder="%s"></td></tr>', esc_attr( $o['groq_model'] ), esc_attr( Spot_AI_Groq::DEFAULT_MODEL ) );
		printf( '<tr><th>Gemini API key (backup)</th><td><input type="password" name="gemini_key" class="regular-text" autocomplete="new-password" placeholder="%s"> %s <label><input type="checkbox" name="gemini_key_clear" value="1"> remove</label></td></tr>', esc_attr( $gem->is_configured() ? 'leave blank to keep the saved key' : 'optional' ), $state( $gem ) );
		printf( '<tr><th>Gemini model</th><td><input type="text" name="gemini_model" class="regular-text" value="%s" placeholder="%s"></td></tr>', esc_attr( $o['gemini_model'] ), esc_attr( Spot_AI_Groq::GEMINI_DEFAULT_MODEL ) );
		echo '<tr><th>Listings searched</th><td><select name="scope">';
		foreach ( array( 'auto' => 'Automatic (only labelled sample listings while any exist, otherwise every listing)', 'all' => 'Every published listing', 'sample' => 'Only the labelled sample listings' ) as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $o['scope'], $v, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';
		// Theme mapping
		$presets = Spot_AI_Adapter::presets();
		$cfg     = Spot_AI_Adapter::current();
		$map     = is_array( $o['adapter_map'] ) ? $o['adapter_map'] : array();
		echo '<tr><th>Theme</th><td><select name="adapter"><option value="auto"' . selected( $o['adapter'], 'auto', false ) . '>Automatic (detected: ' . esc_html( $presets[ Spot_AI_Adapter::detect() ]['label'] ) . ')</option>';
		foreach ( $presets as $id => $p ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $o['adapter'], $id, false ), esc_html( $p['label'] ) );
		}
		echo '</select><p class="description">Now using: <strong>' . esc_html( $cfg['label'] ) . '</strong> · listings are "' . esc_html( $cfg['post_type'] ) . '" · can filter on: ' . esc_html( implode( ', ', $cfg['caps'] ) ) . ' · results shown: ' . esc_html( $cfg['results'] === 'houzez' ? 'on the theme\'s results page' : 'in the AI panel' ) . '.</p></td></tr>';
		echo '<tr><th>Mapping (advanced)</th><td><p class="description">Leave blank to use the theme preset. Fill in only to correct or extend it (slugs / meta keys).</p><table>';
		$row = function ( $label, $name, $val, $ph ) {
			printf( '<tr><td style="padding:2px 12px 2px 0">%s</td><td><input type="text" name="%s" value="%s" placeholder="%s" class="regular-text"></td></tr>', esc_html( $label ), esc_attr( $name ), esc_attr( $val ), esc_attr( $ph ) );
		};
		$row( 'Post type', 'map_post_type', isset( $map['post_type'] ) ? $map['post_type'] : '', $cfg['post_type'] );
		foreach ( array( 'type' => 'Type taxonomy', 'location' => 'Location taxonomy', 'status' => 'Status taxonomy (sale/rent)', 'feature' => 'Feature taxonomy' ) as $k => $l ) {
			$row( $l, 'map_tax_' . $k, isset( $map['tax'][ $k ] ) ? $map['tax'][ $k ] : '', isset( $cfg['tax'][ $k ] ) ? $cfg['tax'][ $k ] : '' );
		}
		foreach ( array( 'price' => 'Price meta key', 'price_alt' => 'Second price key', 'beds' => 'Bedrooms meta key', 'baths' => 'Bathrooms meta key', 'garage' => 'Garage meta key', 'latlng' => 'Location meta ("lat,lng")', 'lat' => 'Latitude meta key', 'lng' => 'Longitude meta key', 'address' => 'Address meta key' ) as $k => $l ) {
			$row( $l, 'map_meta_' . $k, isset( $map['meta'][ $k ] ) ? $map['meta'][ $k ] : '', isset( $cfg['meta'][ $k ] ) ? $cfg['meta'][ $k ] : '' );
		}
		echo '<tr><td style="padding:2px 12px 2px 0">Show results</td><td><select name="map_results"><option value="">Preset default</option><option value="inline"' . selected( isset( $map['results'] ) ? $map['results'] : '', 'inline', false ) . '>In the AI panel</option><option value="houzez"' . selected( isset( $map['results'] ) ? $map['results'] : '', 'houzez', false ) . '>On the Houzez results page</option></select></td></tr>';
		echo '</table></td></tr>';
		printf( '<tr><th>Seller link</th><td><input type="url" name="seller_url" class="regular-text" value="%s"></td></tr>', esc_attr( $o['seller_url'] ) );
		printf( '<tr><th>Seller message</th><td><input type="text" name="seller_text" class="large-text" value="%s"></td></tr>', esc_attr( $o['seller_text'] ) );
		printf( '<tr><th>Seller button text</th><td><input type="text" name="seller_label" class="regular-text" value="%s"></td></tr>', esc_attr( $o['seller_label'] ) );
		printf( '<tr><th>Email new inquiries to</th><td><input type="email" name="notify_email" class="regular-text" value="%s"><p class="description">Optional. The visitor is never emailed.</p></td></tr>', esc_attr( $o['notify_email'] ) );
		printf( '<tr><th>Searches per minute, per visitor</th><td><input type="number" name="rate_limit" min="3" max="200" value="%d"></td></tr>', (int) $o['rate_limit'] );
		echo '</table><p><button class="button button-primary" name="spot_ai_save" value="1">Save settings</button></p></form></div>';
	}
}
