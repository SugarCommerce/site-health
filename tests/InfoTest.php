<?php
/**
 * Info line tests.
 *
 * @package SugarCommerce\SiteHealth
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth\Tests;

use SugarCommerce\SiteHealth\Info;
use PHPUnit\Framework\TestCase;
use ShTable;

/**
 * The lines every plugin's section writes.
 *
 * Each line says one thing two ways: in the site's words on the screen and
 * in plain English in the copy. The copy is what reaches a support ticket,
 * so the copy is what these hold steady -- and the private flag, which is
 * what keeps who and where a site is out of it.
 */
final class InfoTest extends TestCase {

	/**
	 * A clean slate: options, tables.
	 */
	protected function setUp(): void {
		sh_reset();
	}

	/**
	 * A line, a private line, a line whose copy differs.
	 */
	public function test_a_line(): void {
		$this->assertSame(
			[
				'label' => 'Currency',
				'value' => 'GBP',
			],
			Info::line( 'Currency', 'GBP' )
		);
		$this->assertSame(
			[
				'label'   => 'Webhook address',
				'value'   => 'https://shop.test/hook',
				'private' => true,
			],
			Info::line( 'Webhook address', 'https://shop.test/hook', null, true )
		);
		$this->assertSame( 'live', Info::line( 'Mode', 'Live', 'live' )['debug'] );
	}

	/**
	 * Whether a thing is set, never the thing.
	 */
	public function test_yes_or_no(): void {
		$this->assertSame(
			[
				'label' => 'Key saved',
				'value' => 'Yes',
				'debug' => 'yes',
			],
			Info::yes_no( 'Key saved', true )
		);
		$this->assertSame( 'no', Info::yes_no( 'Key saved', false )['debug'] );
		$this->assertSame( 'No', Info::yes_no( 'Key saved', false )['value'] );
		$this->assertTrue( Info::yes_no( 'Sender set', true, true )['private'] );
	}

	/**
	 * A moment: how long ago on the screen, the time in the copy.
	 */
	public function test_a_moment(): void {
		$then = time() - 7200;
		$line = Info::when( 'Last webhook', $then );

		$this->assertSame( '2 hours ago', $line['value'] );
		$this->assertSame( gmdate( 'Y-m-d H:i', $then ) . ' UTC', $line['debug'] );

		// A date as a table stores it, in UTC.
		$this->assertSame( '2026-11-01 09:30 UTC', Info::when( 'Last', '2026-11-01 09:30:00' )['debug'] );

		// Ahead, not behind.
		$this->assertSame( 'In 3 hours', Info::when( 'Next', time() + 3 * 3600 )['value'] );

		// The plugin's own words for it.
		$this->assertSame( 'at 1', Info::when( 'Last', 1, static fn( int $time ): string => 'at ' . $time )['value'] );
	}

	/**
	 * No date is "not known", never the first of January 1970.
	 */
	public function test_no_moment(): void {
		foreach ( [ null, '', '  ', '0000-00-00 00:00:00', '0000-00-00', 0, -5, 'not a date', '1970-01-01 00:00:00' ] as $nothing ) {
			$line = Info::when( 'Last', $nothing );

			$this->assertSame( 'unknown', $line['debug'], var_export( $nothing, true ) );
			$this->assertSame( 'Not known', $line['value'] );
		}
	}

	/**
	 * When a job next runs, or that it is running, or that it is not scheduled.
	 */
	public function test_a_schedule(): void {
		$next = gmmktime( 3, 15, 0, 11, 18, 2026 );
		$line = Info::schedule( 'Clear-out', $next );

		$this->assertSame( 'Next due 2026-11-18 03:15', $line['value'] );
		$this->assertSame( 'next 2026-11-18 03:15 UTC', $line['debug'] );

		$this->assertSame( 'running', Info::schedule( 'Clear-out', true )['debug'] );
		$this->assertSame( 'Running now', Info::schedule( 'Clear-out', true )['value'] );
		$this->assertSame( 'not scheduled', Info::schedule( 'Clear-out', false )['debug'] );
		$this->assertSame( 'Not scheduled', Info::schedule( 'Clear-out', null )['value'] );
		$this->assertSame( 'not scheduled', Info::schedule( 'Clear-out', 0 )['debug'] );
		$this->assertSame( 'not scheduled', Info::schedule( 'Clear-out', -1 )['debug'] );
	}

	/**
	 * The version, and whether the server is new enough.
	 */
	public function test_versions(): void {
		$this->assertSame(
			[
				'label' => 'Version',
				'value' => '1.2.3',
			],
			Info::version( '1.2.3' )
		);

		$met = Info::requirements( '8.3', '7.1' );

		$this->assertSame( 'New enough', $met['value'] );
		$this->assertSame( 'met (needs PHP 8.3, WordPress 7.1)', $met['debug'] );

		$php = Info::requirements( '99.0', '7.1' );

		$this->assertSame( 'Too old: needs PHP 99.0 and WordPress 7.1', $php['value'] );
		$this->assertSame( 'NOT met (needs PHP 99.0, WordPress 7.1)', $php['debug'] );

		$this->assertStringStartsWith( 'NOT met', Info::requirements( '8.3', '7.2' )['debug'] );
		$this->assertSame( 'met (needs PHP -, WordPress -)', Info::requirements( '', '' )['debug'] );
		$this->assertTrue( Info::meets( '', '7.1.4' ) );
		$this->assertFalse( Info::meets( '', '7.1.5' ) );
		$this->assertFalse( Info::meets( '99', '' ) );
	}

	/**
	 * A release candidate is that release: 7.1-RC1 meets 7.1, as core's
	 * is_wp_version_compatible() says, but not 7.2.
	 */
	public function test_a_release_candidate_meets_its_release(): void {
		$GLOBALS['sh_version'] = '7.1-RC1';

		$this->assertTrue( Info::meets( '', '7.1' ) );
		$this->assertTrue( Info::meets( '', '7.0' ) );
		$this->assertFalse( Info::meets( '', '7.2' ) );
		$this->assertSame( 'met (needs PHP 8.3, WordPress 7.1)', Info::requirements( '8.3', '7.1' )['debug'] );

		$GLOBALS['sh_version'] = '7.1.4-src';

		$this->assertTrue( Info::meets( '', '7.1.4' ) );
		$this->assertFalse( Info::meets( '', '7.1.5' ) );
	}

	/**
	 * One line for the tables, naming only the missing.
	 */
	public function test_tables(): void {
		$GLOBALS['sh_options']['wpdb_shop_orders_version']    = '202611170001';
		$GLOBALS['sh_options']['wpdb_shop_customers_version'] = '202611170002';
		$GLOBALS['sh_tables']                                 = [ 'wp_shop_customers', 'wp_shop_logs' ];

		$lines = Info::tables(
			[
				new ShTable( 'wp_shop_orders' ),
				'shop_customers',
				'wp_shop_logs',
				new ShTable( 'other_prefix_table' ),
				5,
				'',
			]
		);

		$this->assertSame( 'All 4 are there', $lines['tables']['value'] );
		$this->assertSame( '4 present', $lines['tables']['debug'] );
		$this->assertSame(
			[
				'other_prefix_table' => '',
				'shop_customers'     => '202611170002',
				'shop_logs'          => '',
				'shop_orders'        => '202611170001',
			],
			$lines['table_versions']['value']
		);

		$lines = Info::tables( [ new ShTable( 'wp_shop_orders' ), new ShTable( 'wp_shop_notes', false ), 'shop_gone' ] );

		$this->assertSame( 'Missing: wp_shop_notes, wp_shop_gone', $lines['tables']['value'] );
		$this->assertSame( 'MISSING wp_shop_notes, wp_shop_gone', $lines['tables']['debug'] );
		$this->assertSame( [ 'shop_orders' => '202611170001' ], $lines['table_versions']['value'] );
	}

	/**
	 * The built-in libraries, and which commit a branch was built from.
	 */
	public function test_libraries(): void {
		$file = tempnam( sys_get_temp_dir(), 'sh' );

		file_put_contents(
			$file,
			(string) json_encode(
				[
					'packages' => [
						[
							'name'    => 'sugarcommerce/money',
							'version' => 'dev-main',
							'source'  => [ 'reference' => '0123456789abcdef' ],
						],
						[
							'name'    => 'sugarcommerce/geo',
							'version' => 'dev-main',
							'dist'    => [ 'reference' => 'fedcba9876543210' ],
						],
						[
							'name'    => 'berlindb/core',
							'version' => '3.0.0',
							'source'  => [ 'reference' => 'abcdef0123456789' ],
						],
						[
							'name'    => 'nobody/unpinned',
							'version' => 'dev-main',
						],
						[ 'version' => '1.0.0' ],
						'junk',
					],
				]
			)
		);

		$lines = Info::libraries( $file );

		$this->assertSame( 'Built-in libraries', $lines['libraries']['label'] );
		$this->assertSame(
			[
				'berlindb/core'       => '3.0.0',
				'nobody/unpinned'     => 'dev-main',
				'sugarcommerce/geo'   => 'dev-main @ fedcba9',
				'sugarcommerce/money' => 'dev-main @ 0123456',
			],
			$lines['libraries']['value']
		);

		// Composer 1 wrote a bare list.
		file_put_contents( $file, (string) json_encode( [ [ 'name' => 'old/list', 'version' => '2.0.0' ] ] ) );

		$this->assertSame( [ 'old/list' => '2.0.0' ], Info::libraries( $file )['libraries']['value'] );

		file_put_contents( $file, '{"packages": []}' );

		$this->assertSame( [], Info::libraries( $file ) );

		unlink( $file );

		$this->assertSame( [], Info::libraries( $file ) );
	}
}
