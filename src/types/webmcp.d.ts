/**
 * WebMCP type declarations for navigator.modelContext and plugin globals.
 */

interface McpResult {
	content: Array< { type: string; text: string } >;
}

interface ToolAnnotations {
	readOnlyHint?: boolean;
	[ key: string ]: unknown;
}

interface McpTool {
	name: string;
	description: string;
	inputSchema: Record< string, unknown >;
	annotations?: ToolAnnotations;
	execute: ( input: Record< string, unknown > ) => Promise< McpResult >;
}

interface ProvideContextOptions {
	tools: McpTool[];
}

interface RegisterToolOptions {
	/** Aborting this signal unregisters the tool. */
	signal?: AbortSignal;
}

/**
 * Both shapes of the API are declared optional: registerTool() is the current
 * spec, provideContext() is what Chrome 146-149 shipped before it was removed
 * in March 2026. Call sites feature-detect rather than assume.
 */
interface ModelContext {
	registerTool?( tool: McpTool, options?: RegisterToolOptions ): Promise< void >;
	unregisterTool?( name: string ): void;
	provideContext?( context: ProvideContextOptions ): void;
}

interface WmcpBridgeConfig {
	toolsEndpoint: string;
	executeEndpoint: string;
	nonceEndpoint: string;
	/** CSRF token for the plugin's own execute endpoint, sent as X-WMCP-Nonce. */
	nonce: string;
	/** Core 'wp_rest' nonce, sent as X-WP-Nonce so WordPress honours the cookie. */
	restNonce?: string;
	/**
	 * Whether to log to the console. wp_localize_script() stringifies booleans,
	 * so this arrives as '1' or '' rather than true/false.
	 */
	debug?: string | boolean;
}

interface Navigator {
	/** Deprecated since Chrome 150 — kept as an alias for document.modelContext. */
	modelContext?: ModelContext;
}

interface Document {
	modelContext?: ModelContext;
}

// eslint-disable-next-line no-var
declare var wmcpBridge: WmcpBridgeConfig | undefined;
