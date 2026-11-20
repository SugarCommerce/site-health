<?php
/**
 * The plugin's section on the Site Health Info screen, and in the copied debug report.
 *
 * @package   SugarCommerce\SiteHealth
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth\Traits\SiteHealth;

use SugarCommerce\SiteHealth\Info;

/**
 * Trait InfoSection
 *
 * The plugin's section on the Site Health Info screen, and in the copied debug report.
 *
 * @since 1.0.0
 */
trait InfoSection {

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
}
