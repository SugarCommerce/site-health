<?php
/**
 * PHPUnit bootstrap.
 *
 * A library, not a plugin. The hooks are a real little hook table so a
 * test can run `site_status_tests` and `debug_information` the way
 * WordPress does and look at what comes out; the rest is stubbed only as
 * far as the library touches it. wp_kses() keeps the allowed tags and drops
 * the rest, which is the part of it a test here can tell apart.
 *
 * @package SugarCommerce\SiteHealth
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function sh_reset(): void {
	$GLOBALS['sh_hooks']   = [];
	$GLOBALS['sh_routes']  = [];
	$GLOBALS['sh_options'] = [
		'date_format' => 'Y-m-d',
		'time_format' => 'H:i',
	];
	$GLOBALS['sh_caps']    = [ 'view_site_health_checks' ];
	$GLOBALS['sh_tables']  = [];
	$GLOBALS['sh_version'] = '7.1.4';
	$GLOBALS['wpdb']       = new ShWpdb();

	( new ReflectionProperty( 'SugarCommerce\SiteHealth\SiteHealth', 'sets' ) )->setValue( null, [] );
}

/**
 * Enough of $wpdb to ask whether a table is there.
 */
final class ShWpdb {

	/**
	 * The site's table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Prepare a query.
	 *
	 * @param string $query The query.
	 * @param mixed  ...$args Its values.
	 *
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return str_replace( '%s', "'" . $args[0] . "'", $query );
	}

	/**
	 * Escape for LIKE.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * One value: the table's name if it is there.
	 *
	 * @param string $query The query.
	 *
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {
		preg_match( "/LIKE '(.*)'$/", $query, $match );

		$name = stripslashes( $match[1] ?? '' );

		return in_array( $name, $GLOBALS['sh_tables'], true ) ? $name : null;
	}
}

/**
 * A BerlinDB table, as far as the library asks one.
 */
final class ShTable {

	/**
	 * Construct.
	 *
	 * @param string $table_name The table's full name.
	 * @param bool   $there      Whether it exists.
	 */
	public function __construct( public string $table_name, private bool $there = true ) {}

	/**
	 * Whether it exists.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->there;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Enough of WP_Error to be one.
	 */
	class WP_Error {

		/**
		 * Construct.
		 *
		 * @param string $code    The code.
		 * @param string $message The message.
		 * @param mixed  $data    Its data.
		 */
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sh_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];

	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['sh_hooks'][ $hook ] ?? [] as $index => [ $registered, $at ] ) {
		if ( $registered === $callback && $at === $priority ) {
			unset( $GLOBALS['sh_hooks'][ $hook ][ $index ] );

			return true;
		}
	}

	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return remove_filter( $hook, $callback, $priority );
}

function has_filter( $hook, $callback = false ) {
	if ( false === $callback ) {
		return ! empty( $GLOBALS['sh_hooks'][ $hook ] );
	}

	foreach ( $GLOBALS['sh_hooks'][ $hook ] ?? [] as [ $registered, $at ] ) {
		if ( $registered === $callback ) {
			return $at;
		}
	}

	return false;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['sh_hooks'][ $hook ] ?? [] as [ $callback, , $accepted ] ) {
		$value = $callback( ...array_slice( array_merge( [ $value ], $args ), 0, max( 1, $accepted ) ) );
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['sh_hooks'][ $hook ] ?? [] as [ $callback, , $accepted ] ) {
		$callback( ...array_slice( $args, 0, $accepted ) );
	}
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return str_replace( '"', '%22', (string) $url );
}

function wp_kses( $content, $allowed ) {
	return strip_tags( (string) $content, array_keys( $allowed ) );
}

function admin_url( $path = '' ) {
	return 'https://shop.test/wp-admin/' . $path;
}

function rest_url( $path = '' ) {
	return 'https://shop.test/wp-json/' . $path;
}

function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['sh_routes'][ $namespace . $route ] = $args;

	return true;
}

function current_user_can( $capability, ...$args ) {
	return in_array( $capability, $GLOBALS['sh_caps'], true );
}

function get_option( $name, $default_value = false ) {
	return $GLOBALS['sh_options'][ $name ] ?? $default_value;
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? $GLOBALS['sh_version'] : '';
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?? time() );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function human_time_diff( $from, $to = 0 ) {
	$diff = abs( (int) $to - (int) $from );

	return $diff >= 3600 ? round( $diff / 3600 ) . ' hours' : round( $diff / 60 ) . ' mins';
}

sh_reset();
