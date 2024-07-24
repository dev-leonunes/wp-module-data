<?php

namespace NewfoldLabs\WP\Module\Data;

use Mockery;
use function NewfoldLabs\WP\ModuleLoader\container;

/**
 * @coversDefaultClass \NewfoldLabs\WP\Module\Data\HiiveConnection
 */
class HiiveConnectionWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Previously, choosing to use a non-blocking request was an option. Now the test is kept to
	 * ensure an error is returned when the response is invalid.
	 *
	 * @covers ::notify
	 */
	public function test_notify_non_blocking_returns_error(): void {

		$sut = new HiiveConnection();

		$event = Mockery::mock( Event::class );

		update_option( 'nfd_data_token', 'appear-connected' );

		$container = new \NewfoldLabs\WP\ModuleLoader\Container();
		container( $container );

		$plugin = Mockery::mock( \NewfoldLabs\WP\ModuleLoader\Plugin::class );
		$plugin->shouldReceive( 'get' )->andReturnUsing(
			function () {
				$args = func_get_args();
				return $args[1] ?? $args[0];
			}
		);
		$container->set( 'plugin', $plugin );

		/**
		 * @see WP_Http::request()
		 */
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'       => array(),
					'body'          => '',
					'response'      => array(
						'code'    => false,
						'message' => false,
					),
					'cookies'       => array(),
					'http_response' => null,
				);
			}
		);

		$result = $sut->notify( array( $event ) );

		$this->assertWPError( $result );
	}

	/**
	 * @covers ::notify
	 */
	public function test_notify_wp_error(): void {

		$sut = new HiiveConnection();

		$event = Mockery::mock( Event::class );

		update_option( 'nfd_data_token', 'appear-connected' );

		$container = new \NewfoldLabs\WP\ModuleLoader\Container();
		container( $container );

		$plugin = Mockery::mock( \NewfoldLabs\WP\ModuleLoader\Plugin::class );
		$plugin->shouldReceive( 'get' )->andReturnUsing(
			function () {
				$args = func_get_args();
				return $args[1] ?? $args[0];
			}
		);
		$container->set( 'plugin', $plugin );

		/**
		 * @see WP_Http::request()
		 */
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_not_executed', 'User has blocked requests through HTTP.' );
			}
		);

		$result = $sut->notify( array( $event ) );

		$this->assertWPError( $result );
	}

	/**
	 * @covers ::send_event
	 * @covers ::hiive_request
	 */
	public function test_plugin_search(): void {

		$sut = new HiiveConnection();

		update_option( 'nfd_data_token', 'appear-connected' );

		/**
		 * For {@see HiiveConnection::get_core_data()} to work, it needs this.
		 */
		$container = new \NewfoldLabs\WP\ModuleLoader\Container();
		container( $container );

		$plugin = Mockery::mock( \NewfoldLabs\WP\ModuleLoader\Plugin::class );
		$plugin->shouldReceive( 'get' )->andReturnUsing(
			function () {
				$args = func_get_args();
				return $args[1] ?? $args[0];
			}
		);
		$container->set( 'plugin', $plugin );

		/**
		 * @see WP_Http::request()
		 */
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'body'     => json_encode(
						array(
							'data' => array(
								array(
									'id'         => 'notification123',
									'locations'  => array(),
									'query'      => null,
									'expiration' => time(),
									'content'    => '<p>Some content</p>',
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
		);

		$event = new Event(
			'admin',
			'plugin_search',
			array(
				'type'  => 'term',
				'query' => 'seo',
			),
		);

		$result = $sut->send_event( $event );

		$this->assertEquals( 'notification123', $result[0]['id'] );
	}

	/**
	 * Hiive may return a 500 error which contains data `succeededEvents` and `failedEvents` which can be acted upon.
	 * If those keys are absent, treat it as a more serious 500 error.
	 */
	public function test_500_error_without_data(): void {

		$sut = Mockery::mock( HiiveConnection::class )->makePartial();
		$sut->expects('get_core_data')->andReturn(array());
		update_option( 'nfd_data_token', 'appear-connected' );

		/**
		 * @see WP_Http::request()
		 */
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'body'     => 'Internal Server Error',
					'response' => array(
						'code'    => 500,
						'message' => 'OK',
					),
				);
			}
		);

		$event = new Event(
			'admin',
			'plugin_search',
			array(
				'type'  => 'term',
				'query' => 'seo',
			),
		);

		$result = $sut->notify( array( $event ) );

		$this->assertWPError( $result );
	}

	/**
	 * Hiive may return a 500 error which contains data `succeededEvents` and `failedEvents` which can be acted upon.
	 * If those keys are absent, treat it as a more serious 500 error.
	 */
	public function test_500_error_with_data(): void {

		$sut = Mockery::mock( HiiveConnection::class )->makePartial();
		$sut->expects('get_core_data')->andReturn(array());
		update_option( 'nfd_data_token', 'appear-connected' );

		$event = new Event(
			'admin',
			'plugin_search',
			array(
				'type'  => 'term',
				'query' => 'seo',
			),
		);

		/**
		 * @see WP_Http::request()
		 */
		add_filter(
			'pre_http_request',
			function () use ( $event ) {
				return array(
					'body'     => json_encode(
						array(
							'succeededEvents' => array( 1 => $event ),
							'failedEvents'    => array( 2 => $event ),
						)
					),
					'response' => array(
						'code'    => 500,
						'message' => 'OK',
					),
				);
			}
		);

		$result = $sut->notify(
			array(
				1 => $event,
				2 => $event,
			)
		);

		$this->assertIsArray( $result );
	}
}
