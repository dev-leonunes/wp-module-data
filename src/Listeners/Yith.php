<?php

namespace NewfoldLabs\WP\Module\Data\Listeners;

/**
 * Monitors Yith events
 */
class Yith extends Listener {

	/**
	 * Register the hooks for the listener
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Paypal Connection
		add_filter( 'pre_update_option_yith_ppwc_merchant_data_production', array( $this, 'paypal_connection' ), 10, 2 );
		add_filter( 'pre_update_option_nfd_ecommerce_captive_flow_razorpay', array( $this, 'razorpay_connection' ), 10, 2 );
		add_filter( 'pre_update_option_nfd_ecommerce_captive_flow_shippo', array( $this, 'shippo_connection' ), 10, 2 );
		add_filter( 'pre_update_option_nfd_ecommerce_captive_flow_stripe', array( $this, 'stripe_connection' ), 10, 2 );
		add_action('rest_after_insert_yith_campaign', array( $this, 'register_campaign' ), 10 );
		add_filter('toplevel_page_newfold-ecomdash', array($this, 'ecomdash_connected'));
	}

	/**
	 * PayPal connected
	 *
	 * @param string $new_option New value of the yith_ppwc_merchant_data_production option
	 * @param string $old_option Old value of the yith_ppwc_merchant_data_production option
	 *
	 * @return string The new option value
	 */
	public function paypal_connection( $new_option, $old_option ) {
		$url =  is_ssl() ? "https://" : "http://"; 
		$url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$data = array(
			"category" 	=> "commerce", 
			"data" 		=> array( 
				"label_key" => "provider",
				"provider" 	=> "yith_paypal",
				"page" 		=> $url
			), 
		);
		if ( $new_option !== $old_option && ! empty( $new_option ) ) {	
			$this->push(
				"payment_connected",
				$data
			);
		}

		return $new_option;
	}

	/**
	 * Razorpay connected
	 *
	 * @param string $new_option New value of the razorpay_data_production option
	 * @param string $old_option Old value of the razorpay_data_production option
	 *
	 * @return string The new option value
	 */
	public function razorpay_connection( $new_option, $old_option ) {
		$url =  is_ssl() ? "https://" : "http://"; 
		$url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$data = array(
			"category" 	=> "commerce", 
			"data" 		=> array( 
				"label_key" => "provider",
				"provider" 	=> "razorpay",
				"page" 		=> $url
			),
		);
		if ( $new_option !== $old_option && ! empty( $new_option ) ) {	
			$this->push(
				"payment_connected",
				$data
			);
		}

		return $new_option;
	}

	/**
	 * Shippo connected
	 *
	 * @param string $new_option New value of the shippo_data option
	 * @param string $old_option Old value of the shippo_data option
	 *
	 * @return string The new option value
	 */
	public function shippo_connection( $new_option, $old_option ) {
		$url =  is_ssl() ? "https://" : "http://"; 
		$url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$data = array(
			"category" 	=> "commerce", 
			"data" 		=> array( 
				"label_key" => "provider",
				"provider" 	=> "shippo",
				"page"		=> $url
			), 
		);
		if ( $new_option !== $old_option && ! empty( $new_option ) ) {	
			$this->push(
				"shpping_connected",
				$data
			);
		}

		return $new_option;
	}

	/**
	 * Stripe connected
	 *
	 * @param string $new_option New value of the stripe_data_production option
	 * @param string $old_option Old value of the stripe_data_production option
	 *
	 * @return string The new option value
	 */
	public function stripe_connection( $new_option, $old_option ) {
		$url =  is_ssl() ? "https://" : "http://"; 
		$url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$data = array(
			"category" 	=> "commerce", 
			"data" 		=> array( 
				"label_key" => "provider",
				"provider" 	=> "stripe",
				"page" 		=> $url
			),
		);
		if ( $new_option !== $old_option && ! empty( $new_option ) ) {	
			$this->push(
				"payment_connected",
				$data
			);
		}

		return $new_option;
	}

	/**
	 * Ecomdash connected
	 *
	 * @return void 
	 */
	public function ecomdash_connected() {
		$isecomdash_connected = \get_option( 'ewc4wp_sso_account_status', '' );
		if($isecomdash_connected === 'connected'){
			$url =  is_ssl() ? "https://" : "http://"; 
			$url .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$data = array(
				"category" 	=> "commerce", 
				"data" 		=> array( 
				"url"		=> $url
				), 
			);
		
			$this->push(
				"ecomdash_connected",
				$data
			);
		}
	}

	/**
	 * Campaign created
	 *
	 * @param string $post
	 *
	 * @return string The post value
	 */
	public function register_campaign( $post){
		$campaign   = yith_sales_get_campaign( $post->ID );
		if ($campaign){
			$type = $campaign->get_type();
			$data = array( 
					"category"=> "wonder_cart", 
					"data"=> array(
					  "label_key"=> "type",
					  "type"=> $type,
			),		
			);
			$this->push(
				"campaign_created",
				$data
			);
		}
		return $post;
	}
}
