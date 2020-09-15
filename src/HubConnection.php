<?php

namespace Endurance\WP\Module\Data;

/**
 * Manages a Hub connection instance and interactions with it
 */
class HubConnection implements SubscriberInterface {

	/**
	 * Hub API url
	 *
	 * @var string
	 */
	private $api;

	/**
	 * Authentication token for data api
	 *
	 * @var string
	 */
	private $token;


	/**
	 * Whether connection attempts are currently throttled
	 *
	 * @var boolean
	 */
	private $throttled;

	/**
	 * Construct
	 */
	public function __construct() {

		if ( ! defined( 'BH_HUB_URL' ) ) {
			define( 'BH_HUB_URL', 'https://hiive.cloud/api' );
		}

		$this->api = BH_HUB_URL;

	}

	/**
	 * Register the hooks required for site verification
	 *
	 * @return void
	 */
	public function register_verification_hooks() {
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		add_action( 'wp_ajax_nopriv_bh-hub-verify', array( $this, 'ajax_verify' ) );

	}

	/**
	 * Set up REST API routes
	 *
	 * @return void
	 */
	public function rest_api_init() {
		$controller = new API\Verify( $this );
		$controller->register_routes();
	}

	/**
	 * Process the admin-ajax request
	 *
	 * @return void
	 */
	public function ajax_verify() {
		$valid  = $this->verify_token( $_REQUEST['token'] );
		$status = ( $valid ) ? 200 : 400;

		$data = array(
			'token' => $_REQUEST['token'],
			'valid' => $valid,
		);
		wp_send_json( $data, $status );
	}

	/**
	 * Confirm whether verification token is valid
	 *
	 * @param string $token Token to verify
	 * @return boolean
	 */
	public function verify_token( $token ) {
		$saved_token = $this->get_transient( 'bh_data_verify_token' );

		if ( $saved_token && $saved_token === $token ) {
			$this->delete_transient( 'bh_data_verify_token' );
			return true;
		}

		return false;
	}

	/**
	 * Check whether site has established connection to hub
	 *
	 * @return boolean
	 */
	public function is_connected() {
		return (bool) ( $this->get_auth_token() );
	}

	/**
	 * Attempt to connect to hub
	 *
	 * @return void
	 */
	public function connect() {

		if ( $this->is_throttled() ) {
			return;
		}

		$this->throttle();

		$token = md5( wp_generate_password() );
		$this->set_transient( 'bh_data_verify_token', $token, 5 * MINUTE_IN_SECONDS );

		$data                 = $this->get_core_data();
		$data['verify_token'] = $token;

		$args = array(
			'body'     => wp_json_encode( $data ),
			'headers'  => array(
				'Content-Type' => 'applicaton/json',
				'Accept'       => 'applicaton/json',
			),
			'blocking' => true,
			'timeout'  => 30,
		);

		$response = wp_remote_post( $this->api . '/connect', $args );
		$status   = wp_remote_retrieve_response_code( $response );

		// Created = 201; Updated = 200
		if ( 201 === $status || 200 === $status ) {
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! empty( $body->token ) ) {
				$encryption      = new Encryption();
				$encrypted_token = $encryption->encrypt( $body->token );
				update_option( 'bh_data_token', $encrypted_token );
			}
		}

	}

	/**
	 * Whether to use transients to store temporary data
	 *
	 * If the site has an object-cache.php drop-in, then we can't reliably
	 * use the transients API. We'll try to fall back to the options API.
	 *
	 * @return boolean
	 */
	public function should_use_transients() {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		return ! array_key_exists( 'object-cache.php', get_dropins() );
	}

	/**
	 * Set the connection throttle
	 *
	 * @return void
	 */
	public function throttle() {
		$this->throttle = $this->set_transient( 'bh_data_connection_throttle', true, 60 * MINUTE_IN_SECONDS );
	}

	/**
	 * Check whether connection is throttled
	 *
	 * @return boolean
	 */
	public function is_throttled() {
		$this->throttled = $this->get_transient( 'bh_data_connection_throttle' );
		return $this->throttled;
	}

	/**
	 * Custom override for get_transient() with Options API fallback
	 *
	 * @param string $key The key of the transient to retrieve
	 * @return mixed The value of the transient
	 */
	private function get_transient( $key ) {
		if ( $this->should_use_transients() ) {
			return get_transient( $key );
		}

		$data = get_option( $key );
		if ( ! empty( $data ) ) {
			if ( isset( $data['expires'] ) && $data['expires'] > time() ) {
				return $data['value'];
			}
		}

		return false;
	}

	/**
	 * Custom override for set_transient() with Options API fallback
	 *
	 * @param string  $key     Key to use for storing the transient
	 * @param mixed   $value   Value to be saved
	 * @param integer $expires Optional expiration time
	 * @return boolean Whether the value was saved
	 */
	private function set_transient( $key, $value, $expires = 60 * MINUTE_IN_SECONDS ) {
		if ( $this->should_use_transients() ) {
			return set_transient( $key, $value, $expires );
		}

		$data = array(
			'value'   => $value,
			'expires' => $expires,
		);
		return update_option( $key, $data, false );
	}

	/**
	 * Custom override for delete_transient() with Optiosn API fallback
	 *
	 * @param string $key The key of the transient/option to delete
	 * @return boolean Whether the value was deleted
	 */
	private function delete_transient( $key ) {
		if ( $this->should_use_transients() ) {
			return delete_transient( $key );
		}

		return delete_option( $key );
	}

	/**
	 * Post event data payload to the hub
	 *
	 * @param Event $event Event object representing the action that occurred
	 *
	 * @return void
	 */
	public function notify( Event $event ) {

		// If for some reason we are not connected, bail out now.
		if ( ! $this->is_connected() ) {
			return;
		}

		$args = array(
			'body'     => wp_json_encode( $event ),
			'headers'  => array(
				'Content-Type'  => 'applicaton/json',
				'Accept'        => 'applicaton/json',
				'Authorization' => 'Bearer ' . $this->get_auth_token(),
			),
			'blocking' => false,
			'timeout'  => .5,
		);

		wp_remote_post( $this->api . '/event', $args );
	}

	/**
	 * Try to return the auth token
	 *
	 * @return string|null The decrypted token if it's set
	 */
	public function get_auth_token() {
		if ( empty( $this->token ) ) {
			$encrypted_token = get_option( 'bh_data_token' );
			if ( false !== $encrypted_token ) {
				$encryption  = new Encryption();
				$this->token = $encryption->decrypt( $encrypted_token );
			}
		}

		return $this->token;
	}

	/**
	 * Get core site data for initial connection
	 *
	 * @return array
	 */
	public function get_core_data() {
		global $wpdb, $wp_version;

		return array(
			'url'         => get_site_url(),
			'php'         => phpversion(),
			'mysql'       => $wpdb->db_version(),
			'wp'          => $wp_version,
			'plugin'      => BLUEHOST_PLUGIN_VERSION,
			'hostname'    => gethostname(),
			'cache_level' => intval( get_option( 'endurance_cache_level', 2 ) ),
		);
	}
}
