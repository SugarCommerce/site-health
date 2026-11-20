<?php
/**
 * The plugin's checks on the Site Health status screen: added to core's list, run, and answered over REST when async.
 *
 * @package   SugarCommerce\SiteHealth
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth\Traits\SiteHealth;

use SugarCommerce\SiteHealth\Result;
use WP_Error;
use WP_REST_Request;

/**
 * Trait Checks
 *
 * The plugin's checks on the Site Health status screen: added to core's list, run, and answered over REST when async.
 *
 * An async check is answered by this plugin's own REST route, asked with
 * the same permission as WordPress's own async checks.
 *
 * @since 1.0.0
 */
trait Checks {

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
				'args'                => [
					'test' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-zA-Z0-9_-]+$',
					],
				],
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
}
