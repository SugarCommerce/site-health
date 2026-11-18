<?php
/**
 * A Site Health result.
 *
 * @package   SugarCommerce\SiteHealth
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\SiteHealth;

/**
 * What a check answers, in the fewest words.
 *
 * A check says how it went, a headline and why. The badge, the test's
 * name and the "go and fix it" link are the same for every check a plugin
 * has, so SiteHealth adds them; a result only carries them when it wants
 * something other than the plugin's own.
 *
 *     return Result::critical(
 *         __( 'My Shop cannot reach Stripe', 'my-shop' ),
 *         __( 'No API key is set. Paste one into <code>Settings</code>.', 'my-shop' )
 *     );
 *
 * @since 1.0.0
 */
final class Result {

	/**
	 * The statuses Site Health knows, mildest first.
	 *
	 * @since 1.0.0
	 */
	public const STATUSES = [ 'good', 'recommended', 'critical' ];

	/**
	 * Everything is as it should be.
	 *
	 * @param string            $label       The headline.
	 * @param string            $description Why. Plain text with inline markup is put in a paragraph.
	 * @param string|array|null $actions     What to do, if not the plugin's own link. '' for nothing.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function good( string $label, string $description = '', string|array|null $actions = null ): array {
		return self::make( 'good', $label, $description, $actions );
	}

	/**
	 * Worth a look, not urgent.
	 *
	 * @param string            $label       The headline.
	 * @param string            $description Why.
	 * @param string|array|null $actions     What to do, if not the plugin's own link. '' for nothing.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function recommended( string $label, string $description = '', string|array|null $actions = null ): array {
		return self::make( 'recommended', $label, $description, $actions );
	}

	/**
	 * Something is broken, and it matters.
	 *
	 * @param string            $label       The headline.
	 * @param string            $description Why.
	 * @param string|array|null $actions     What to do, if not the plugin's own link. '' for nothing.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function critical( string $label, string $description = '', string|array|null $actions = null ): array {
		return self::make( 'critical', $label, $description, $actions );
	}

	/**
	 * An optional feature set up by several settings: all of them, or none.
	 *
	 * Half a set is the case worth catching -- a feature that is offered and
	 * then fails -- so it is recommended, naming what is missing. None set
	 * is the feature switched off, which is recommended too, saying how to
	 * switch it on, but never critical: the plugin works without it.
	 *
	 *     return Result::all_or_none(
	 *         __( 'Discord roles', 'my-shop' ),
	 *         [ __( 'Client ID', 'my-shop' ) => $client_id, __( 'Bot token', 'my-shop' ) => $token ],
	 *         __( 'Buyers are given their Discord role', 'my-shop' ),
	 *         __( 'Buyers are not given a Discord role.', 'my-shop' )
	 *     );
	 *
	 * @param string               $feature  What the feature is called.
	 * @param array<string, mixed> $settings Each setting's label => its value. '', null and false are not set.
	 * @param string               $on       The headline when it is all set up.
	 * @param string               $off      What is missed while it is not, as a sentence.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function all_or_none( string $feature, array $settings, string $on, string $off ): array {
		$missing = array_keys( array_filter( $settings, static fn( $value ): bool => '' === $value || null === $value || false === $value ) );

		if ( [] === $missing ) {
			return self::good( $on );
		}

		$names = static fn( array $labels ): string => implode( ', ', array_map( static fn( $label ): string => '<strong>' . esc_html( (string) $label ) . '</strong>', $labels ) );

		if ( count( $missing ) === count( $settings ) ) {
			return self::recommended(
				/* translators: %s: a feature's name. */
				sprintf( __( '%s is switched off', 'sugarcommerce' ), $feature ),
				/* translators: 1: what is missed while the feature is off, 2: a list of settings. */
				sprintf( __( '%1$s Set %2$s to switch it on.', 'sugarcommerce' ), esc_html( $off ), $names( array_keys( $settings ) ) )
			);
		}

		return self::recommended(
			/* translators: %s: a feature's name. */
			sprintf( __( '%s is only half set up', 'sugarcommerce' ), $feature ),
			/* translators: 1: a list of settings, 2: what is missed while the feature is off. */
			sprintf( __( 'Missing: %1$s. %2$s', 'sugarcommerce' ), $names( $missing ), esc_html( $off ) )
		);
	}

	/**
	 * A result.
	 *
	 * @param string            $status      good, recommended or critical.
	 * @param string            $label       The headline.
	 * @param string            $description Why.
	 * @param string|array|null $actions     What to do; null to leave it to the plugin.
	 *
	 * @return array<string, mixed>
	 */
	private static function make( string $status, string $label, string $description, string|array|null $actions ): array {
		$result = [
			'status'      => $status,
			'label'       => $label,
			'description' => $description,
		];

		if ( null !== $actions ) {
			$result['actions'] = $actions;
		}

		return $result;
	}

	/**
	 * A description as Site Health prints it.
	 *
	 * Plain text, with inline markup, is put in a paragraph. Markup that
	 * starts with a block -- a paragraph, a list -- is used as it is. Either
	 * way only the tags a description needs survive.
	 *
	 * @param string $description The description.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function description( string $description ): string {
		$description = trim( $description );

		if ( '' === $description ) {
			return '';
		}

		if ( ! preg_match( '/^<(p|ul|ol|pre)\b/i', $description ) ) {
			$description = '<p>' . $description . '</p>';
		}

		return wp_kses( $description, self::allowed() );
	}

	/**
	 * Actions as Site Health prints them.
	 *
	 * A link is `[ 'url' => …, 'label' => … ]`; a list of them is one
	 * paragraph each; a string is markup, kept to the tags an action needs.
	 *
	 * @param mixed $actions The actions.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function actions( mixed $actions ): string {
		if ( is_string( $actions ) ) {
			return wp_kses( $actions, self::allowed() );
		}

		if ( ! is_array( $actions ) ) {
			return '';
		}

		if ( isset( $actions['url'] ) ) {
			return self::link( $actions );
		}

		$links = array_filter( array_map( static fn( $action ): string => is_array( $action ) ? self::link( $action ) : '', $actions ) );

		return implode( '', array_map( static fn( string $link ): string => '<p>' . $link . '</p>', $links ) );
	}

	/**
	 * One link.
	 *
	 * @param array<string, mixed> $action url, label.
	 *
	 * @return string
	 */
	private static function link( array $action ): string {
		$url   = (string) ( $action['url'] ?? '' );
		$label = (string) ( $action['label'] ?? '' );

		if ( '' === $url || '' === $label ) {
			return '';
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * The tags a description or an action may carry.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed(): array {
		return [
			'p'      => [],
			'br'     => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'code'   => [],
			'pre'    => [],
			'strong' => [],
			'em'     => [],
			'span'   => [
				'class'       => true,
				'aria-hidden' => true,
			],
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			],
		];
	}
}
