<?php
/**
 * Consent-based inquiry storage. Records locally in a custom table.
 * Nothing is emailed to the visitor. An optional notification goes only to
 * SPOT_AI_INQUIRY_EMAIL if (and only if) that constant is defined.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Spot_AI_Inquiry {

	const ACTIONS = array( 'showing', 'info', 'callback' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'spot_ai_inquiries';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			action varchar(20) NOT NULL,
			property_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			criteria longtext NULL,
			consent tinyint(1) NOT NULL DEFAULT 0,
			source_url varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id)
		) $charset;" );
	}

	/** @return int|WP_Error inserted id */
	public static function create( array $in, array $criteria ) {
		global $wpdb;

		if ( ! empty( $in['website'] ) ) { // Honeypot.
			return new WP_Error( 'spam', 'Rejected.', array( 'status' => 400 ) );
		}
		$action = isset( $in['action'] ) ? $in['action'] : '';
		$name   = isset( $in['name'] ) ? trim( sanitize_text_field( $in['name'] ) ) : '';
		$email  = isset( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
		if ( ! in_array( $action, self::ACTIONS, true ) || $name === '' || ! is_email( $email ) ) {
			return new WP_Error( 'invalid', 'Please provide your name, a valid email and a request type.', array( 'status' => 400 ) );
		}
		if ( empty( $in['consent'] ) || $in['consent'] !== true ) {
			return new WP_Error( 'consent', 'Please confirm you agree to be contacted about this request.', array( 'status' => 400 ) );
		}
		$property_id = isset( $in['property_id'] ) ? absint( $in['property_id'] ) : 0;
		if ( $property_id && get_post_type( $property_id ) !== Spot_AI_Adapter::get( 'post_type', 'property' ) ) {
			$property_id = 0;
		}

		$ok = $wpdb->insert( self::table(), array(
			'created_at'  => current_time( 'mysql' ),
			'action'      => $action,
			'property_id' => $property_id,
			'name'        => mb_substr( $name, 0, 120 ),
			'email'       => $email,
			'phone'       => isset( $in['phone'] ) ? mb_substr( preg_replace( '/[^0-9+\-\(\)\s\.]/', '', (string) $in['phone'] ), 0, 40 ) : '',
			'message'     => isset( $in['message'] ) ? mb_substr( sanitize_textarea_field( $in['message'] ), 0, 1000 ) : '',
			'criteria'    => wp_json_encode( $criteria ),
			'consent'     => 1,
			'source_url'  => isset( $in['source_url'] ) ? esc_url_raw( mb_substr( $in['source_url'], 0, 255 ) ) : '',
		) );
		if ( ! $ok ) {
			return new WP_Error( 'db', 'Could not save your request.', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		$notify = defined( 'SPOT_AI_INQUIRY_EMAIL' ) ? SPOT_AI_INQUIRY_EMAIL : Spot_AI_Settings::get( 'notify_email', '' );
		if ( $notify && is_email( $notify ) ) {
			wp_mail( $notify, '[SPOT AI demo] New ' . $action . ' request #' . $id,
				"Name: $name\nEmail: $email\nProperty ID: $property_id\nCriteria: " . wp_json_encode( $criteria ) );
		}
		return $id;
	}

	public static function admin_page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['spot_ai_seed'] ) && check_admin_referer( 'spot_ai_tools' ) ) {
			$r = Spot_AI_Sample_Data::seed();
			echo '<div class="notice notice-success"><p>' . ( is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : esc_html( "Created $r sample listings." ) ) . '</p></div>';
		}
		if ( isset( $_POST['spot_ai_unseed'] ) && check_admin_referer( 'spot_ai_tools' ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( 'Deleted ' . Spot_AI_Sample_Data::remove() . ' sample listings.' ) . '</p></div>';
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 100' );
		echo '<div class="wrap"><h1>SPOT AI Search</h1>';
		$groq_ok = ( Spot_AI_Groq::from_environment() )->is_configured();
		$gem_ok  = ( Spot_AI_Groq::gemini_from_environment() )->is_configured();
		echo '<p>AI: Groq ' . ( $groq_ok ? '<strong>key saved</strong>' : '<strong>no key</strong>' ) . ' · Gemini backup ' . ( $gem_ok ? '<strong>key saved</strong>' : '<strong>no key</strong>' )
			. ( $groq_ok || $gem_ok ? '' : ' (the basic keyword parser is used until a key is added)' )
			. ' · <a href="' . esc_url( admin_url( 'admin.php?page=spot-ai-settings' ) ) . '">Settings</a></p>';
		echo '<p>Searching: <strong>' . ( Spot_AI_Search::demo_only() ? 'labelled sample listings only' : 'all published listings' ) . '</strong>.</p>';
		// Sample-data tools are shown only on a dev site (constant) or while sample listings exist, so nobody adds fictional homes to a real site by accident.
		if ( defined( 'SPOT_AI_DEV' ) || Spot_AI_Search::has_sample_data() ) {
			echo '<form method="post">';
			wp_nonce_field( 'spot_ai_tools' );
			echo '<button class="button button-primary" name="spot_ai_seed" value="1">Load sample listings</button> ';
			echo '<button class="button" name="spot_ai_unseed" value="1" onclick="return confirm(\'Delete all sample listings?\')">Remove sample listings</button></form>';
		}
		echo '<h2>Inquiries (demo)</h2><table class="widefat striped"><thead><tr><th>#</th><th>When</th><th>Action</th><th>Property</th><th>Name</th><th>Email</th><th>Criteria</th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				$r->id, esc_html( $r->created_at ), esc_html( $r->action ), $r->property_id ? esc_html( get_the_title( $r->property_id ) ) : '-',
				esc_html( $r->name ), esc_html( $r->email ), esc_html( $r->criteria ) );
		}
		echo '</tbody></table></div>';
	}
}
