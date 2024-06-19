<?php

namespace NewfoldLabs\WP\Module\Data;

use Mockery;
use NewfoldLabs\WP\Module\Data\Listeners\Admin;
use WP_Mock;

/**
 * @coversDefaultClass \NewfoldLabs\WP\Module\Data\EventManager
 */
class EventManagerTest extends \WP_Mock\Tools\TestCase {
	public function tearDown(): void {
		parent::tearDown();

		\Patchwork\restoreAll();
	}

	/**
	 * @covers ::initialize_listeners
	 */
	public function test_initialize_listeners_register_hooks() {

		$this->markTestSkipped('WP_Mock AnyInstance not available in until PHP 7.4.');

		$sut = Mockery::mock(EventManager::class)->makePartial();
		$sut->expects('get_listeners')->andReturn( array( '\\NewfoldLabs\\WP\\Module\\Data\\Listeners\\WP_Mail' ) );

		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ) {
				switch ($constant_name) {
					case 'BURST_SAFETY_MODE':
						return false;
					default:
						return \Patchwork\relay(func_get_args());
				}
			}
		);

		WP_Mock::expectFilterAdded( 'cron_schedules', array( $sut, 'add_minutely_schedule' ) );
		WP_Mock::expectActionAdded('nfd_data_sync_cron', array( $sut, 'send_batch'));
		WP_Mock::userFunction('wp_next_scheduled' )->andReturnTrue();
		WP_Mock::expectActionAdded('shutdown', array( $sut, 'shutdown'));

		// WP_Mock AnyInstance not available in until PHP 7.4.
		WP_Mock::expectActionAdded('admin_footer', array( new AnyInstance( \NewfoldLabs\WP\Module\Data\Listeners\WPMail::class ), 'mail_succeeded') );

		$sut->init();
	}

	/**
	 * @covers ::initialize_listeners
	 */
	public function test_initialize_listeners_burst_safety_enabled_no_listeners_hooks_registered() {
		$sut = new EventManager();

		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ) {
				switch ($constant_name) {
					case 'BURST_SAFETY_MODE':
						return true;
					default:
						return \Patchwork\relay(func_get_args());
				}
			}
		);

		\Patchwork\redefine(
			'constant',
			function ( string $constant_name ) {
				switch ($constant_name) {
					case 'BURST_SAFETY_MODE':
						return true;
					default:
						return \Patchwork\relay(func_get_args());
				}
			}
		);

		WP_Mock::expectFilterAdded( 'cron_schedules', array( $sut, 'add_minutely_schedule' ) );
		WP_Mock::expectActionAdded('nfd_data_sync_cron', array( $sut, 'send_batch'));
		WP_Mock::userFunction('wp_next_scheduled' )->andReturnTrue();
		WP_Mock::expectActionAdded('shutdown', array( $sut, 'shutdown'));

		$sut->init();

		/**
		 * Test fails if {@see Listener::register_hooks()} is called.
		 *
		 * `Unexpected use of add_action for action admin_footer with callback NewfoldLabs\WP\Module\Data\Listeners\Admin::view`
		 */
		$this->expectNotToPerformAssertions();
	}

	/**
	 * @covers ::initialize_cron
	 */
	public function test_initialize_cron(): void {
		$sut = new EventManager();

		/**
		 * @see EventManager::initialize_listeners()
		 */
		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ) {
				switch ($constant_name) {
					case 'BURST_SAFETY_MODE':
						return true;
					default:
						return \Patchwork\relay(func_get_args());
				}
			}
		);

		\Patchwork\redefine(
			'constant',
			function ( string $constant_name ) {
				switch ($constant_name) {
					case 'BURST_SAFETY_MODE':
						return true;
					case 'MINUTE_IN_SECONDS':
						return 60;
					default:
						return \Patchwork\relay(func_get_args());
				}
			}
		);

		WP_Mock::expectFilterAdded( 'cron_schedules', array( $sut, 'add_minutely_schedule' ) );
		WP_Mock::expectActionAdded('nfd_data_sync_cron', array( $sut, 'send_batch'));

		WP_Mock::userFunction('wp_next_scheduled' )
		       ->once()
		       ->with('nfd_data_sync_cron')
		       ->andReturnFalse();

		\Patchwork\redefine(
			'time',
			function() { return 0; }
		);

		WP_Mock::userFunction('wp_schedule_event' )
		       ->once()
		       ->with(60, 'minutely', 'nfd_data_sync_cron' );

		WP_Mock::expectActionAdded('shutdown', array( $sut, 'shutdown'));

		$sut->init();

		$this->expectNotToPerformAssertions();
	}

	/**
	 * @covers ::init
	 */
	public function test_init(): void {
		$sut = Mockery::mock(EventManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
		$sut->expects('initialize_listeners');
		$sut->expects('initialize_cron');

		WP_Mock::expectActionAdded('shutdown', array( $sut, 'shutdown'));

		$sut->init();

		$this->expectNotToPerformAssertions();
	}


	
}
