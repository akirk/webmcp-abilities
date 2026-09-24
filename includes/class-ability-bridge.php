<?php
/**
 * Bridges WordPress Abilities to WebMCP tool definitions.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Converts registered WordPress Abilities into WebMCP-compatible tool definitions,
 * applying visibility controls and permission filtering.
 */
class Ability_Bridge {

	/** Object cache group for the tools list. */
	const CACHE_GROUP = 'wmcp_bridge';

	/** Option holding the cache generation, bumped to invalidate the tools list. */
	const CACHE_VERSION_OPTION = 'wmcp_bridge_cache_version';

	/** Tool-definition shape version, bumped when cached output changes. */
	const CACHE_SCHEMA_VERSION = 2;

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Return WebMCP tool definitions for all abilities the current user
	 * is allowed to discover.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}>
	 */
	public function get_tools_for_current_user(): array {
		$user_id   = get_current_user_id();
		$cache_key = 'tools_' . $user_id . '_s' . self::CACHE_SCHEMA_VERSION . '_v' . $this->cache_version();

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$tools = $this->build_tools();

		wp_cache_set( $cache_key, $tools, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $tools;
	}

	/**
	 * Build the full list of tool definitions for the current user.
	 *
	 * @return array<int, array>
	 */
	private function build_tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$abilities = wp_get_abilities();
		$tools     = [];

		foreach ( $abilities as $name => $ability ) {
			$tool = $this->convert( $name, $ability );
			if ( null !== $tool ) {
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * The visibility an ability asks for itself, or null if it says nothing.
	 *
	 * `wmcp_visibility` is this plugin's own flag. `meta.mcp.public = false` is the
	 * MCP Adapter's opt-out, honoured here so an ability that has withdrawn from
	 * one bridge does not have to withdraw from the other separately.
	 *
	 * @param \WP_Ability $ability Ability object.
	 */
	public function declared_visibility( \WP_Ability $ability ): ?string {
		$declared = $ability->get_meta_item( 'wmcp_visibility', null );

		if ( is_string( $declared ) && in_array( $declared, Settings::VISIBILITIES, true ) ) {
			return $declared;
		}

		$mcp = $ability->get_meta_item( 'mcp', [] );

		if ( is_array( $mcp ) && isset( $mcp['public'] ) && false === $mcp['public'] ) {
			return Settings::VISIBILITY_PRIVATE;
		}

		return null;
	}

	/**
	 * Whether an ability has opted out in its own registration.
	 *
	 * An administrator can hide an ability the plugin was happy to advertise, but
	 * not advertise one the plugin asked to keep hidden.
	 *
	 * @param \WP_Ability $ability Ability object.
	 */
	public function is_locked( \WP_Ability $ability ): bool {
		return Settings::VISIBILITY_PRIVATE === $this->declared_visibility( $ability );
	}

	/**
	 * The visibility that actually applies to an ability.
	 *
	 * An ability that opted out stays out. Otherwise the administrator's decision
	 * wins, then the ability's own declaration, then the default.
	 *
	 * @param string      $name    Ability identifier.
	 * @param \WP_Ability $ability Ability object.
	 */
	public function resolve_visibility( string $name, \WP_Ability $ability ): string {
		$declared = $this->declared_visibility( $ability );

		if ( Settings::VISIBILITY_PRIVATE === $declared ) {
			$visibility = Settings::VISIBILITY_PRIVATE;
		} else {
			$visibility = $this->settings->get_visibility_override( $name )
				?? $declared
				?? Settings::DEFAULT_VISIBILITY;
		}

		/**
		 * Filter the visibility of a single ability.
		 *
		 * Has the final say over both the ability's own declaration and the
		 * administrator's choice. Return one of 'public', 'authenticated' or
		 * 'private'; anything else is ignored.
		 *
		 * @param string      $visibility Resolved visibility.
		 * @param string      $name       Ability name.
		 * @param \WP_Ability $ability    The ability object.
		 */
		$filtered = apply_filters( 'wmcp_tool_visibility', $visibility, $name, $ability );

		return in_array( $filtered, Settings::VISIBILITIES, true ) ? $filtered : $visibility;
	}

	/**
	 * Every registered ability with the verdict on it, for the settings screen.
	 *
	 * @return array<int, array{name:string,label:string,description:string,visibility:string,visible:bool,anonymous:bool,locked:bool,override:bool,reason:string}>
	 */
	public function report(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$rows = [];

		foreach ( wp_get_abilities() as $name => $ability ) {
			$rows[] = $this->report_row( $name, $ability );
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * One row of the report.
	 *
	 * @param string      $name    Ability identifier.
	 * @param \WP_Ability $ability Ability object.
	 * @return array<string, mixed>
	 */
	public function report_row( string $name, \WP_Ability $ability ): array {
		$declared   = $this->declared_visibility( $ability );
		$override   = $this->settings->get_visibility_override( $name );
		$locked     = Settings::VISIBILITY_PRIVATE === $declared;
		$visibility = $this->resolve_visibility( $name, $ability );

		return [
			'name'        => $name,
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'visibility'  => $visibility,
			'visible'     => Settings::VISIBILITY_PRIVATE !== $visibility,
			'anonymous'   => Settings::VISIBILITY_PUBLIC === $visibility,
			'locked'      => $locked,
			'override'    => ! $locked && null !== $override,
			'reason'      => $this->reason( $visibility, $declared, $override, $locked ),
		];
	}

	/**
	 * Why an ability ended up where it did, in one phrase.
	 *
	 * @param string      $visibility Resolved visibility.
	 * @param string|null $declared   What the ability asked for.
	 * @param string|null $override   What the administrator asked for.
	 * @param bool        $locked     Whether the ability opted out itself.
	 */
	private function reason( string $visibility, ?string $declared, ?string $override, bool $locked ): string {
		if ( $locked ) {
			return __( 'its own plugin asked to keep it hidden', 'webmcp-abilities' );
		}

		if ( null !== $override && $override === $visibility ) {
			return __( 'set here', 'webmcp-abilities' );
		}

		if ( null !== $override ) {
			return __( 'overridden in code', 'webmcp-abilities' );
		}

		if ( null !== $declared && $declared === $visibility ) {
			return __( 'its own plugin registered it this way', 'webmcp-abilities' );
		}

		if ( Settings::DEFAULT_VISIBILITY === $visibility ) {
			return __( 'the default', 'webmcp-abilities' );
		}

		return __( 'overridden in code', 'webmcp-abilities' );
	}

	/**
	 * Convert a single WP_Ability to a WebMCP tool definition.
	 * Returns null if the ability should not be exposed.
	 *
	 * @param string      $name    Ability identifier.
	 * @param \WP_Ability $ability Ability object.
	 * @return array|null
	 */
	public function convert( string $name, \WP_Ability $ability ): ?array {
		// 1. Resolve the ability's visibility and check it against this visitor.
		$visibility = $this->resolve_visibility( $name, $ability );

		if ( Settings::VISIBILITY_PRIVATE === $visibility ) {
			return null;
		}

		if ( Settings::VISIBILITY_AUTHENTICATED === $visibility && ! is_user_logged_in() ) {
			return null;
		}

		// 2. Check permission callback for the current user.
		$permission = $ability->check_permissions();
		if ( true !== $permission ) {
			return null;
		}

		// 3. Validate and sanitize the inputSchema.
		$input_schema = $this->validate_schema( $ability->get_input_schema() );

		// 4. Build the tool definition.
		$tool = [
			'name'        => $name,
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'inputSchema' => $input_schema,
		];

		// 5. Translate WordPress Ability annotations to WebMCP/MCP hint names.
		$annotations = $this->get_tool_annotations( $ability );
		if ( [] !== $annotations ) {
			$tool['annotations'] = $annotations;
		}

		/**
		 * Filter the WebMCP tool definition before it's sent to the browser.
		 * Use to customize description, add tool annotations, etc.
		 *
		 * @param array       $tool    The tool definition.
		 * @param string      $name    The ability name.
		 * @param \WP_Ability $ability The ability object.
		 */
		$tool = apply_filters( 'wmcp_tool_definition', $tool, $name, $ability );

		/**
		 * Filter whether an ability is exposed via WebMCP.
		 * Return false to hide a tool from discovery.
		 *
		 * @param bool        $expose  Whether to expose this ability.
		 * @param string      $name    Ability name.
		 * @param \WP_Ability $ability The ability object.
		 */
		if ( ! apply_filters( 'wmcp_expose_ability', true, $name, $ability ) ) {
			return null;
		}

		return $tool;
	}

	/**
	 * Translate an ability's standard annotations to WebMCP tool annotations.
	 *
	 * The legacy wmcp_read_only flag remains supported for abilities registered
	 * before WordPress introduced the annotations metadata object.
	 *
	 * @param \WP_Ability $ability Ability object.
	 * @return array<string, bool>
	 */
	public function get_tool_annotations( \WP_Ability $ability ): array {
		$source = $ability->get_meta_item( 'annotations', [] );
		$source = is_array( $source ) ? $source : [];
		$map    = [
			'readonly'   => 'readOnlyHint',
			'destructive' => 'destructiveHint',
			'idempotent'  => 'idempotentHint',
		];
		$result = [];

		foreach ( $map as $ability_key => $tool_key ) {
			if ( isset( $source[ $ability_key ] ) && is_bool( $source[ $ability_key ] ) ) {
				$result[ $tool_key ] = $source[ $ability_key ];
			}
		}

		if ( ! isset( $result['readOnlyHint'] ) && $ability->get_meta_item( 'wmcp_read_only', false ) ) {
			$result['readOnlyHint'] = true;
		}

		return $result;
	}

	/**
	 * Validate a JSON Schema object for use as a tool inputSchema.
	 * Rejects schemas with depth > 5 or unsupported $ref usage.
	 *
	 * @param array $schema Raw schema from ability definition.
	 * @return array Validated schema, or empty-object schema on failure.
	 */
	public function validate_schema( array $schema ): array {
		// Cast properties to stdClass so JSON encodes as {} not [].
		$empty = [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];

		if ( empty( $schema ) ) {
			return $empty;
		}

		if ( $this->schema_depth( $schema ) > 5 ) {
			return $empty;
		}

		if ( $this->schema_has_ref( $schema ) ) {
			return $empty;
		}

		return $this->fix_empty_properties( $schema );
	}

	/**
	 * Recursively cast any empty 'properties' arrays to stdClass so they
	 * serialize as JSON objects ({}) rather than arrays ([]).
	 * Gemini and other models reject [] as an invalid JSON Schema properties value.
	 *
	 * @param array $schema Schema to fix.
	 * @return array
	 */
	private function fix_empty_properties( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new \stdClass();
			} else {
				foreach ( $schema['properties'] as &$prop ) {
					if ( is_array( $prop ) ) {
						$prop = $this->fix_empty_properties( $prop );
					}
				}
				unset( $prop );
			}
		}

		// Also recurse into 'items' for array schemas.
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = $this->fix_empty_properties( $schema['items'] );
		}

		return $schema;
	}

	/**
	 * Compute the maximum nesting depth of an array/schema.
	 *
	 * @param array $schema Schema to inspect.
	 * @param int   $depth  Current depth (1-based at the top level).
	 */
	private function schema_depth( array $schema, int $depth = 1 ): int {
		$max = $depth;
		foreach ( $schema as $value ) {
			if ( is_array( $value ) ) {
				$child = $this->schema_depth( $value, $depth + 1 );
				$max   = max( $max, $child );
			}
		}
		return $max;
	}

	/**
	 * Check if a schema contains $ref keys (not supported).
	 *
	 * @param array $schema Schema to inspect.
	 */
	private function schema_has_ref( array $schema ): bool {
		if ( array_key_exists( '$ref', $schema ) ) {
			return true;
		}
		foreach ( $schema as $value ) {
			if ( is_array( $value ) && $this->schema_has_ref( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute an ETag for the current user's tool set.
	 * Used for HTTP caching on the /tools endpoint.
	 */
	public function compute_etag(): string {
		return md5( (string) wp_json_encode( $this->get_tools_for_current_user() ) );
	}

	/**
	 * The current cache generation, part of every tools cache key.
	 */
	private function cache_version(): int {
		return (int) get_option( self::CACHE_VERSION_OPTION, 1 );
	}

	/**
	 * Invalidate all cached tool lists.
	 * Called when plugins activate or deactivate, and when an admin changes
	 * which tools are exposed.
	 *
	 * Bumping the generation is what actually invalidates: not every persistent
	 * object cache drop-in supports flushing a group, and a stale tools list
	 * would otherwise survive for an hour. The group flush is still attempted
	 * where supported, so the superseded entries do not linger.
	 */
	public function invalidate_cache(): void {
		update_option( self::CACHE_VERSION_OPTION, $this->cache_version() + 1, false );

		if ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}
}
