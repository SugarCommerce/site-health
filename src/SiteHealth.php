<?php
/**
 * A plugin's part of Site Health.
 *
 * @package   SugarCommerce\SiteHealth
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth;

use InvalidArgumentException;
use SugarCommerce\SiteHealth\Traits\SiteHealth\Checks;
use SugarCommerce\SiteHealth\Traits\SiteHealth\InfoSection;

/**
 * A plugin's checks on Tools > Site Health, and its section of the Info tab.
 *
 * WordPress already has the screen people are told to look at when
 * something is wrong, and the one support asks them to copy from. A plugin
 * that adds its own "system status" page makes a second place to look and
 * a second thing to keep true, so this puts the plugin into those two
 * instead.
 *
 * The two tabs answer different questions. A check (Status) says whether
 * something is wrong and what to do about it; the section (Info) says what
 * is there, with no opinion.
 *
 * Checks are named `{id}_{check}`, carry the plugin's badge -- red when
 * critical -- and, unless they say otherwise, a link to wherever the plugin
 * is fixed when they are not good. A slow check (one that makes an HTTP
 * request) can be `async`: the screen asks it over REST after the page has
 * drawn, and WordPress's weekly cron run calls it directly.
 *
 * The section is keyed by the id. Its lines pass through `{id}_site_info`,
 * which is where an add-on adds its own; checks pass through
 * `{id}_site_health_tests` the same way.
 *
 * @since 1.0.0
 *
 * The checks are Traits/SiteHealth/Checks and the Info section
 * Traits/SiteHealth/InfoSection; the registry of plugins is here.
 */
final class SiteHealth {

	use Checks;
	use InfoSection;

	/**
	 * Every registered plugin, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $sets = [];

	/**
	 * The id: names the checks, the section and the hooks.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The plugin's name, on the badge and over the section.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * The badge's colour for anything not critical.
	 *
	 * @var string
	 */
	private string $badge;

	/**
	 * What a result that is not good offers to do, when it does not say.
	 *
	 * @var string|array|callable|null
	 */
	private $actions;

	/**
	 * The checks: id => definition.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $tests;

	/**
	 * The section, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $info;

	/**
	 * The REST namespace async checks are asked through.
	 *
	 * @var string
	 */
	private string $rest_namespace;

	/**
	 * Put a plugin into Site Health.
	 *
	 * Registering again under the same id replaces the plugin's part.
	 *
	 * @param string               $id     The plugin's id: usually its prefix.
	 * @param array<string, mixed> $config {
	 *     @type string                    $label          The plugin's name: the badge, the checks while they run, the section.
	 *     @type string                    $badge          The badge's colour when not critical. `blue`.
	 *     @type string|array|callable     $actions        What a result that is not good offers: [ url, label ], markup,
	 *                                                     or a callable returning either.
	 *     @type array<string, mixed>      $tests          id => callable, or id => [ callback, label, async, enabled, skip_cron ].
	 *     @type array<string, mixed>|false $info          The section: label, description, fields (array or callable),
	 *                                                     version, requires [ php, wp ], tables (iterable or callable),
	 *                                                     libraries (an installed.json path), private, show_count,
	 *                                                     filter (`{id}_site_info`, or false).
	 *     @type string                    $rest_namespace Where async checks are asked. `{id}-site-health/v1`.
	 * }
	 *
	 * @return self
	 * @throws InvalidArgumentException When the id is empty.
	 * @since 1.0.0
	 */
	public static function register( string $id, array $config = [] ): self {
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Site Health needs an id.' );
		}

		self::unregister( $id );

		$set = new self( $id, $config );

		self::$sets[ $id ] = $set;

		add_filter( 'site_status_tests', [ $set, 'register_tests' ] );
		add_action( 'rest_api_init', [ $set, 'register_routes' ] );

		if ( null !== $set->info ) {
			add_filter( 'debug_information', [ $set, 'section' ] );
		}

		return $set;
	}

	/**
	 * A registered plugin.
	 *
	 * @param string $id The plugin's id.
	 *
	 * @return self|null
	 * @since 1.0.0
	 */
	public static function get( string $id ): ?self {
		return self::$sets[ $id ] ?? null;
	}

	/**
	 * Take a plugin out of Site Health.
	 *
	 * @param string $id The plugin's id.
	 *
	 * @return bool Whether it was in.
	 * @since 1.0.0
	 */
	public static function unregister( string $id ): bool {
		if ( ! isset( self::$sets[ $id ] ) ) {
			return false;
		}

		$set = self::$sets[ $id ];

		remove_filter( 'site_status_tests', [ $set, 'register_tests' ] );
		remove_action( 'rest_api_init', [ $set, 'register_routes' ] );
		remove_filter( 'debug_information', [ $set, 'section' ] );
		unset( self::$sets[ $id ] );

		return true;
	}

	/**
	 * Where the Info tab is.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function info_url(): string {
		return admin_url( 'site-health.php?tab=debug' );
	}

	/**
	 * Where the Status tab is.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function status_url(): string {
		return admin_url( 'site-health.php' );
	}

	/**
	 * Build the plugin's part.
	 *
	 * @param string               $id     The plugin's id.
	 * @param array<string, mixed> $config See register().
	 */
	private function __construct( string $id, array $config ) {
		$this->id      = $id;
		$this->label   = (string) ( $config['label'] ?? $id );
		$this->badge   = (string) ( $config['badge'] ?? 'blue' );
		$this->actions = $config['actions'] ?? null;
		$this->tests   = [];

		foreach ( (array) ( $config['tests'] ?? [] ) as $test => $definition ) {
			$this->tests[ (string) $test ] = is_callable( $definition ) ? [ 'callback' => $definition ] : (array) $definition;
		}

		$info       = $config['info'] ?? [];
		$this->info = false === $info ? null : (array) $info;

		$this->rest_namespace = (string) ( $config['rest_namespace'] ?? self::slug( $id ) . '-site-health/v1' );
	}

	/**
	 * The plugin's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * A check's full name: `{id}_{check}`.
	 *
	 * @param string $test The check's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function key( string $test ): string {
		return $this->id . '_' . $test;
	}

	/**
	 * A check's id without the plugin's prefix.
	 *
	 * @param string $test With or without it.
	 *
	 * @return string
	 */
	private function short( string $test ): string {
		$prefix = $this->id . '_';

		return str_starts_with( $test, $prefix ) && ! isset( $this->tests()[ $test ] ) ? substr( $test, strlen( $prefix ) ) : $test;
	}

	/**
	 * A REST namespace's first part: lowercase letters, digits and dashes.
	 *
	 * @param string $value Anything.
	 *
	 * @return string
	 */
	private static function slug( string $value ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
	}
}
