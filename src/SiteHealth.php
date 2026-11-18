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
use WP_Error;
use WP_REST_Request;

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
 */
final class SiteHealth {

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
	 * Every check, after add-ons have had theirs added: id => definition.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function tests(): array {
		/**
		 * Filter a plugin's Site Health checks.
		 *
		 * The way in for an add-on: a check added here wears the plugin's
		 * badge and offers the plugin's link.
		 *
		 * @param array<string, array<string, mixed>|callable> $tests id => [ callback, label, async, enabled, skip_cron ].
		 *
		 * @since 1.0.0
		 */
		$tests = (array) apply_filters( $this->id . '_site_health_tests', $this->tests );
		$found = [];

		foreach ( $tests as $test => $definition ) {
			$definition = is_callable( $definition ) ? [ 'callback' => $definition ] : $definition;

			if ( ! is_array( $definition ) || ! is_callable( $definition['callback'] ?? null ) ) {
				continue;
			}

			$enabled = $definition['enabled'] ?? true;
			$enabled = is_callable( $enabled ) ? (bool) call_user_func( $enabled ) : (bool) $enabled;

			if ( $enabled ) {
				$found[ (string) $test ] = $definition;
			}
		}

		return $found;
	}

	/**
	 * Add the plugin's checks to WordPress's list.
	 *
	 * Hooked to `site_status_tests`.
	 *
	 * @param mixed $tests WordPress's checks: direct and async.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function register_tests( $tests ): array {
		$tests = (array) $tests;

		foreach ( $this->tests() as $test => $definition ) {
			$key  = $this->key( $test );
			$run  = fn(): array => $this->run( $test );
			$item = [
				'label' => (string) ( $definition['label'] ?? $this->label ),
				'test'  => $run,
			];

			if ( ! empty( $definition['skip_cron'] ) ) {
				$item['skip_cron'] = true;
			}

			if ( empty( $definition['async'] ) ) {
				$tests['direct'][ $key ] = $item;

				continue;
			}

			$item['test']              = rest_url( $this->rest_namespace . '/tests/' . $test );
			$item['has_rest']          = true;
			$item['async_direct_test'] = $run;

			$tests['async'][ $key ] = $item;
		}

		return $tests;
	}

	/**
	 * Run one check, and dress its answer for Site Health.
	 *
	 * @param string $test The check's id, with or without the plugin's prefix.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function run( string $test ): array {
		$test       = $this->short( $test );
		$definition = $this->tests()[ $test ] ?? null;

		if ( null === $definition ) {
			return $this->dress(
				$test,
				Result::recommended(
					/* translators: %s: the check's name. */
					sprintf( __( 'The check %s is not available', 'sugarcommerce' ), $this->key( $test ) ),
					'',
					''
				)
			);
		}

		$result = call_user_func( $definition['callback'] );

		if ( ! is_array( $result ) ) {
			$result = Result::recommended(
				/* translators: %s: the plugin's name. */
				sprintf( __( 'One of %s’s checks gave no answer', 'sugarcommerce' ), $this->label ),
				'',
				''
			);
		}

		return $this->dress( $test, $result );
	}

	/**
	 * Add the async checks' REST route.
	 *
	 * Hooked to `rest_api_init`. Asked for with the same permission as
	 * WordPress's own async checks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		$async = array_filter( $this->tests, static fn( $definition ): bool => is_array( $definition ) && ! empty( $definition['async'] ) );

		if ( [] === $async && ! has_filter( $this->id . '_site_health_tests' ) ) {
			return;
		}

		register_rest_route(
			$this->rest_namespace,
			'/tests/(?P<test>[a-zA-Z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'respond' ],
				'permission_callback' => static fn(): bool => current_user_can( 'view_site_health_checks' ),
			]
		);
	}

	/**
	 * Answer an async check asked over REST.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.0.0
	 */
	public function respond( $request ): array|WP_Error {
		$test       = (string) $request['test'];
		$definition = $this->tests()[ $test ] ?? null;

		if ( null === $definition || empty( $definition['async'] ) ) {
			return new WP_Error( 'rest_not_found', __( 'There is no such check.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		return $this->run( $test );
	}

	/**
	 * Add the plugin's section to Site Health > Info.
	 *
	 * Hooked to `debug_information`.
	 *
	 * @param mixed $info The sections.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function section( $info ): array {
		$info = (array) $info;

		if ( null === $this->info ) {
			return $info;
		}

		$section = [
			'label'       => (string) ( $this->info['label'] ?? $this->label ),
			'description' => (string) ( $this->info['description'] ?? '' ),
			'fields'      => $this->fields(),
		];

		foreach ( [ 'private', 'show_count' ] as $flag ) {
			if ( ! empty( $this->info[ $flag ] ) ) {
				$section[ $flag ] = true;
			}
		}

		$info[ $this->id ] = $section;

		return $info;
	}

	/**
	 * Every line of the section: the version first, then the plugin's own,
	 * then the tables and the libraries, then whatever add-ons add.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function fields(): array {
		$info   = $this->info ?? [];
		$fields = [];

		if ( isset( $info['version'] ) ) {
			$fields['version'] = Info::version( (string) $info['version'] );
		}

		if ( isset( $info['requires'] ) ) {
			$requires               = (array) $info['requires'];
			$fields['requirements'] = Info::requirements( (string) ( $requires['php'] ?? '' ), (string) ( $requires['wp'] ?? '' ) );
		}

		$own    = $info['fields'] ?? [];
		$fields = array_merge( $fields, (array) ( is_callable( $own ) ? call_user_func( $own ) : $own ) );

		if ( isset( $info['tables'] ) ) {
			$tables = is_callable( $info['tables'] ) ? call_user_func( $info['tables'] ) : $info['tables'];
			$fields = array_merge( $fields, Info::tables( is_iterable( $tables ) ? $tables : [] ) );
		}

		if ( isset( $info['libraries'] ) ) {
			$fields = array_merge( $fields, Info::libraries( (string) $info['libraries'] ) );
		}

		$filter = $info['filter'] ?? '{id}_site_info';

		if ( false !== $filter ) {
			/**
			 * Filter a plugin's lines on Site Health > Info.
			 *
			 * Each line, keyed by a unique id, is `label`, `value` and
			 * optionally `debug` (the value in the copied text) and
			 * `private` (true to leave it out of the copied text). Never add
			 * a key, a password or a token: say whether it is set.
			 *
			 * @param array<string, array<string, mixed>> $fields The lines.
			 *
			 * @since 1.0.0
			 */
			$fields = (array) apply_filters( str_replace( '{id}', $this->id, (string) $filter ), $fields );
		}

		return $fields;
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
	 * A result in the shape Site Health wants.
	 *
	 * @param string               $test   The check's id.
	 * @param array<string, mixed> $result What the check answered.
	 *
	 * @return array<string, mixed>
	 */
	private function dress( string $test, array $result ): array {
		$status = in_array( $result['status'] ?? '', Result::STATUSES, true ) ? $result['status'] : 'recommended';
		$badge  = [
			'label' => $this->label,
			'color' => 'critical' === $status ? 'red' : $this->badge,
		];

		if ( array_key_exists( 'actions', $result ) ) {
			$actions = Result::actions( $result['actions'] );
		} elseif ( 'good' === $status ) {
			$actions = '';
		} else {
			$actions = Result::actions( is_callable( $this->actions ) ? call_user_func( $this->actions ) : $this->actions );
		}

		return [
			'label'       => (string) ( $result['label'] ?? '' ),
			'status'      => $status,
			'badge'       => array_merge( $badge, is_array( $result['badge'] ?? null ) ? $result['badge'] : [] ),
			'description' => Result::description( (string) ( $result['description'] ?? '' ) ),
			'actions'     => $actions,
			'test'        => $this->key( $test ),
		];
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
