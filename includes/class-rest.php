<?php
/**
 * REST layer: POST /spot-ai/v1/search and /inquiry.
 * Pipeline: rate limit -> interpret (Groq, else fallback) -> validate -> merge into state -> fixed query.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_REST {

	const MAX_MESSAGE = 500;

	public static function register() {
		register_rest_route( 'spot-ai/v1', '/search', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'search' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( 'spot-ai/v1', '/nonce', array(
			'methods' => 'GET', 'permission_callback' => '__return_true',
			'callback' => function () {
				// WordPress treats a cookie request without a nonce as logged out; restore the real user so the nonce matches.
				if ( ! is_user_logged_in() && ( $uid = wp_validate_auth_cookie( '', 'logged_in' ) ) ) {
					wp_set_current_user( $uid );
				}
				nocache_headers();
				$r = rest_ensure_response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
				$r->header( 'Cache-Control', 'no-store, max-age=0' );
				$r->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
				return $r;
			},
		) );
		register_rest_route( 'spot-ai/v1', '/inquiry', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'inquiry' ), 'permission_callback' => '__return_true',
		) );
	}

	private static function throttle( $bucket, $limit ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'spot_ai_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return new WP_Error( 'rate_limited', 'Too many requests. Please wait a minute and try again.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private static function check_nonce( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'bad_nonce', 'Your session expired. Please reload the page.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function search( WP_REST_Request $req ) {
		$ok = self::check_nonce( $req );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$limit = defined( 'SPOT_AI_RATE_LIMIT' ) ? (int) SPOT_AI_RATE_LIMIT : (int) Spot_AI_Settings::get( 'rate_limit', 40 );
		$ok    = self::throttle( 'search', $limit );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$body    = $req->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$message = isset( $body['message'] ) && is_string( $body['message'] ) ? trim( $body['message'] ) : '';
		if ( strlen( $message ) > self::MAX_MESSAGE ) {
			return new WP_Error( 'too_long', 'Please keep your request under ' . self::MAX_MESSAGE . ' characters.', array( 'status' => 400 ) );
		}

		$cities = Spot_AI_Search::known_cities();
		$state  = Spot_AI_Criteria::sanitize_state( isset( $body['criteria'] ) ? $body['criteria'] : array(), $cities );

		$interpreter = 'none';
		$ai_error    = null;
		$warnings    = array();
		$delta       = Spot_AI_Criteria::empty_delta();

		if ( $message !== '' ) {
			// Provider chain: Groq first, Gemini if Groq fails, then the offline parser.
			$errors = array();
			foreach ( array( Spot_AI_Groq::from_environment(), Spot_AI_Groq::gemini_from_environment() ) as $provider ) {
				if ( ! $provider->is_configured() ) {
					continue;
				}
				// A provider that just reported quota/auth trouble is skipped for a few minutes instead of slowing every search.
				if ( get_transient( 'spot_ai_skip_' . $provider->label() ) ) {
					$errors[] = $provider->label() . ':skipped';
					continue;
				}
				$res = $provider->interpret( $message, $state, $cities );
				if ( ! $res['ok'] && in_array( $res['error'], array( 'rate_limited', 'auth' ), true ) ) {
					set_transient( 'spot_ai_skip_' . $provider->label(), 1, 5 * MINUTE_IN_SECONDS );
				}
				if ( $res['ok'] ) {
					$interpreter = $provider->label();
					list( $delta, $warnings ) = Spot_AI_Criteria::sanitize_delta( $res['data'], $cities );
					// Small models occasionally drop stated features; fill from the deterministic parser only when both lists came back empty.
					list( $det ) = Spot_AI_Criteria::sanitize_delta( Spot_AI_Fallback_Parser::parse( $message, $state, $cities ), $cities );
					if ( ! $delta['features_required'] && ! $delta['features_preferred'] ) {
						$delta['features_required']  = $det['features_required'];
						$delta['features_preferred'] = $det['features_preferred'];
					}
					// A price the visitor typed out ("under 1.5 million") must be used exactly; small models sometimes "adjust" it.
					foreach ( array( 'price_max', 'price_min' ) as $pf ) {
						if ( $det[ $pf ] !== null && $delta[ $pf ] !== $det[ $pf ] ) {
							$delta[ $pf ] = $det[ $pf ];
						}
					}
					// The keyword parser is deterministic about things we can never verify (schools, commute...): always tell the visitor.
					if ( ! $delta['unsupported'] && $det['unsupported'] ) {
						$delta['unsupported'] = $det['unsupported'];
					}
					break;
				}
				$errors[] = $provider->label() . ':' . $res['error'];
			}
			if ( $interpreter === 'none' ) {
				$ai_error    = $errors ? implode( ', ', $errors ) : 'not_configured';
				$interpreter = 'fallback';
				list( $delta, $warnings ) = Spot_AI_Criteria::sanitize_delta( Spot_AI_Fallback_Parser::parse( $message, $state, $cities ), $cities );
			} elseif ( $errors && current_user_can( 'manage_options' ) ) {
				// Visitors don't need to know which provider answered; site admins do.
				$warnings[] = 'The primary AI provider was unavailable (' . implode( ', ', $errors ) . '); the backup provider answered.';
			}
		} elseif ( isset( $body['patch'] ) && is_array( $body['patch'] ) ) {
			// Chip removal / "broaden" buttons: validated like any other delta, no model involved.
			$interpreter = 'direct';
			list( $delta, $warnings ) = Spot_AI_Criteria::sanitize_delta( $body['patch'] + array( 'intent' => 'search' ), $cities );
		}

		// This site's theme may not hold some fields (e.g. a directory has no bedrooms): drop them and say so.
		$cap_names = array( 'bedrooms' => 'bedrooms', 'bathrooms' => 'bathrooms', 'garages' => 'garage spaces', 'price' => 'price', 'status' => 'rent or sale', 'radius' => 'distance from a place', 'features' => 'that feature', 'types' => 'that type', 'location' => 'that place' );
		foreach ( Spot_AI_Criteria::STATE_FIELDS as $field ) {
			$cap = Spot_AI_Adapter::cap_for_field( $field );
			if ( $cap && ! Spot_AI_Adapter::supports( $cap ) && array_key_exists( $field, $delta ) && $delta[ $field ] !== null && $delta[ $field ] !== array() ) {
				$delta[ $field ]       = ( is_array( $delta[ $field ] ) ? array() : null );
				if ( $cap !== 'status' ) { // Rent/sale is a quiet internal field, not something the visitor asked about.
					$delta['unsupported'][] = $cap_names[ $cap ];
				}
			}
		}
		$delta['unsupported'] = array_values( array_unique( $delta['unsupported'] ) );
		if ( $delta['intent'] === 'sell' && ! Spot_AI_Adapter::get( 'seller', false ) ) {
			$delta['intent'] = 'other'; // Only real-estate sites have a seller route.
		}
		if ( $delta['intent'] === 'sell' ) { // A seller message is not a buyer search: leave the buyer criteria untouched.
			$delta = array_merge( Spot_AI_Criteria::empty_delta(), array( 'intent' => 'sell' ) );
		}
		$state = Spot_AI_Criteria::merge( $state, $delta );
		$notes = array();
		foreach ( $delta['unsupported'] as $u ) {
			$notes[] = 'No data for "' . $u . '", so it was ignored.';
		}
		if ( in_array( 'near_lake', array_merge( $state['features_required'], $state['features_preferred'] ), true ) ) {
			$notes[] = '"Near the lake" means within about ' . Spot_AI_Search::LAKE_RADIUS_MILES . ' miles (straight line).';
		}
		if ( $state['location'] && $cities && ! in_array( $state['location'], $cities, true ) ) {
			$notes[] = 'No ' . Spot_AI_Adapter::noun( true ) . ' in "' . $state['location'] . '". Try: ' . implode( ', ', array_slice( $cities, 0, 6 ) ) . '.';
		}

		$out = array(
			'interpreter'   => $interpreter,
			'ai_error'      => $ai_error,
			'intent'        => $delta['intent'],
			'criteria'      => $state,
			'chips'         => self::chips( $state ),
			'notes'         => $notes,
			'warnings'      => $warnings,
			'clarification' => $delta['needs_clarification'] ? $delta['clarification_question'] : null,
			'seller_cta'    => $delta['intent'] === 'sell' ? array( 'url' => defined( 'SPOT_AI_SELLER_URL' ) ? SPOT_AI_SELLER_URL : Spot_AI_Settings::get( 'seller_url', 'https://spot.realty/' ), 'label' => Spot_AI_Settings::get( 'seller_label', 'Seller savings calculator' ), 'text' => Spot_AI_Settings::get( 'seller_text', '' ) ) : null,
			'results'       => array(),
			'total'         => 0,
			'broaden'       => array(),
			'sample_data'   => Spot_AI_Search::demo_only(),
			'results_url'   => Spot_AI_Adapter::get( 'results' ) === 'houzez' ? Spot_AI_Houzez_Bridge::results_url( $state ) : '',
			'results_mode'  => Spot_AI_Adapter::get( 'results', 'inline' ),
			'noun'          => array( Spot_AI_Adapter::noun(), Spot_AI_Adapter::noun( true ) ),
			'adapter'       => Spot_AI_Adapter::get( 'id' ),
		);

		if ( $delta['intent'] === 'other' && $message !== '' ) {
			$ex = Spot_AI_Catalog::examples();
			$out['notes'][] = $ex ? 'Try: "' . $ex[0] . '".' : 'Try describing what you are looking for.';
			return rest_ensure_response( $out );
		}
		if ( $out['clarification'] ) {
			return rest_ensure_response( $out );
		}

		$found          = Spot_AI_Search::run( $state );
		$out['results'] = $found['results'];
		$out['total']   = $found['total'];
		if ( $found['total'] === 0 ) {
			$comp = Spot_AI_Search::compromise( $state );
			$out['compromise'] = $comp;
			$out['broaden']    = array_map( function ( $o ) {
				return array( 'label' => $o['label'] . ' (' . $o['count'] . ' ' . Spot_AI_Adapter::noun( $o['count'] !== 1 ) . ')', 'patch' => $o['patch'], 'count' => $o['count'] );
			}, $comp['options'] );
		}
		return rest_ensure_response( $out );
	}

	/** Removable criteria chips, each with the patch that removes it. */
	private static function chips( array $s ) {
		$labels = Spot_AI_Criteria::describe( $s );
		$patch  = array(
			'location'       => array( 'clear' => array( 'location', 'radius_miles' ) ),
			'property_types' => array( 'clear' => array( 'property_types' ) ),
			'bedrooms_min'   => array( 'clear' => array( 'bedrooms_min', 'bedrooms_max' ) ),
			'bedrooms_max'   => array( 'clear' => array( 'bedrooms_min', 'bedrooms_max' ) ),
			'bathrooms_min'  => array( 'clear' => array( 'bathrooms_min' ) ),
			'garages_min'    => array( 'clear' => array( 'garages_min' ) ),
			'price_max'      => array( 'clear' => array( 'price_min', 'price_max' ) ),
			'price_min'      => array( 'clear' => array( 'price_min', 'price_max' ) ),
		);
		$chips = array();
		foreach ( $labels as $key => $label ) {
			if ( strpos( $key, 'feature_req_' ) === 0 ) {
				$f     = substr( $key, 12 );
				$p     = array( 'clear' => array( 'features_required' ), 'features_required' => array_values( array_diff( $s['features_required'], array( $f ) ) ) );
			} elseif ( strpos( $key, 'feature_pref_' ) === 0 ) {
				$f     = substr( $key, 13 );
				$p     = array( 'clear' => array( 'features_preferred' ), 'features_preferred' => array_values( array_diff( $s['features_preferred'], array( $f ) ) ) );
			} else {
				$p = $patch[ $key ];
			}
			$chips[] = array( 'label' => $label, 'patch' => $p );
		}
		return $chips;
	}

	public static function inquiry( WP_REST_Request $req ) {
		$ok = self::check_nonce( $req );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$ok = self::throttle( 'inquiry', 5 );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$crit = Spot_AI_Criteria::sanitize_state( isset( $body['criteria'] ) ? $body['criteria'] : array(), Spot_AI_Search::known_cities() );
		$id   = Spot_AI_Inquiry::create( $body, $crit );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Thank you. Your request was recorded. (Demo: no email is sent to you.)' ) );
	}
}
