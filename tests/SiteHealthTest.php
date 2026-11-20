<?php
/**
 * Site Health tests.
 *
 * @package SugarCommerce\SiteHealth
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth\Tests;

use SugarCommerce\SiteHealth\Info;
use SugarCommerce\SiteHealth\Result;
use SugarCommerce\SiteHealth\SiteHealth;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * A plugin's checks and its section, from arrays to what the screen draws.
 *
 * What matters: every check the plugin defines reaches WordPress's list
 * under the plugin's name; an answer comes back in the shape the screen
 * needs whatever the check returned; a check that is not good offers the
 * plugin's link unless it says otherwise; and the section keeps the order
 * support reads it in, with add-ons let in at the end.
 */
final class SiteHealthTest extends TestCase {

	/**
	 * Empty the hooks and the registry.
	 */
	protected function setUp(): void {
		sh_reset();
	}

	/**
	 * A shop's part: three checks and a settings link.
	 *
	 * @param array<string, mixed> $config Changes to it.
	 *
	 * @return SiteHealth
	 */
	private function shop( array $config = [] ): SiteHealth {
		return SiteHealth::register(
			'shop',
			$config + [
				'label'   => 'Shop',
				'actions' => [
					'url'   => 'https://shop.test/wp-admin/admin.php?page=shop-settings',
					'label' => 'Open Shop settings',
				],
				'tests'   => [
					'keys'  => static fn(): array => Result::critical( 'Shop has no API key', 'Paste one into <code>Settings</code>.' ),
					'mode'  => [
						'label'    => 'Shop mode',
						'callback' => static fn(): array => Result::recommended( 'Shop is in test mode', 'No real payment can be taken.' ),
					],
					'queue' => static fn(): array => Result::good( 'Shop’s jobs can run', 'They run in the background.' ),
				],
			]
		);
	}

	/**
	 * What WordPress's list holds after every plugin has added to it.
	 *
	 * @return array<string, mixed>
	 */
	private function wordpress_tests(): array {
		return apply_filters(
			'site_status_tests',
			[
				'direct' => [ 'php_version' => [ 'label' => 'PHP' ] ],
				'async'  => [],
			]
		);
	}

	/**
	 * The plugin hooks into both tabs, and the REST API for async checks.
	 */
	public function test_it_hooks_in(): void {
		$set = $this->shop();

		$this->assertSame( 10, has_filter( 'site_status_tests', [ $set, 'register_tests' ] ) );
		$this->assertSame( 10, has_filter( 'debug_information', [ $set, 'section' ] ) );
		$this->assertSame( 10, has_filter( 'rest_api_init', [ $set, 'register_routes' ] ) );
		$this->assertSame( 'shop', $set->id() );

		$set = $this->shop( [ 'info' => false ] );

		$this->assertFalse( has_filter( 'debug_information', [ $set, 'section' ] ) );
	}

	/**
	 * Each check is in WordPress's list under the plugin's name.
	 */
	public function test_the_checks_are_listed(): void {
		$this->shop();

		$tests = $this->wordpress_tests();

		$this->assertArrayHasKey( 'php_version', $tests['direct'] );
		$this->assertSame( [ 'php_version', 'shop_keys', 'shop_mode', 'shop_queue' ], array_keys( $tests['direct'] ) );
		$this->assertSame( 'Shop', $tests['direct']['shop_keys']['label'] );
		$this->assertSame( 'Shop mode', $tests['direct']['shop_mode']['label'] );
		$this->assertArrayNotHasKey( 'skip_cron', $tests['direct']['shop_keys'] );

		$result = ( $tests['direct']['shop_keys']['test'] )();

		$this->assertSame( 'shop_keys', $result['test'] );
		$this->assertSame( 'Shop has no API key', $result['label'] );
	}

	/**
	 * An answer is dressed in the plugin's badge, and its words in a paragraph.
	 */
	public function test_a_result_is_dressed(): void {
		$set = $this->shop();

		$this->assertSame(
			[
				'label'       => 'Shop has no API key',
				'status'      => 'critical',
				'badge'       => [
					'label' => 'Shop',
					'color' => 'red',
				],
				'description' => '<p>Paste one into <code>Settings</code>.</p>',
				'actions'     => '<a href="https://shop.test/wp-admin/admin.php?page=shop-settings">Open Shop settings</a>',
				'test'        => 'shop_keys',
			],
			$set->run( 'keys' )
		);

		$mode = $set->run( 'mode' );

		$this->assertSame( 'blue', $mode['badge']['color'] );
		$this->assertSame( 'recommended', $mode['status'] );
		$this->assertStringContainsString( 'page=shop-settings', $mode['actions'] );

		$queue = $set->run( 'shop_queue' );

		$this->assertSame( 'good', $queue['status'] );
		$this->assertSame( '', $queue['actions'] );
		$this->assertSame( 'shop_queue', $queue['test'] );
	}

	/**
	 * The badge's colour, and a result that wants its own.
	 */
	public function test_the_badge(): void {
		$set = $this->shop(
			[
				'badge' => 'green',
				'tests' => [
					'own' => static fn(): array => [
						'status' => 'good',
						'label'  => 'Fine',
						'badge'  => [ 'label' => 'Security' ],
					],
					'bad' => static fn(): array => Result::critical( 'Bad' ),
				],
			]
		);

		$this->assertSame(
			[
				'label' => 'Security',
				'color' => 'green',
			],
			$set->run( 'own' )['badge']
		);
		$this->assertSame( 'red', $set->run( 'bad' )['badge']['color'] );
	}

	/**
	 * Markup that is already a block is used as it is, and only what a
	 * description needs survives.
	 */
	public function test_descriptions(): void {
		$this->assertSame( '<p>One <code>two</code></p>', Result::description( ' One <code>two</code> ' ) );
		$this->assertSame( '<p>Old copies:</p><ul><li>a.php</li></ul>', Result::description( '<p>Old copies:</p><ul><li>a.php</li></ul>' ) );
		$this->assertSame( '<ul><li>a</li></ul>', Result::description( '<ul><li>a</li></ul>' ) );
		$this->assertSame( '<p>alert(1)Hi</p>', Result::description( '<script>alert(1)</script>Hi' ) );
		$this->assertSame( '<p>x</p>', Result::description( '<div>x</div>' ) );
		$this->assertSame( '', Result::description( '   ' ) );
	}

	/**
	 * What a check that is not good offers, and what it can say instead.
	 */
	public function test_actions(): void {
		$set = $this->shop(
			[
				'tests' => [
					'none'   => static fn(): array => Result::recommended( 'A', 'B', '' ),
					'link'   => static fn(): array => Result::critical(
						'A',
						'B',
						[
							'url'   => 'https://shop.test/x',
							'label' => 'Fix <it>',
						]
					),
					'links'  => static fn(): array => Result::critical(
						'A',
						'B',
						[
							[
								'url'   => 'https://shop.test/a',
								'label' => 'One',
							],
							[
								'url'   => '',
								'label' => 'Nowhere',
							],
							[
								'url'   => 'https://shop.test/unnamed',
								'label' => '',
							],
							'not a link',
							[
								'url'   => 'https://shop.test/b',
								'label' => 'Two',
							],
						]
					),
					'markup' => static fn(): array => Result::good( 'A', 'B', '<p><a href="https://shop.test/c" onclick="x()">See</a></p><script>x()</script>' ),
				],
			]
		);

		$this->assertSame( '', $set->run( 'none' )['actions'] );
		$this->assertSame( '<a href="https://shop.test/x">Fix &lt;it&gt;</a>', $set->run( 'link' )['actions'] );
		$this->assertSame( '<p><a href="https://shop.test/a">One</a></p><p><a href="https://shop.test/b">Two</a></p>', $set->run( 'links' )['actions'] );
		$this->assertStringStartsWith( '<p><a href="https://shop.test/c"', $set->run( 'markup' )['actions'] );
		$this->assertStringNotContainsString( '<script', $set->run( 'markup' )['actions'] );
		$this->assertSame( '', Result::actions( null ) );
		$this->assertSame( '', Result::actions( 5 ) );
	}

	/**
	 * The plugin's link can be worked out when it is needed.
	 */
	public function test_the_plugins_link_can_be_a_callable(): void {
		$set = $this->shop(
			[
				'actions' => static fn(): array => [
					'url'   => admin_url( 'admin.php?page=later' ),
					'label' => 'Later',
				],
			]
		);

		$this->assertSame( '<a href="https://shop.test/wp-admin/admin.php?page=later">Later</a>', $set->run( 'keys' )['actions'] );

		$set = $this->shop( [ 'actions' => null ] );

		$this->assertSame( '', $set->run( 'keys' )['actions'] );
	}

	/**
	 * A check that answers nonsense still gives the screen something to draw.
	 */
	public function test_a_bad_answer(): void {
		$set = $this->shop(
			[
				'tests' => [
					'nothing' => static fn() => null,
					'odd'     => static fn(): array => [
						'status' => 'fine',
						'label'  => 'Odd',
					],
				],
			]
		);

		$nothing = $set->run( 'nothing' );

		$this->assertSame( 'recommended', $nothing['status'] );
		$this->assertSame( 'One of Shop’s checks gave no answer', $nothing['label'] );
		$this->assertSame( '', $nothing['actions'] );
		$this->assertSame( 'shop_nothing', $nothing['test'] );

		$this->assertSame( 'recommended', $set->run( 'odd' )['status'] );
		$this->assertSame( 'The check shop_missing is not available', $set->run( 'missing' )['label'] );
	}

	/**
	 * A check can be switched off, now or as it is asked.
	 */
	public function test_a_check_can_be_switched_off(): void {
		$on  = false;
		$set = $this->shop(
			[
				'tests' => [
					'never'     => [
						'callback' => static fn(): array => Result::good( 'Never' ),
						'enabled'  => false,
					],
					'sometimes' => [
						'callback' => static fn(): array => Result::good( 'Sometimes' ),
						'enabled'  => static function () use ( &$on ): bool {
							return $on;
						},
					],
					'broken'    => [ 'callback' => 'no_such_function' ],
				],
			]
		);

		$this->assertSame( [ 'php_version' ], array_keys( $this->wordpress_tests()['direct'] ) );

		$on = true;

		$this->assertSame( [ 'php_version', 'shop_sometimes' ], array_keys( $this->wordpress_tests()['direct'] ) );
		$this->assertSame( 'The check shop_never is not available', $set->run( 'never' )['label'] );
	}

	/**
	 * A slow check is asked over REST, and directly by the weekly cron run.
	 */
	public function test_an_async_check(): void {
		$set = $this->shop(
			[
				'tests' => [
					'remote' => [
						'label'     => 'Shop can reach its server',
						'callback'  => static fn(): array => Result::good( 'Reached' ),
						'async'     => true,
						'skip_cron' => true,
					],
				],
			]
		);

		$tests = $this->wordpress_tests();

		$this->assertArrayNotHasKey( 'shop_remote', $tests['direct'] );

		$async = $tests['async']['shop_remote'];

		$this->assertSame( 'https://shop.test/wp-json/shop-site-health/v1/tests/remote', $async['test'] );
		$this->assertTrue( $async['has_rest'] );
		$this->assertTrue( $async['skip_cron'] );
		$this->assertSame( 'Shop can reach its server', $async['label'] );
		$this->assertSame( 'shop_remote', ( $async['async_direct_test'] )()['test'] );

		do_action( 'rest_api_init' );

		$route = $GLOBALS['sh_routes']['shop-site-health/v1/tests/(?P<test>[a-zA-Z0-9_-]+)'];

		$this->assertSame( 'GET', $route['methods'] );
		$this->assertSame( [ 'type' => 'string', 'required' => true, 'pattern' => '^[a-zA-Z0-9_-]+$' ], $route['args']['test'] );
		$this->assertTrue( ( $route['permission_callback'] )() );

		$GLOBALS['sh_caps'] = [];

		$this->assertFalse( ( $route['permission_callback'] )() );
		$this->assertSame( 'Reached', $set->respond( [ 'test' => 'remote' ] )['label'] );
	}

	/**
	 * Only an async check can be asked for over REST.
	 */
	public function test_rest_refuses_what_is_not_async(): void {
		$set = $this->shop();

		$answer = $set->respond( [ 'test' => 'keys' ] );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 404, $answer->data['status'] );
		$this->assertInstanceOf( WP_Error::class, $set->respond( [ 'test' => 'nope' ] ) );
	}

	/**
	 * A plugin with no async checks adds no route; its own namespace can be chosen.
	 */
	public function test_the_route(): void {
		$this->shop();

		do_action( 'rest_api_init' );

		$this->assertSame( [], $GLOBALS['sh_routes'] );

		sh_reset();
		SiteHealth::register(
			'My_Shop',
			[
				'tests' => [
					'slow' => [
						'callback' => 'time',
						'async'    => true,
					],
				],
			]
		);

		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( 'my-shop-site-health/v1/tests/(?P<test>[a-zA-Z0-9_-]+)', $GLOBALS['sh_routes'] );

		sh_reset();
		SiteHealth::register(
			'shop',
			[
				'rest_namespace' => 'shop/v2',
				'tests'          => [
					'slow' => [
						'callback' => 'time',
						'async'    => true,
					],
				],
			]
		);

		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( 'shop/v2/tests/(?P<test>[a-zA-Z0-9_-]+)', $GLOBALS['sh_routes'] );
	}

	/**
	 * An add-on's check wears the plugin's badge.
	 */
	public function test_an_add_on_adds_a_check(): void {
		add_filter(
			'shop_site_health_tests',
			static fn( array $tests ): array => $tests + [
				'affiliates' => static fn(): array => Result::critical( 'Payouts are stuck' ),
				'remote'     => [
					'callback' => static fn(): array => Result::good( 'Far away' ),
					'async'    => true,
				],
				'junk'       => 'not a check',
			]
		);

		$set   = $this->shop();
		$tests = $this->wordpress_tests();

		$this->assertArrayHasKey( 'shop_affiliates', $tests['direct'] );
		$this->assertArrayNotHasKey( 'shop_junk', $tests['direct'] );
		$this->assertSame( 'Shop', $set->run( 'affiliates' )['badge']['label'] );

		do_action( 'rest_api_init' );

		$this->assertCount( 1, $GLOBALS['sh_routes'] );
		$this->assertSame( 'Far away', $set->respond( [ 'test' => 'remote' ] )['label'] );
	}

	/**
	 * The section is the plugin's own, beside everybody else's.
	 */
	public function test_the_section(): void {
		$this->shop(
			[
				'info' => [
					'description' => 'How the shop is set up.',
					'fields'      => [ 'mode' => Info::line( 'Mode', 'Test', 'test' ) ],
				],
			]
		);

		$info = apply_filters( 'debug_information', [ 'wp-core' => [ 'label' => 'WordPress' ] ] );

		$this->assertSame( [ 'wp-core', 'shop' ], array_keys( $info ) );
		$this->assertSame(
			[
				'label'       => 'Shop',
				'description' => 'How the shop is set up.',
				'fields'      => [
					'mode' => [
						'label' => 'Mode',
						'value' => 'Test',
						'debug' => 'test',
					],
				],
			],
			$info['shop']
		);

		$this->shop(
			[
				'info' => [
					'label'      => 'Shop (private)',
					'private'    => true,
					'show_count' => true,
				],
			]
		);

		$section = apply_filters( 'debug_information', [] )['shop'];

		$this->assertSame( 'Shop (private)', $section['label'] );
		$this->assertTrue( $section['private'] );
		$this->assertTrue( $section['show_count'] );
		$this->assertSame( '', $section['description'] );
	}

	/**
	 * The lines come in the order support reads them, and add-ons come last.
	 */
	public function test_the_lines_and_their_order(): void {
		$GLOBALS['sh_tables']                           = [ 'wp_shop_orders' ];
		$GLOBALS['sh_options']['wpdb_shop_orders_version'] = '202611170001';

		$file = tempnam( sys_get_temp_dir(), 'sh' );
		file_put_contents( $file, (string) json_encode( [ 'packages' => [ [ 'name' => 'sugarcommerce/money', 'version' => '1.0.0' ] ] ] ) );

		add_filter(
			'shop_site_info',
			static fn( array $fields ): array => $fields + [ 'addon' => Info::line( 'Add-on', 'on' ) ]
		);

		$set = $this->shop(
			[
				'info' => [
					'version'   => '1.2.3',
					'requires'  => [
						'php' => '8.3',
						'wp'  => '7.1',
					],
					'fields'    => static fn(): array => [
						'mode'    => Info::line( 'Mode', 'Live', 'live' ),
						'version' => Info::line( 'Version', 'overridden' ),
					],
					'tables'    => static fn(): array => [ 'shop_orders' ],
					'libraries' => $file,
				],
			]
		);

		$fields = $set->fields();

		unlink( $file );

		$this->assertSame( [ 'version', 'requirements', 'mode', 'tables', 'table_versions', 'libraries', 'addon' ], array_keys( $fields ) );
		$this->assertSame( 'overridden', $fields['version']['value'] );
		$this->assertStringStartsWith( 'met', $fields['requirements']['debug'] );
		$this->assertSame( [ 'shop_orders' => '202611170001' ], $fields['table_versions']['value'] );
		$this->assertSame( [ 'sugarcommerce/money' => '1.0.0' ], $fields['libraries']['value'] );

		// Tables given as a list rather than a callable.
		$set = $this->shop( [ 'info' => [ 'tables' => [ new \ShTable( 'wp_shop_gone', false ) ] ] ] );

		$this->assertSame( 'MISSING wp_shop_gone', $set->fields()['tables']['debug'] );

		// Nothing iterable is nothing.
		$set = $this->shop( [ 'info' => [ 'tables' => static fn(): string => 'nope' ] ] );

		$this->assertSame( '0 present', $set->fields()['tables']['debug'] );
	}

	/**
	 * The add-on filter's name is the plugin's to choose, or to switch off.
	 */
	public function test_the_info_filter(): void {
		add_filter( 'shop_site_info', static fn( array $fields ): array => $fields + [ 'default' => Info::line( 'D', 'd' ) ] );
		add_filter( 'shop_debug_lines', static fn( array $fields ): array => $fields + [ 'custom' => Info::line( 'C', 'c' ) ] );

		$this->assertSame( [ 'default' ], array_keys( $this->shop()->fields() ) );
		$this->assertSame( [ 'custom' ], array_keys( $this->shop( [ 'info' => [ 'filter' => '{id}_debug_lines' ] ] )->fields() ) );
		add_filter( '', static fn( array $fields ): array => $fields + [ 'nameless' => Info::line( 'N', 'n' ) ] );

		$this->assertSame( [], $this->shop( [ 'info' => [ 'filter' => false ] ] )->fields() );
	}

	/**
	 * Registering again replaces the plugin's part; unregistering takes it out.
	 */
	public function test_registering_again_and_unregistering(): void {
		$first  = $this->shop();
		$second = $this->shop();

		$this->assertSame( $second, SiteHealth::get( 'shop' ) );
		$this->assertFalse( has_filter( 'site_status_tests', [ $first, 'register_tests' ] ) );
		$this->assertFalse( has_filter( 'debug_information', [ $first, 'section' ] ) );
		$this->assertFalse( has_filter( 'rest_api_init', [ $first, 'register_routes' ] ) );

		$this->assertTrue( SiteHealth::unregister( 'shop' ) );
		$this->assertFalse( SiteHealth::unregister( 'shop' ) );
		$this->assertNull( SiteHealth::get( 'shop' ) );
		$this->assertSame( [ 'php_version' ], array_keys( $this->wordpress_tests()['direct'] ) );
		$this->assertSame( [], apply_filters( 'debug_information', [] ) );
	}

	/**
	 * A plugin with no section leaves the Info tab alone even if asked.
	 */
	public function test_no_section(): void {
		$set = $this->shop( [ 'info' => false ] );

		$this->assertSame( [ 'x' => [] ], $set->section( [ 'x' => [] ] ) );
	}

	/**
	 * A plugin needs an id.
	 */
	public function test_an_id_is_needed(): void {
		$this->expectException( InvalidArgumentException::class );

		SiteHealth::register( '' );
	}

	/**
	 * The screen's addresses.
	 */
	public function test_the_addresses(): void {
		$this->assertSame( 'https://shop.test/wp-admin/site-health.php?tab=debug', SiteHealth::info_url() );
		$this->assertSame( 'https://shop.test/wp-admin/site-health.php', SiteHealth::status_url() );

		$set = SiteHealth::register( 'shop', [] );

		$this->assertSame( $set, SiteHealth::get( 'shop' ) );
		$this->assertSame( 'shop', apply_filters( 'debug_information', [] )['shop']['label'] );
		$this->assertSame( 'shop_x', $set->key( 'x' ) );
	}

	/**
	 * A feature with every setting is good, with its own headline.
	 */
	public function test_all_or_none_all_set(): void {
		$result = Result::all_or_none( 'Discord roles', [ 'Client ID' => 'abc', 'Bot token' => 'xyz' ], 'Buyers get their role', 'Buyers get no role.' );

		$this->assertSame( [ 'status' => 'good', 'label' => 'Buyers get their role', 'description' => '' ], $result );
	}

	/**
	 * A feature with no setting is off: recommended, saying how to switch it on.
	 */
	public function test_all_or_none_none_set(): void {
		$result = Result::all_or_none( 'Discord roles', [ 'Client ID' => '', 'Bot token' => null ], 'On', 'Buyers get no role.' );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Discord roles is switched off', $result['label'] );
		$this->assertSame( 'Buyers get no role. Set <strong>Client ID</strong>, <strong>Bot token</strong> to switch it on.', $result['description'] );
	}

	/**
	 * Half a feature is recommended, naming only what is missing.
	 */
	public function test_all_or_none_half_set(): void {
		$result = Result::all_or_none( 'Discord roles', [ 'Client ID' => 'abc', 'Bot token' => false, 'Guild <id>' => '' ], 'On', 'Buyers get no role.' );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Discord roles is only half set up', $result['label'] );
		$this->assertSame( 'Missing: <strong>Bot token</strong>, <strong>Guild &lt;id&gt;</strong>. Buyers get no role.', $result['description'] );
	}

	/**
	 * Zero and "0" are values, not missing settings.
	 */
	public function test_all_or_none_counts_zero_as_set(): void {
		$this->assertSame( 'good', Result::all_or_none( 'Limit', [ 'Max' => 0, 'Min' => '0' ], 'On', 'Off.' )['status'] );
	}
}
