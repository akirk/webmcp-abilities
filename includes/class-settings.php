<?php
/**
 * Settings management.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and retrieves plugin settings via the WordPress Settings API.
 */
class Settings {

	const OPTION_ENABLED          = 'wmcp_enabled';
	const OPTION_EXPOSED_TOOLS    = 'wmcp_exposed_tools';
	const OPTION_TOOL_VISIBILITY  = 'wmcp_tool_visibility';
	const OPTION_DISCOVERY_PUBLIC = 'wmcp_discovery_public';

	/** Set once the pre-0.7 allowlist has been converted into visibility overrides. */
	const OPTION_VISIBILITY_MIGRATED = 'wmcp_visibility_migrated';

	/** Advertised to anyone, subject to the site-wide public discovery setting. */
	const VISIBILITY_PUBLIC = 'public';

	/** Advertised only to signed-in users. */
	const VISIBILITY_AUTHENTICATED = 'authenticated';

	/** Never advertised. */
	const VISIBILITY_PRIVATE = 'private';

	/** The three states an ability can be in. */
	const VISIBILITIES = [
		self::VISIBILITY_PUBLIC,
		self::VISIBILITY_AUTHENTICATED,
		self::VISIBILITY_PRIVATE,
	];

	/**
	 * Where an ability lands when neither the plugin that registered it nor the
	 * administrator has said anything.
	 *
	 * Abilities activate themselves rather than waiting to be ticked, because an
	 * allowlist duplicates work the permission callbacks already do. They land on
	 * 'authenticated' rather than 'public' because being executable by a logged-out
	 * visitor is not the same as being worth advertising to one.
	 */
	const DEFAULT_VISIBILITY = self::VISIBILITY_AUTHENTICATED;

	/**
	 * Register settings with the WordPress Settings API.
	 * Called by Admin_Page on admin_init.
	 */
	public function register(): void {
		register_setting(
			'wmcp_settings_group',
			self::OPTION_ENABLED,
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);

		register_setting(
			'wmcp_settings_group',
			self::OPTION_DISCOVERY_PUBLIC,
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);
	}

	/**
	 * Whether WebMCP Abilities is globally enabled.
	 * Defaults to true on fresh installs — the plugin does nothing harmful
	 * when enabled and "install and it works" is the right first-run experience.
	 */
	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Whether public (unauthenticated) discovery is enabled.
	 */
	public function is_discovery_public(): bool {
		$public = (bool) get_option( self::OPTION_DISCOVERY_PUBLIC, false );

		/**
		 * Filter whether tool discovery requires authentication.
		 *
		 * When true, unauthenticated requests to the /tools endpoint return 401.
		 * Note: execution always requires authentication regardless of this setting.
		 *
		 * @param bool $require_auth Default true (authenticated required).
		 */
		$require_auth = apply_filters( 'wmcp_tools_require_auth', ! $public );

		return ! $require_auth;
	}

	/**
	 * Built-in tool names that were exposed by default before 0.7.
	 * Kept only so the one-off migration knows what a fresh pre-0.7 install meant.
	 */
	const DEFAULT_EXPOSED_TOOLS = [
		'wp/search-posts',
		'wp/get-post',
		'wp/get-categories',
		'wp/submit-comment',
	];

	/**
	 * The administrator's per-ability visibility overrides.
	 *
	 * Only abilities an administrator has actually decided about appear here;
	 * everything else falls back to what the ability itself declared, and then
	 * to DEFAULT_VISIBILITY.
	 *
	 * @return array<string, string> Ability name => one of self::VISIBILITIES.
	 */
	public function get_visibility_overrides(): array {
		$stored = get_option( self::OPTION_TOOL_VISIBILITY, [] );

		return $this->clean_visibility_map( $stored );
	}

	/**
	 * The administrator's override for one ability, or null if they have not
	 * expressed an opinion about it.
	 *
	 * @param string $tool_name Ability identifier.
	 */
	public function get_visibility_override( string $tool_name ): ?string {
		$overrides = $this->get_visibility_overrides();

		return $overrides[ $tool_name ] ?? null;
	}

	/**
	 * Record, or clear, the administrator's override for one ability.
	 *
	 * @param string      $tool_name  Ability identifier.
	 * @param string|null $visibility One of self::VISIBILITIES, or null to clear.
	 */
	public function set_visibility_override( string $tool_name, ?string $visibility ): void {
		$overrides = $this->get_visibility_overrides();

		if ( null === $visibility ) {
			unset( $overrides[ $tool_name ] );
		} elseif ( in_array( $visibility, self::VISIBILITIES, true ) ) {
			$overrides[ $tool_name ] = $visibility;
		} else {
			return;
		}

		update_option( self::OPTION_TOOL_VISIBILITY, $overrides, false );
	}

	/**
	 * Drop anything from a stored or submitted map that is not an ability name
	 * pointing at one of the three known states.
	 *
	 * @param mixed $value Raw map.
	 * @return array<string, string>
	 */
	private function clean_visibility_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $name => $visibility ) {
			if ( ! is_string( $name ) || ! is_string( $visibility ) ) {
				continue;
			}

			$name = sanitize_text_field( $name );

			if ( '' === $name || ! in_array( $visibility, self::VISIBILITIES, true ) ) {
				continue;
			}

			$clean[ $name ] = $visibility;
		}

		return $clean;
	}

	/**
	 * Convert a pre-0.7 exposed-tools allowlist into visibility overrides.
	 *
	 * Before 0.7 nothing was advertised until an administrator ticked it. Now
	 * abilities advertise themselves, so inverting the model without translating
	 * the old option would silently expose every tool an administrator had left
	 * unticked. Each such ability gets an explicit 'private' override instead, so
	 * the decision they already made survives the change.
	 *
	 * Best effort by nature: only abilities registered on the request this runs on
	 * can be marked, so an ability whose plugin loads later keeps the new default.
	 *
	 * @param string[] $registered Names of all currently registered abilities.
	 */
	public function migrate_exposed_tools( array $registered ): void {
		if ( get_option( self::OPTION_VISIBILITY_MIGRATED, false ) ) {
			return;
		}

		$exposed = get_option( self::OPTION_EXPOSED_TOOLS, null );

		// Never configured: nothing to preserve, the new defaults apply as-is.
		if ( null !== $exposed ) {
			$exposed   = is_array( $exposed ) ? $exposed : [];
			$overrides = $this->get_visibility_overrides();

			foreach ( $registered as $name ) {
				if ( isset( $overrides[ $name ] ) ) {
					continue;
				}

				$overrides[ $name ] = in_array( $name, $exposed, true )
					? self::VISIBILITY_PUBLIC
					: self::VISIBILITY_PRIVATE;
			}

			update_option( self::OPTION_TOOL_VISIBILITY, $overrides, false );
		}

		update_option( self::OPTION_VISIBILITY_MIGRATED, true, false );
	}
}
