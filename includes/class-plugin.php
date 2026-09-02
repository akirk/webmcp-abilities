<?php
/**
 * Main plugin class.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires up all plugin components.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	public readonly Settings $settings;

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	public readonly Ability_Bridge $bridge;

	/**
	 * REST API handler instance.
	 *
	 * @var REST_API
	 */
	public readonly REST_API $rest;

	/**
	 * Built-in tools instance.
	 *
	 * @var Builtin_Tools
	 */
	public readonly Builtin_Tools $builtin_tools;

	/**
	 * Rate limiter instance.
	 *
	 * @var Rate_Limiter
	 */
	public readonly Rate_Limiter $rate_limiter;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings      = new Settings();
		$this->rate_limiter  = new Rate_Limiter();
		$this->bridge        = new Ability_Bridge( $this->settings );
		$this->builtin_tools = new Builtin_Tools();
		$this->rest          = new REST_API( $this->bridge, $this->rate_limiter, $this->settings );

		$this->register_hooks();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register plugin hooks.
	 */
	private function register_hooks(): void {
		// Register the WebMCP ability category, then built-in tools.
		// If the action has already fired (e.g. another plugin triggered the registry
		// before plugins_loaded finished), call immediately instead of waiting.
		if ( did_action( 'wp_abilities_api_categories_init' ) ) {
			$this->builtin_tools->register_category();
		} else {
			add_action( 'wp_abilities_api_categories_init', [ $this->builtin_tools, 'register_category' ] );
		}

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$this->builtin_tools->register();
		} else {
			add_action( 'wp_abilities_api_init', [ $this->builtin_tools, 'register' ] );
		}

		// Enqueue front-end JS.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );

		// Admin page.
		if ( is_admin() ) {
			$admin_page = new Admin_Page( $this->settings, $this->bridge );
			$admin_page->register();
		}

		// Cache invalidation when plugins activate/deactivate.
		add_action( 'activate_plugin', [ $this->bridge, 'invalidate_cache' ] );
		add_action( 'deactivate_plugin', [ $this->bridge, 'invalidate_cache' ] );

		// Cache invalidation when an admin changes which tools are exposed.
		// Both hooks are needed: the first save adds the option, later ones update it.
		add_action( 'add_option_' . Settings::OPTION_EXPOSED_TOOLS, [ $this->bridge, 'invalidate_cache' ] );
		add_action( 'update_option_' . Settings::OPTION_EXPOSED_TOOLS, [ $this->bridge, 'invalidate_cache' ] );
	}

	/**
	 * Enqueue the WebMCP Abilities front-end script.
	 */
	public function enqueue_frontend(): void {
		// Only load when enabled and on HTTPS.
		if ( ! $this->settings->is_enabled() || ! is_ssl() ) {
			return;
		}

		// Allow themes/plugins to suppress loading on specific pages.
		if ( ! apply_filters( 'wmcp_should_enqueue', true ) ) {
			return;
		}

		$asset_file = WMCP_PLUGIN_DIR . 'dist/webmcp-abilities.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WMCP_VERSION,
			];

		wp_enqueue_script(
			'webmcp-abilities',
			WMCP_PLUGIN_URL . 'dist/webmcp-abilities.js',
			$asset['dependencies'],
			$asset['version'],
			[ 'strategy' => 'defer' ]
		);

		// Pass configuration to the script.
		wp_localize_script(
			'webmcp-abilities',
			'wmcpBridge',
			[
				'toolsEndpoint'   => rest_url( 'webmcp/v1/tools' ),
				'executeEndpoint' => rest_url( 'webmcp/v1/execute/' ),
				'nonceEndpoint'   => rest_url( 'webmcp/v1/nonce' ),
				'nonce'           => wp_create_nonce( 'wmcp_execute' ),
				// Core discards the auth cookie on a REST request that carries
				// no 'wp_rest' nonce, so every request needs this one too.
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'debug'           => $this->is_debug(),
			]
		);
	}

	/**
	 * Whether the front-end script should log what it is doing to the console.
	 *
	 * Off for visitors by default. Follows WP_DEBUG, and can be forced either
	 * way with the filter — or per-browser, without touching the server, with
	 * localStorage.setItem( 'wmcp_debug', '1' ).
	 */
	private function is_debug(): bool {
		/**
		 * Filter whether the WebMCP front-end script logs to the browser console.
		 *
		 * @param bool $debug Defaults to WP_DEBUG.
		 */
		return (bool) apply_filters( 'wmcp_debug', defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
