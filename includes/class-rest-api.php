<?php
/**
 * REST API endpoint registration and request handling.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the three WebMCP Abilities REST endpoints:
 *   GET  /wp-json/webmcp/v1/tools
 *   POST /wp-json/webmcp/v1/execute/{ability-name}
 *   GET  /wp-json/webmcp/v1/nonce
 */
class REST_API {

	const NAMESPACE = 'webmcp/v1';

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	private Ability_Bridge $bridge;

	/**
	 * Rate limiter instance.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $rate_limiter;

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Ability_Bridge $bridge       Ability bridge.
	 * @param Rate_Limiter   $rate_limiter Rate limiter.
	 * @param Settings       $settings     Plugin settings.
	 */
	public function __construct(
		Ability_Bridge $bridge,
		Rate_Limiter $rate_limiter,
		Settings $settings
	) {
		$this->bridge       = $bridge;
		$this->rate_limiter = $rate_limiter;
		$this->settings     = $settings;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// Tool discovery endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/tools',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_tools' ],
				'permission_callback' => [ $this, 'tools_permission_check' ],
			]
		);

		// Tool execution endpoint.
		// The ability name may contain a namespace separator (e.g. webmcp%2Fget-categories).
		register_rest_route(
			self::NAMESPACE,
			'/execute/(?P<ability>[a-zA-Z0-9_%\-]+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute_tool' ],
				'permission_callback' => [ $this, 'execute_permission_check' ],
				'args'                => [
					'ability' => [
						'required'          => true,
						'sanitize_callback' => static function ( $value ) {
							return sanitize_text_field( rawurldecode( $value ) );
						},
					],
				],
			]
		);

		// The eye and the checkbox on the settings screen. Administrators only:
		// these change what every visitor is shown.
		$admin_only = [ $this, 'admin_permission_check' ];

		register_rest_route(
			self::NAMESPACE,
			'/abilities/visibility',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'toggle_visibility' ],
				'permission_callback' => $admin_only,
				'args'                => [
					'ability' => [
						'type'     => 'string',
						'required' => true,
					],
					'hide'    => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/abilities/anonymous',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'toggle_anonymous' ],
				'permission_callback' => $admin_only,
				'args'                => [
					'ability'   => [
						'type'     => 'string',
						'required' => true,
					],
					'anonymous' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);

		// Nonce refresh endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/nonce',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_nonce' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	// =========================================================================
	// GET /tools
	// =========================================================================

	/**
	 * Permission check for the tools discovery endpoint.
	 * Enforces authentication unless public discovery is enabled.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function tools_permission_check( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API signature.
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error( 'wmcp_disabled', __( 'WebMCP Abilities is not enabled.', 'webmcp-abilities' ), [ 'status' => 404 ] );
		}

		// Rate-limit discovery by IP.
		$ip = $this->get_client_ip();
		if ( ! $this->rate_limiter->check_discovery( $ip ) ) {
			return new \WP_Error(
				'wmcp_rate_limited',
				__( 'Too many requests. Please slow down.', 'webmcp-abilities' ),
				[ 'status' => 429 ]
			);
		}

		// If public discovery is on, anyone can list tools.
		if ( $this->settings->is_discovery_public() ) {
			return true;
		}

		// Otherwise require authentication.
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'wmcp_auth_required', __( 'Authentication required.', 'webmcp-abilities' ), [ 'status' => 401 ] );
		}

		return true;
	}

	/**
	 * Handle GET /tools — return all discoverable tools for the current user.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function get_tools( \WP_REST_Request $request ): \WP_REST_Response {
		$tools = $this->bridge->get_tools_for_current_user();
		$etag  = $this->bridge->compute_etag();

		// Support conditional requests.
		$if_none_match = $request->get_header( 'if_none_match' );
		if ( $if_none_match && trim( $if_none_match, '"' ) === $etag ) {
			return new \WP_REST_Response( null, 304 );
		}

		$response = new \WP_REST_Response(
			[
				'tools' => $tools,
				'nonce' => wp_create_nonce( 'wmcp_execute' ),
				// Core requires a 'wp_rest' nonce before it will honour the auth
				// cookie on a REST request — without one it zeroes the user.
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			],
			200
		);

		$response->header( 'Cache-Control', 'private, max-age=300' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'ETag', '"' . $etag . '"' );

		return $response;
	}

	// =========================================================================
	// POST /execute/{ability}
	// =========================================================================

	/**
	 * Permission check for the execute endpoint.
	 * Only gates on plugin-enabled; per-tool auth is handled in execute_tool()
	 * after the ability's own permission_callback is evaluated.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function execute_permission_check( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API signature.
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error( 'wmcp_disabled', __( 'WebMCP Abilities is not enabled.', 'webmcp-abilities' ), [ 'status' => 404 ] );
		}

		return true;
	}

	/**
	 * Handle POST /execute/{ability} — validate, rate-limit, and run the ability.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function execute_tool( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability_name = $request->get_param( 'ability' );
		$user_id      = get_current_user_id();

		// Input size check.
		$max_size = (int) apply_filters( 'wmcp_max_input_size', 100 * 1024 ); // 100 KB
		if ( strlen( $request->get_body() ) > $max_size ) {
			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_payload_too_large',
					'message' => __( 'Request payload exceeds the maximum allowed size.', 'webmcp-abilities' ),
				],
				400
			);
		}

		// Get registered abilities.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_abilities_unavailable',
					'message' => __( 'WordPress Abilities API is not available.', 'webmcp-abilities' ),
				],
				500
			);
		}

		if ( ! wp_has_ability( $ability_name ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_not_found',
					'message' => __( 'Tool not found.', 'webmcp-abilities' ),
				],
				404
			);
		}

		$ability = wp_get_ability( $ability_name );

		// A tool this caller is not shown is a tool this caller cannot run, so the
		// same visibility rules as discovery apply — and give the same 404, rather
		// than confirming the ability exists.
		$visibility = $this->bridge->resolve_visibility( $ability_name, $ability );

		if (
			Settings::VISIBILITY_PRIVATE === $visibility
			|| ( Settings::VISIBILITY_AUTHENTICATED === $visibility && ! is_user_logged_in() )
		) {
			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_not_found',
					'message' => __( 'Tool not found.', 'webmcp-abilities' ),
				],
				404
			);
		}

		// Parse input before permission check so callbacks can inspect it.
		$input = $request->get_json_params() ?? [];

		// Check the ability's own permission callback.
		$permission = $ability->check_permissions( $input );
		if ( true !== $permission ) {
			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_forbidden',
					'message' => __( 'You do not have permission to use this tool.', 'webmcp-abilities' ),
				],
				403
			);
		}

		// Nonce verification for logged-in users on write tools — prevents CSRF.
		// Read-only tools and unauthenticated requests skip this.
		$annotations  = $this->bridge->get_tool_annotations( $ability );
		$is_read_only = true === ( $annotations['readOnlyHint'] ?? false );
		if ( is_user_logged_in() && ! $is_read_only ) {
			// Sent as X-WMCP-Nonce: X-WP-Nonce belongs to core, which needs a
			// 'wp_rest' nonce there to accept the auth cookie at all. Older
			// scripts sent ours in X-WP-Nonce, so that is still accepted.
			$nonce = $request->get_header( 'x_wmcp_nonce' );
			if ( ! $nonce ) {
				$nonce = $request->get_header( 'x_wp_nonce' );
			}
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wmcp_execute' ) ) {
				return new \WP_REST_Response(
					[
						'code'    => 'wmcp_invalid_nonce',
						'message' => __( 'Invalid or expired security token.', 'webmcp-abilities' ),
					],
					403
				);
			}
		}

		/**
		 * Filter to block execution before it happens.
		 * Return a WP_Error to block; return true to allow.
		 *
		 * @param true|\WP_Error $allow
		 * @param string         $ability_name
		 * @param array          $input
		 * @param int            $user_id
		 */
		$allow = apply_filters( 'wmcp_allow_execution', true, $ability_name, $input, $user_id );
		if ( is_wp_error( $allow ) ) {
			return new \WP_REST_Response(
				[
					'code'    => $allow->get_error_code(),
					'message' => $allow->get_error_message(),
				],
				403
			);
		}

		// Rate limit check.
		if ( ! $this->rate_limiter->check_execution( $user_id, $ability_name ) ) {
			$response = new \WP_REST_Response(
				[
					'code'    => 'wmcp_rate_limited',
					'message' => __( 'Rate limit exceeded. Please wait before making more requests.', 'webmcp-abilities' ),
				],
				429
			);
			$response->header( 'Retry-After', '60' );
			return $response;
		}

		// Execute the ability.
		$result  = null;
		$success = false;

		try {
			$result = $ability->execute( $input );

			if ( is_wp_error( $result ) ) {
				// Log only the error code, not the message (may contain PII).
				do_action( 'wmcp_tool_executed', $ability_name, $user_id, false );

				return new \WP_REST_Response(
					[
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					],
					$result->get_error_data( 'status' ) ?? 500
				);
			}

			$success = true;
		} catch ( \Throwable $e ) {
			do_action( 'wmcp_tool_executed', $ability_name, $user_id, false );

			return new \WP_REST_Response(
				[
					'code'    => 'wmcp_execution_error',
					'message' => __( 'Tool execution failed.', 'webmcp-abilities' ),
				],
				500
			);
		}

		/**
		 * Fires after a tool is successfully executed.
		 * Receives only PII-safe data: no raw input or output.
		 *
		 * @param string $ability_name The ability that was executed.
		 * @param int    $user_id      The executing user's ID.
		 * @param bool   $success      Whether execution succeeded.
		 */
		do_action( 'wmcp_tool_executed', $ability_name, $user_id, $success );

		return new \WP_REST_Response( [ 'result' => $result ], 200 );
	}

	// =========================================================================
	// GET /nonce
	// =========================================================================

	/**
	 * Return a fresh nonce for the execution endpoint, plus a core 'wp_rest'
	 * nonce. Both are minted for the current user, so an unauthenticated
	 * request gets nonces that will not authenticate anyone.
	 *
	 * @param \WP_REST_Request $request Request object.
	 */
	public function get_nonce( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API signature.
		return new \WP_REST_Response(
			[
				'nonce'     => wp_create_nonce( 'wmcp_execute' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			],
			200
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get the client's IP address for rate limiting.
	 * Uses REMOTE_ADDR as the authoritative source; does not trust proxy headers
	 * to avoid IP spoofing.
	 */
	private function get_client_ip(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}

	// =========================================================================
	// POST /abilities/visibility and /abilities/anonymous
	// =========================================================================

	/**
	 * Permission check for the settings-screen toggles.
	 */
	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Resolve the ability a toggle request names, or an error.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_Ability|\WP_Error
	 */
	private function toggle_target( \WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'ability' );

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
			return new \WP_Error(
				'wmcp_unknown_ability',
				__( 'Unknown tool.', 'webmcp-abilities' ),
				[ 'status' => 404 ]
			);
		}

		$ability = wp_get_ability( $name );

		if ( $this->bridge->is_locked( $ability ) ) {
			return new \WP_Error(
				'wmcp_locked_ability',
				__( 'The plugin that registered this tool asked to keep it hidden.', 'webmcp-abilities' ),
				[ 'status' => 400 ]
			);
		}

		return $ability;
	}

	/**
	 * Hide an ability from every agent, or advertise it again.
	 *
	 * Showing an ability again restores what its own plugin asked for, or the
	 * default — the state it was hidden from is not remembered, so a tool never
	 * comes back more visible than a fresh one would be.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_visibility( \WP_REST_Request $request ) {
		$ability = $this->toggle_target( $request );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$name = (string) $request->get_param( 'ability' );

		if ( $request->get_param( 'hide' ) ) {
			$this->settings->set_visibility_override( $name, Settings::VISIBILITY_PRIVATE );
		} else {
			$this->settings->set_visibility_override(
				$name,
				$this->bridge->declared_visibility( $ability ) ?? Settings::DEFAULT_VISIBILITY
			);
		}

		return $this->toggle_response( $name, $ability );
	}

	/**
	 * Advertise an ability to logged-out visitors, or restrict it to signed-in ones.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_anonymous( \WP_REST_Request $request ) {
		$ability = $this->toggle_target( $request );

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$name = (string) $request->get_param( 'ability' );

		$this->settings->set_visibility_override(
			$name,
			$request->get_param( 'anonymous' ) ? Settings::VISIBILITY_PUBLIC : Settings::VISIBILITY_AUTHENTICATED
		);

		return $this->toggle_response( $name, $ability );
	}

	/**
	 * The row a toggle produced, plus the counts in the table's caption.
	 *
	 * @param string      $name    Ability identifier.
	 * @param \WP_Ability $ability Ability object.
	 */
	private function toggle_response( string $name, \WP_Ability $ability ): \WP_REST_Response {
		$this->bridge->invalidate_cache();

		$rows = $this->bridge->report();

		return new \WP_REST_Response(
			array_merge(
				$this->bridge->report_row( $name, $ability ),
				[
					'count_total'     => count( $rows ),
					'count_shown'     => count( array_filter( array_column( $rows, 'visible' ) ) ),
					'count_anonymous' => count( array_filter( array_column( $rows, 'anonymous' ) ) ),
				]
			)
		);
	}
}
