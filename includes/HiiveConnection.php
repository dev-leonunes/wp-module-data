<?php

namespace NewfoldLabs\WP\Module\Data;

use NewfoldLabs\WP\Module\Data\Helpers\Plugin as PluginHelper;
use NewfoldLabs\WP\Module\Data\Helpers\Transient;
use WP_Error;
use function NewfoldLabs\WP\ModuleLoader\container;

/**
 * Manages a Hiive connection instance and interactions with it
 */
class HiiveConnection implements SubscriberInterface {

	/**
	 * Hiive API url
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

		if ( ! defined( 'NFD_HIIVE_URL' ) ) {
			define( 'NFD_HIIVE_URL', 'https://hiive.cloud/api' );
		}

		$this->api = constant( 'NFD_HIIVE_URL' );
	}

	/**
	 * Register the hooks required for site verification
	 *
	 * @return void
	 */
	public function register_verification_hooks() {
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		add_action( 'wp_ajax_nopriv_nfd-hiive-verify', array( $this, 'ajax_verify' ) );
	}

	/**
	 * Set up REST API routes
	 *
	 * @hooked rest_api_init
	 */
	public function rest_api_init(): void {
		$controller = new API\Verify( $this );
		$controller->register_routes();
	}

	/**
	 * Process the admin-ajax request
	 *
	 * Hiive will first attempt to verify using the REST API, and fallback to this AJAX endpoint on error.
	 *
	 * @hooked wp_ajax_nopriv_nfd-hiive-verify
	 *
	 * @return never
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
	 */
	public function verify_token( string $token ): bool {
		$saved_token = Transient::get( 'nfd_data_verify_token' );

		if ( $saved_token && $saved_token === $token ) {
			Transient::delete( 'nfd_data_verify_token' );

			return true;
		}

		return false;
	}

	/**
	 * Check whether site has established connection to hiive
	 *
	 * This is cleared whenever Hiive returns 401 unauthenticated {@see Data::delete_token_on_401_response()}.
	 *
	 * @used-by Data::init()
	 */
	public static function is_connected(): bool {
		return (bool) ( self::get_auth_token() );
	}

	/**
	 * Attempt to connect to Hiive
	 *
	 * @used-by Data::init()
	 * @used-by HiiveConnection::reconnect()
	 */
	public function connect( string $path = '/sites/v2/connect', ?string $authorization = null ): bool {

		if ( $this->is_throttled() ) {
			return false;
		}

		$this->throttle();

		$token = md5( wp_generate_password() );
		Transient::set( 'nfd_data_verify_token', $token, 5 * constant( 'MINUTE_IN_SECONDS' ) );

		$data                 = $this->get_core_data();
		$data['verify_token'] = $token;
		$data['plugins']      = PluginHelper::collect_installed();

		$args = array(
			'body'     => wp_json_encode( $data ),
			'headers'  => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'blocking' => true,
			'timeout'  => 30,
		);

		if ( $authorization ) {
			$args['headers']['Authorization'] = $authorization;
		}

		$attempts = intval( get_option( 'nfd_data_connection_attempts', 0 ) );
		update_option( 'nfd_data_connection_attempts', $attempts + 1 );

		$response = wp_remote_post( $this->api . $path, $args );
		$status   = wp_remote_retrieve_response_code( $response );

		// Created = 201; Updated = 200
		if ( 201 === $status || 200 === $status ) {
			$body = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! empty( $body->token ) ) {

				// Token is auto-encrypted using the `pre_update_option_nfd_data_token` hook.
				update_option( 'nfd_data_token', $body->token );
				return true;
			}
		}
		return false;
	}

	/**
	 * Rename the site URL in Hiive.
	 *
	 * This performs almost the same request as {@see self::connect} but includes the Site authorization token,
	 * to verify this site is the owner of the existing site in Hiive, and Hiive pings back the new URL to verify
	 * the DNS points to this site.
	 */
	public function reconnect(): bool {
		return $this->connect( '/sites/v2/reconnect', 'Bearer ' . self::get_auth_token() );
	}

	/**
	 * Set the connection throttle
	 *
	 * @return void
	 */
	public function throttle() {
		$interval = $this->get_throttle_interval();

		$this->throttle = Transient::set( 'nfd_data_connection_throttle', true, $interval );
	}

	/**
	 * Determine the throttle interval based off number of connection attempts
	 *
	 * @return integer Time to wait until next connection attempt
	 */
	public function get_throttle_interval() {

		$attempts = intval( get_option( 'nfd_data_connection_attempts', 0 ) );

		// Throttle intervals step-up:
		// Hourly for 4 hours
		// Twice a day for 3 days
		// Once a day for 3 days
		// Every 3 days for 3 times
		// Once a week
		if ( $attempts <= 4 ) {
			return HOUR_IN_SECONDS;
		} elseif ( $attempts <= 10 ) {
			return 12 * HOUR_IN_SECONDS;
		} elseif ( $attempts <= 13 ) {
			return DAY_IN_SECONDS;
		} elseif ( $attempts <= 16 ) {
			return 3 * DAY_IN_SECONDS;
		} else {
			return WEEK_IN_SECONDS;
		}
	}

	/**
	 * Check whether connection is throttled
	 *
	 * @return boolean
	 */
	public function is_throttled() {
		$this->throttled = Transient::get( 'nfd_data_connection_throttle' );

		return $this->throttled;
	}

	/**
	 * Synchronously send a single event and return the notifications.
	 *
	 * @used-by Events::create_item()
	 *
	 * @param Event $event
	 *
	 * @return array{data:array{id:string,locations:array,query:string|null,expiration:int,content:string}}|WP_Error
	 */
	public function send_event( Event $event ) {

		$payload = array(
			'environment' => $this->get_core_data(),
			'events'      => array( $event ),
		);

		$hiive_response = $this->hiive_request( 'sites/v1/events', $payload );

		if ( is_wp_error( $hiive_response ) ) {
			// TODO: enqueue failed event for later. Should this function call go via EventManager?
			return $hiive_response;
		}

		$status_code = wp_remote_retrieve_response_code( $hiive_response );

		if ( ! in_array( $status_code, array( 200, 201 ), true ) ) {
			return new \WP_Error( $status_code, wp_remote_retrieve_response_message( $hiive_response ) );
		}

		/** @var array{data:array{id:string,locations:array,query:string|null,expiration:int,content:string}} $response_payload */
		$response_payload = json_decode( wp_remote_retrieve_body( $hiive_response ), true );

		return $response_payload['data'] ?? array();
	}

	/**
	 * Send events to the v2 events endpoint and return the list of successes and list of failures.
	 *
	 * @see SubscriberInterface::notify()
	 * @used-by EventManager::send()
	 *
	 * @param Event[] $events Array of Event objects representing the actions that occurred.
	 *
	 * @return array{succeededEvents:array,failedEvents:array}|WP_Error
	 */
	public function notify( $events ) {

		$payload = array(
			'environment' => $this->get_core_data(),
			'events'      => $events,
		);

		$hiive_response = $this->hiive_request( 'sites/v2/events', $payload );

		if ( is_wp_error( ( $hiive_response ) ) ) {
			return $hiive_response;
		}

		if ( ! in_array( wp_remote_retrieve_response_code( $hiive_response ), array( 200, 201, 500 ) ) ) {
			return new WP_Error( wp_remote_retrieve_response_code( $hiive_response ), wp_remote_retrieve_response_message( $hiive_response ) );
		}

		return json_decode( wp_remote_retrieve_body( $hiive_response ), true );
	}

	/**
	 * Send a HTTP request to Hiive and return the body of the request.
	 *
	 * Handles throttling and reconnection, clients should handle queueing if necessary.
	 *
	 * Defaults to POST. Override with `$args = array('method' => 'GET')`.
	 *
	 * @param string     $path The Hiive api path (after /api/).
	 * @param array|null $payload
	 * @param array|null $args
	 *
	 * @return array|WP_Error
	 */
	public function hiive_request( string $path, ?array $payload = array(), ?array $args = array() ) {

		// If for some reason we are not connected, bail out now.
		// If we are not connected, the throttling logic should eventually reconnect.
		if ( ! self::is_connected() ) {
			return new WP_Error( 'hiive_connection', __( 'This site is not connected to the hiive.' ) );
		}

		$defaults = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'applicaton/json',
				'Accept'        => 'applicaton/json',
				'Authorization' => 'Bearer ' . self::get_auth_token(),
			),
			'timeout' => wp_is_serving_rest_request() ? 15 : 60, // If we're responding to the frontend, we need to be quick.
		);

		$parsed_args = wp_parse_args( $args ?? array(), $defaults );

		if ( ! empty( $payload ) ) {
			$parsed_args['body'] = wp_json_encode( $payload );
		}

		$request_response = wp_remote_request( "{$this->api}/{$path}", $parsed_args );

		// E.g. Hiive is down, or the site has disabled HTTP requests.
		if ( is_wp_error( $request_response ) ) {
			return $request_response;
		}

		// Authentication token is valid for Hiive but not for the resource or Site.
		if ( 403 === $request_response['response']['code'] ) {
			$body = json_decode( $request_response['body'], true );
			if ( 'Invalid token for url' === $body['message'] ) {
				if ( $this->reconnect() ) {
					$this->hiive_request( $path, $args );
				} else {
					return new WP_Error( 'hiive_connection', __( 'This site is not connected to the hiive.' ) );
				}
			}
		}

		return $request_response;
	}

	/**
	 * Try to return the auth token
	 *
	 * This is cleared whenever Hiive returns 401 unauthenticated {@see Data::delete_token_on_401_response()}.
	 *
	 * @return string|false The decrypted token if it's set
	 */
	public static function get_auth_token() {
		return get_option( 'nfd_data_token' );
	}

	/**
	 * Get core site data for initial connection
	 *
	 * @return array
	 */
	public function get_core_data() {
		global $wpdb, $wp_version;
		$container = container();

		$data = array(
			'brand'       => sanitize_title( $container->plugin()->brand ),
			'cache_level' => intval( get_option( 'newfold_cache_level', 2 ) ),
			'cloudflare'  => get_option( 'newfold_cloudflare_enabled', false ),
			'data'        => defined( 'NFD_DATA_MODULE_VERSION' ) ? constant( 'NFD_DATA_MODULE_VERSION' ) : '0.0',
			'email'       => get_option( 'admin_email' ),
			'hostname'    => gethostname(),
			'mysql'       => $wpdb->db_version(),
			'origin'      => $container->plugin()->get( 'id', 'error' ),
			'php'         => phpversion(),
			'plugin'      => $container->plugin()->get( 'version', '0' ),
			'url'         => get_site_url(),
			'username'    => get_current_user(),
			'wp'          => $wp_version,
			'server_path' => defined( 'ABSPATH' ) ? constant( 'ABSPATH' ) : '',
		);

		return apply_filters( 'newfold_wp_data_module_core_data_filter', $data );
	}
}
