<?php
/**
 * Lines of a Site Health > Info section.
 *
 * @package   SugarCommerce\SiteHealth
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The lines every plugin's section ends up writing.
 *
 * Each line is `label`, `value` (what the screen shows), `debug` (what the
 * Copy button copies, in English and without decoration, because whoever
 * reads it may not read the site's language) and, for the few that are,
 * `private` -- shown on the screen and left out of the copy. Anything that
 * says who or where a site is rather than how it is set up (an address
 * somebody writes to, a webhook URL) is private: the copied text ends up in
 * support tickets and public forums.
 *
 * **Never put a key, a password or a token in a line.** Say whether one is
 * set: yes_no() is there for exactly that.
 *
 * @since 1.0.0
 */
final class Info {

	/**
	 * One line.
	 *
	 * @param string                            $label   What it is.
	 * @param string|array<string, string>      $value   What the screen shows.
	 * @param string|array<string, string>|null $debug   What the copied text says; the value when null.
	 * @param bool                              $hidden  Whether it is private: left out of the copied text.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function line( string $label, string|array $value, string|array|null $debug = null, bool $hidden = false ): array {
		$line = [
			'label' => $label,
			'value' => $value,
		];

		if ( null !== $debug ) {
			$line['debug'] = $debug;
		}

		if ( $hidden ) {
			$line['private'] = true;
		}

		return $line;
	}

	/**
	 * A line that is a yes or a no: whether a key is saved, not the key.
	 *
	 * @param string $label  What it is.
	 * @param bool   $yes    Whether it is so.
	 * @param bool   $hidden Whether it is private: left out of the copied text.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function yes_no( string $label, bool $yes, bool $hidden = false ): array {
		return self::line(
			$label,
			$yes ? __( 'Yes', 'sugarcommerce' ) : __( 'No', 'sugarcommerce' ),
			$yes ? 'yes' : 'no',
			$hidden
		);
	}

	/**
	 * A line that is a moment: how long ago on the screen, the time in the copy.
	 *
	 * @param string          $label The line's label.
	 * @param int|string|null $time  A Unix time, or a date as tables store it, in UTC.
	 * @param callable|null   $shown What the screen says for a Unix time; "3 hours ago" when null.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function when( string $label, int|string|null $time, ?callable $shown = null ): array {
		$timestamp = self::timestamp( $time );

		// A row with no date on it has one all the same: nothing, which
		// would be printed as the first of January 1970.
		if ( null === $timestamp ) {
			return self::line( $label, __( 'Not known', 'sugarcommerce' ), 'unknown' );
		}

		$value = null !== $shown ? (string) $shown( $timestamp ) : self::ago( $timestamp );

		return self::line( $label, $value, gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC' );
	}

	/**
	 * A line that is when a scheduled job next runs.
	 *
	 * Takes what wp_next_scheduled() or as_next_scheduled_action() answer:
	 * a time it is next due, true for "running this moment" (Action
	 * Scheduler's answer for a job in progress), or false for not scheduled.
	 *
	 * @param string        $label What the job is for.
	 * @param int|bool|null $next  When it is next due.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function schedule( string $label, int|bool|null $next ): array {
		if ( is_int( $next ) && $next > 0 ) {
			$due = (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next );

			return self::line(
				$label,
				/* translators: %s: a date and time. */
				sprintf( __( 'Next due %s', 'sugarcommerce' ), $due ),
				'next ' . gmdate( 'Y-m-d H:i', $next ) . ' UTC'
			);
		}

		$running = true === $next;

		return self::line(
			$label,
			$running ? __( 'Running now', 'sugarcommerce' ) : __( 'Not scheduled', 'sugarcommerce' ),
			$running ? 'running' : 'not scheduled'
		);
	}

	/**
	 * The plugin's version.
	 *
	 * @param string $version The version.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function version( string $version ): array {
		return self::line( __( 'Version', 'sugarcommerce' ), $version );
	}

	/**
	 * Whether this server is new enough for the plugin.
	 *
	 * @param string $php The PHP version it needs, or '' for any.
	 * @param string $wp  The WordPress version it needs, or '' for any.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function requirements( string $php, string $wp ): array {
		$meets = self::meets( $php, $wp );
		$php   = '' !== $php ? $php : '-';
		$wp    = '' !== $wp ? $wp : '-';

		$shown = $meets
			? __( 'New enough', 'sugarcommerce' )
			/* translators: 1: a PHP version, 2: a WordPress version. */
			: sprintf( __( 'Too old: needs PHP %1$s and WordPress %2$s', 'sugarcommerce' ), $php, $wp );

		$debug = sprintf( '%s (needs PHP %s, WordPress %s)', $meets ? 'met' : 'NOT met', $php, $wp );

		return self::line( __( 'PHP and WordPress versions', 'sugarcommerce' ), $shown, $debug );
	}

	/**
	 * Whether this server is new enough.
	 *
	 * @param string $php The PHP version needed, or '' for any.
	 * @param string $wp  The WordPress version needed, or '' for any.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function meets( string $php, string $wp ): bool {
		// Every version is at least '', so an empty one asks for nothing.
		return version_compare( PHP_VERSION, $php, '>=' )
			&& version_compare( (string) get_bloginfo( 'version' ), $wp, '>=' );
	}

	/**
	 * The plugin's database tables: whether each is there, and its version.
	 *
	 * One line for the lot. Twenty-five lines saying "there" would bury
	 * everything else in the section; what matters is the ones that are not,
	 * and those are named. The versions are BerlinDB's, which it keeps in an
	 * option named after the table without the site's prefix
	 * (`wpdb_{table}_version`) and gives no way to ask the table for.
	 *
	 * @param iterable<mixed> $tables BerlinDB tables (anything with `table_name` and exists()), or table
	 *                                names, with or without the site's prefix.
	 *
	 * @return array<string, array<string, mixed>> `tables` and `table_versions`.
	 * @since 1.0.0
	 */
	public static function tables( iterable $tables ): array {
		global $wpdb;

		$versions = [];
		$missing  = [];

		foreach ( $tables as $table ) {
			if ( is_object( $table ) && isset( $table->table_name ) && method_exists( $table, 'exists' ) ) {
				$name   = (string) $table->table_name;
				$exists = (bool) $table->exists();
			} elseif ( is_string( $table ) && '' !== $table ) {
				$name   = str_starts_with( $table, $wpdb->prefix ) ? $table : $wpdb->prefix . $table;
				$exists = self::table_exists( $name );
			} else {
				continue;
			}

			if ( ! $exists ) {
				$missing[] = $name;

				continue;
			}

			$bare              = str_starts_with( $name, $wpdb->prefix ) ? substr( $name, strlen( $wpdb->prefix ) ) : $name;
			$versions[ $bare ] = (string) get_option( 'wpdb_' . $bare . '_version', '' );
		}

		ksort( $versions );

		$summary = [] === $missing
			/* translators: %s: how many database tables. */
			? sprintf( __( 'All %s are there', 'sugarcommerce' ), number_format_i18n( count( $versions ) ) )
			/* translators: %s: a list of database table names. */
			: sprintf( __( 'Missing: %s', 'sugarcommerce' ), implode( ', ', $missing ) );

		$debug = [] === $missing ? count( $versions ) . ' present' : 'MISSING ' . implode( ', ', $missing );

		return [
			'tables'         => self::line( __( 'Database tables', 'sugarcommerce' ), $summary, $debug ),
			'table_versions' => self::line( __( 'Table versions', 'sugarcommerce' ), $versions ),
		];
	}

	/**
	 * The libraries built into the plugin, and which commit of each.
	 *
	 * Read from the list Composer leaves beside them -- `vendor/composer/
	 * installed.json`, or Strauss's copy of it. A few kilobytes, read only
	 * when the screen is opened.
	 *
	 * @param string $file The installed.json file.
	 *
	 * @return array<string, array<string, mixed>> `libraries`, or nothing when there is no list.
	 * @since 1.0.0
	 */
	public static function libraries( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file in the plugin's own folder.
		$installed = json_decode( (string) file_get_contents( $file ), true );
		$libraries = [];

		foreach ( (array) ( $installed['packages'] ?? $installed ?? [] ) as $package ) {
			$name = is_array( $package ) ? (string) ( $package['name'] ?? '' ) : '';

			if ( '' === $name ) {
				continue;
			}

			// A branch is not a version: "dev-main" says nothing about what
			// is installed, so the commit it was built from goes beside it.
			$commit    = (string) ( $package['source']['reference'] ?? $package['dist']['reference'] ?? '' );
			$reference = substr( $commit, 0, 7 );
			$version   = (string) ( $package['version'] ?? '' );
			$is_branch = str_starts_with( $version, 'dev-' ) && '' !== $reference;

			$libraries[ $name ] = $is_branch ? $version . ' @ ' . $reference : $version;
		}

		ksort( $libraries );

		if ( [] === $libraries ) {
			return [];
		}

		return [ 'libraries' => self::line( __( 'Built-in libraries', 'sugarcommerce' ), $libraries ) ];
	}

	/**
	 * A Unix time from what a line was given, or null for none.
	 *
	 * @param int|string|null $time A Unix time or a UTC date.
	 *
	 * @return int|null
	 */
	private static function timestamp( int|string|null $time ): ?int {
		if ( is_int( $time ) ) {
			return $time > 0 ? $time : null;
		}

		$time = trim( (string) $time );

		if ( '' === $time ) {
			return null;
		}

		// MySQL's zero date, `0000-00-00 00:00:00`, reads as a moment in
		// the year -1 and is turned away with everything before 1970.

		try {
			$date = new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			return null;
		}

		$timestamp = $date->getTimestamp();

		return $timestamp > 0 ? $timestamp : null;
	}

	/**
	 * How long ago, or how long until, in WordPress's words.
	 *
	 * @param int $timestamp A Unix time.
	 *
	 * @return string
	 */
	private static function ago( int $timestamp ): string {
		$now = time();

		return $timestamp <= $now
			/* translators: %s: a length of time, such as "3 hours". */
			? sprintf( __( '%s ago', 'sugarcommerce' ), human_time_diff( $timestamp, $now ) )
			/* translators: %s: a length of time, such as "3 hours". */
			: sprintf( __( 'In %s', 'sugarcommerce' ), human_time_diff( $now, $timestamp ) );
	}

	/**
	 * Whether a table is there.
	 *
	 * @param string $name The table's full name.
	 *
	 * @return bool
	 */
	private static function table_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asked only when the screen is opened, and the answer must be today's.
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}
}
