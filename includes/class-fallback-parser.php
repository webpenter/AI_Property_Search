<?php
/**
 * Deterministic, offline interpreter used when Groq is unavailable or not
 * configured. Deliberately conservative: it only extracts what it is sure of.
 * Returns a raw delta in the same shape as the model, which is then validated
 * by Spot_AI_Criteria::sanitize_delta() like any other input.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'SPOT_AI_TESTING' ) ) {
	exit;
}

class Spot_AI_Fallback_Parser {

	private static $words = array(
		'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
		'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
	);

	public static function parse( $message, array $state, array $cities ) {
		$d   = Spot_AI_Criteria::empty_delta();
		$t   = ' ' . strtolower( preg_replace( '/\s+/', ' ', $message ) ) . ' ';
		$num = '(\d+|one|two|three|four|five|six|seven|eight|nine|ten)';

		// Intent.
		if ( preg_match( '/\b(sell(ing)? (my|our|the) (home|house|condo|place|property)|list my (home|house)|thinking of selling|want to sell|how much (is|can) my (home|house))/', $t ) ) {
			$d['intent'] = 'sell';
		} elseif ( ! preg_match( '/\b(home|homes|house|condo|townhouse|townhome|apartment|property|properties|place|places|bed|bedroom|bath|listing|listings|price|\$|k\b|under|below|lake|move|buy|rent|near|cheap|best|find|show|looking)/', $t ) ) {
			$d['intent'] = 'other';
		}
		if ( preg_match( '/\b(start over|new search|reset|clear (everything|all))\b/', $t ) ) {
			$d['reset'] = true;
		}
		$has_loc    = false;
		foreach ( $cities as $city ) {
			if ( strpos( $t, strtolower( $city ) ) !== false ) {
				$has_loc = true;
			}
		}
		$has_detail = (bool) preg_match( '/\b(condos?|houses?|townhou?s?e?s?|townhomes?|bed|beds|bedroom|bedrooms|under|below|\$)/', $t );
		$is_change  = (bool) preg_match( '/\b(make|also|actually|instead|too|but|only|just|change|add|remove|cheaper|more|less|and also)\b/', $t );
		if ( $has_loc && $has_detail && ! $is_change && ( $state['location'] || $state['property_types'] || $state['price_max'] ) ) {
			$d['reset'] = true; // A complete stand-alone search replaces the previous one instead of piling onto it.
		}

		// Location: longest matching known city name.
		$best = null;
		foreach ( $cities as $city ) {
			if ( strpos( $t, strtolower( $city ) ) !== false && ( $best === null || strlen( $city ) > strlen( $best ) ) ) {
				$best = $city;
			}
		}
		if ( $best === null && preg_match( '/\b(?:in|near|around|outside)\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)\b/', $message, $m ) && ! in_array( $m[1], array( 'The', 'My', 'Our' ), true ) ) {
			$best = $m[1]; // Unknown place: kept so the user is told we have no listings there.
		}
		$d['location'] = $best;

		// Property types: whatever types this site really has.
		$types = array();
		foreach ( Spot_AI_Criteria::types() as $slug ) {
			if ( preg_match( self::type_regex( $slug ), $t ) ) {
				$types[] = $slug;
			}
		}
		if ( $types ) {
			$additive = preg_match( '/\b(too|also|as well|fine|okay|ok|include|including|or)\b/', $t ) && ! preg_match( '/\b(instead|rather|only|just)\b/', $t );
			if ( $additive && $state['property_types'] ) {
				$types = array_values( array_unique( array_merge( $state['property_types'], $types ) ) );
			}
			$d['property_types'] = $types;
		}

		// Bedrooms.
		if ( preg_match( '/\b' . $num . '(?:\s*(?:or|to|-)\s*' . $num . ')\s*(?:-|\s)?(?:bed|br\b|bedroom)/', $t, $m ) ) {
			$a = self::n( $m[1] );
			$b = self::n( $m[2] );
			$d['bedrooms_min'] = min( $a, $b );
			$d['bedrooms_max'] = max( $a, $b );
		} elseif ( preg_match( '/\b' . $num . '(?:\s*\+)?\s*(?:-|\s)?(?:bed|br\b|bedroom)/', $t, $m ) ) {
			$d['bedrooms_min'] = self::n( $m[1] );
			$d['bedrooms_max'] = null;
			$d['clear'][]      = 'bedrooms_max';
		} elseif ( preg_match( '/\bmake (?:that|it) ' . $num . ' (?:bed|bedroom)/', $t, $m ) ) {
			$d['bedrooms_min'] = self::n( $m[1] );
			$d['clear'][]      = 'bedrooms_max';
		}
		if ( preg_match( '/\b' . $num . '(?:\s*\+)?\s*(?:-|\s)?(?:bath|bathroom)/', $t, $m ) ) {
			$d['bathrooms_min'] = self::n( $m[1] );
		}

		if ( preg_match( '/\b' . $num . '[- ](?:car )?garages?\b/', $t, $m ) && self::n( $m[1] ) > 0 ) {
			$d['garages_min'] = self::n( $m[1] );
		}

		if ( preg_match( '/\bwithin\s+(\d{1,2})\s*(?:miles?|mi)\b/', $t, $m ) ) {
			$d['radius_miles'] = (int) $m[1];
		}

		// Prices.
		// "between 1 million and 6 million", "between 500 and 900k" (the unit may be given only once).
		if ( preg_match( '/\bbetween\s+\$?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|m|million)?\s*(?:and|to|-)\s*\$?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|m|million)?/', $t, $rm ) ) {
			$u1 = ( isset( $rm[2] ) && $rm[2] !== '' ) ? $rm[2] : ( isset( $rm[4] ) ? $rm[4] : '' );
			$u2 = ( isset( $rm[4] ) && $rm[4] !== '' ) ? $rm[4] : $u1;
			$lo = self::money( $rm[1], $u1, $t );
			$hi = self::money( $rm[3], $u2, $t );
			if ( $lo && $hi && $lo <= $hi ) {
				$d['price_min'] = $lo;
				$d['price_max'] = $hi;
			}
		}
		$money = '\$?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|m|million)?';
		if ( preg_match( '/\b(?:under|below|less than|up to|max(?:imum)?(?: of)?|no more than|cheaper than|budget(?: of)?(?: around)?|around|about)\s*' . $money . '/', $t, $m ) ) {
			$p = self::money( $m[1], isset( $m[2] ) ? $m[2] : '', $t );
			if ( $p ) {
				$d['price_max'] = $p;
			}
		}
		if ( preg_match( '/\b(?:over|above|more than|at least|min(?:imum)?(?: of)?|starting at|from)\s*' . $money . '/', $t, $m ) ) {
			$p = self::money( $m[1], isset( $m[2] ) ? $m[2] : '', $t );
			if ( $p && $p >= 10000 ) {
				$d['price_min'] = $p;
			}
		}
		if ( $d['price_max'] === null && preg_match( '/\b(cheaper|less expensive|lower)\b/', $t ) && ! preg_match( '/\bunder|below/', $t ) ) {
			if ( $state['price_max'] ) {
				$d['price_max'] = (int) ( round( $state['price_max'] * 0.85 / 5000 ) * 5000 );
			} else {
				$d['needs_clarification']    = true;
				$d['clarification_question'] = 'What is the most you would like to spend?';
			}
		}
		if ( preg_match( '/\b(any price|no (price|budget) (limit|max)|remove (the )?(price|budget))/', $t ) ) {
			$d['clear'][] = 'price_max';
			$d['clear'][] = 'price_min';
		}

		// Features: wishes vs. firm requirements. Only features this site really has; mentions of common
		// amenities it does not track are reported as unsupported instead of being silently dropped.
		$map = array();
		foreach ( Spot_AI_Criteria::features() as $slug ) {
			$map[ $slug ] = self::feature_regex( $slug );
		}
		foreach ( array( 'backyard' => '/\b(back ?yard|yard|garden)\b/', 'garage' => '/\bgarage\b/', 'fireplace' => '/\bfireplace\b/', 'pool' => '/\b(pool|swimming)\b/', 'basement' => '/\bbasement\b/' ) as $word => $re ) {
			if ( preg_match( $re, $t ) && ! array_filter( $map, function ( $mre ) use ( $t ) { return preg_match( $mre, $t ); } ) && $word !== 'garage' ) {
				$d['unsupported'][] = $word;
			} elseif ( $word === 'garage' && preg_match( $re, $t ) && ! preg_match( '/\d[- ]?(car )?garage|(one|two|three|four)[- ]?(car )?garage/', $t ) && ! isset( $map['garage'] ) ) {
				$d['unsupported'][] = 'garage';
			}
		}
		$req = array();
		$pref = array();
		foreach ( $map as $f => $re ) {
			if ( $f === 'near_lake' ) {
				$probe = str_replace( 'lake zurich', '', $t );
				$hit   = (bool) preg_match( '/\b(close to|near|by|walk(?:ing)? to|on|access to) the lake\b|\blake ?(front|view)\b/', $probe );
			} else {
				$hit = (bool) preg_match( $re, $t );
			}
			if ( ! $hit ) {
				continue;
			}
			$word = $f === 'near_lake' ? 'lake' : ( $f === 'backyard' ? '(?:back ?)?yard' : preg_quote( strtolower( Spot_AI_Criteria::feature_label( $f ) ), '/' ) );
			$firm = (bool) preg_match( '/\b(must|need|needs|has to|have to|required)\b[^.]{0,30}' . $word . '/', $t )
				&& ! preg_match( '/\b(ideally|if possible|preferably|nice to have|would like|maybe)\b/', $t );
			if ( $firm ) {
				$req[] = $f;
			} else {
				$pref[] = $f;
			}
		}
		if ( $req ) {
			$d['features_required'] = array_values( array_unique( array_merge( $state['features_required'], $req ) ) );
		}
		if ( $pref ) {
			$d['features_preferred'] = array_values( array_unique( array_merge( $state['features_preferred'], $pref ) ) );
		}

		// Rent or buy.
		if ( preg_match( '/\b(for rent|to rent|rent|renting|rental|rentals|lease|leasing)\b/', $t ) ) {
			$d['listing_status'] = 'rent';
		} elseif ( preg_match( '/\b(for sale|to buy|buy|buying|purchase)\b/', $t ) ) {
			$d['listing_status'] = 'sale';
		}

		// A message that names a place, a home type or rent/sale on this site is a property search, whatever other words it uses.
		if ( $d['intent'] === 'other' && ( $best !== null || $types || ! empty( $d['listing_status'] ) ) ) {
			$d['intent'] = 'search';
		}

		// Sorting.
		if ( preg_match( '/\b(cheapest first|lowest price|price low)/', $t ) ) {
			$d['sort'] = 'price_asc';
		} elseif ( preg_match( '/\b(most expensive|highest price|price high)/', $t ) ) {
			$d['sort'] = 'price_desc';
		} elseif ( preg_match( '/\b(newest|latest)\b/', $t ) ) {
			$d['sort'] = 'newest';
		}

		// Things we cannot verify.
		$unsupported = array(
			'school quality'  => '/\b(school|schools|school district|rated)\b/',
			'commute / walking distance' => '/\b(commute|walk(?:ing)? (distance|score)|\d+[- ]minute)/',
			'neighborhood safety'        => '/\b(safe|safety|crime)\b/',
			'views'                      => '/\b(view|views)\b/',
		);
		foreach ( $unsupported as $label => $re ) {
			if ( preg_match( $re, $t ) ) {
				$d['unsupported'][] = $label;
			}
		}

		$d['clear'] = array_values( array_unique( $d['clear'] ) );
		return $d;
	}

	/** Words that mean this home type (its own name plus everyday synonyms). */
	private static function type_regex( $slug ) {
		$label = strtolower( Spot_AI_Criteria::type_label( $slug ) );
		$alts  = array( preg_quote( $label, '/' ), preg_quote( $label, '/' ) . 's', preg_quote( str_replace( ' ', '-', $label ), '/' ) );
		$k     = strtolower( $slug . ' ' . $label );
		if ( preg_match( '/house|single|detached/', $k ) && ! preg_match( '/town/', $k ) ) {
			$alts[] = 'houses?|single[- ]family|detached';
		}
		if ( preg_match( '/condo|apartment|flat/', $k ) ) {
			$alts[] = 'condos?|condominiums?|apartments?|flats?';
		}
		if ( preg_match( '/town/', $k ) ) {
			$alts[] = 'townhou?se?s?|townhomes?';
		}
		if ( preg_match( '/shop|store|retail/', $k ) ) {
			$alts[] = 'shops?|stores?|retail';
		}
		return '/\b(' . implode( '|', array_unique( $alts ) ) . ')\b/';
	}

	/** Words that mean this feature (its label, its last word, and everyday synonyms). */
	private static function feature_regex( $slug ) {
		if ( $slug === 'near_lake' ) {
			return '/\b(close to|near|by|around|walk(?:ing)? to|on|access to) the lake\b|\blake ?(front|view|access)\b/';
		}
		$label = strtolower( Spot_AI_Criteria::feature_label( $slug ) );
		$alts  = array( preg_quote( $label, '/' ) );
		$words = preg_split( '/[\s_-]+/', $label );
		$last  = end( $words );
		if ( strlen( $last ) >= 4 ) {
			$alts[] = preg_quote( $last, '/' );
		}
		$k = strtolower( $slug . ' ' . $label );
		$syn = array(
			'/yard|lawn|garden/'     => '(?:back ?)?yard|garden|lawn',
			'/pool/'                 => 'pool|swimming',
			'/kitchen/'              => '(?:updated|renovated|remodeled|modern) kitchen',
			'/barbe|bbq|grill/'      => 'barbe(?:cue|que)|bbq|grill',
			'/air|a\/c|conditioning/' => 'air[- ]conditioning|a\/c|\bac\b',
			'/wifi|wi-fi|internet/'  => 'wi-?fi|internet',
			'/gym|fitness/'          => 'gym|fitness',
			'/laundry|washer|dryer/' => 'laundry|washer|dryer',
		);
		foreach ( $syn as $re => $alt ) {
			if ( preg_match( $re, $k ) ) {
				$alts[] = $alt;
			}
		}
		return '/\b(' . implode( '|', array_unique( $alts ) ) . ')\b/';
	}

	private static function n( $s ) {
		return is_numeric( $s ) ? (int) $s : ( isset( self::$words[ $s ] ) ? self::$words[ $s ] : 0 );
	}

	private static function money( $num, $unit, $text = '' ) {
		$v = (float) str_replace( ',', '', $num );
		if ( $unit === 'k' || $unit === 'thousand' ) {
			$v *= 1000;
		} elseif ( $unit === 'm' || $unit === 'million' ) {
			$v *= 1000000;
		}
		// Monthly rents are small numbers; purchase prices are not.
		$floor = preg_match( '/\b(rent|rental|rentals|lease|per month|monthly)\b|\/mo\b/', $text ) ? 300 : 10000;
		return $v >= $floor ? (int) round( $v ) : null; // Reject bare small numbers like "under 3".
	}
}
