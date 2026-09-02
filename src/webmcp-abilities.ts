/**
 * WebMCP Abilities — front-end imperative tool registration.
 *
 * Fetches registered WordPress Abilities from the REST API and registers
 * each one as a WebMCP tool via document.modelContext (falling back to the
 * deprecated navigator.modelContext alias), making them available to AI agents
 * in Chrome 146+.
 *
 * Every path that ends without registering tools logs why, but only when
 * debugging is switched on — see isDebugEnabled() below.
 */

interface ToolDefinition {
	name: string;
	description: string;
	inputSchema?: Record< string, unknown >;
	annotations?: ToolAnnotations;
}

interface ToolsCache {
	tools: ToolDefinition[];
	etag: string;
	expiry: number;
}

interface ToolsResponse {
	tools: ToolDefinition[];
	nonce?: string;
	restNonce?: string;
}

( function () {
	'use strict';

	const CACHE_KEY = 'wmcp_tools_cache';
	const CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours in ms
	const DEBUG_KEY = 'wmcp_debug';

	// -------------------------------------------------------------------------
	// Logging.
	//
	// Off by default — a plugin has no business writing to every visitor's
	// console. Turned on either server-side (WP_DEBUG, or the `wmcp_debug`
	// filter) or in the browser with:
	//
	//     localStorage.setItem( 'wmcp_debug', '1' )
	//
	// The browser switch is checked first so it works even when the config
	// object is missing entirely.
	// -------------------------------------------------------------------------

	function isDebugEnabled(): boolean {
		try {
			if ( localStorage.getItem( DEBUG_KEY ) === '1' ) {
				return true;
			}
		} catch {
			// localStorage unavailable (private browsing, blocked cookies).
		}
		// wp_localize_script() stringifies booleans: '1' when on, '' when off.
		return typeof wmcpBridge !== 'undefined' && !! wmcpBridge.debug;
	}

	const debug = isDebugEnabled();
	const PREFIX = '[WebMCP Abilities]';

	function log( ...args: unknown[] ): void {
		if ( debug ) {
			// eslint-disable-next-line no-console
			console.info( PREFIX, ...args );
		}
	}

	function warn( ...args: unknown[] ): void {
		if ( debug ) {
			// eslint-disable-next-line no-console
			console.warn( PREFIX, ...args );
		}
	}

	function error( ...args: unknown[] ): void {
		if ( debug ) {
			// eslint-disable-next-line no-console
			console.error( PREFIX, ...args );
		}
	}

	function detail( label: string, value: unknown ): void {
		if ( debug ) {
			// eslint-disable-next-line no-console
			console.debug( PREFIX, label, value );
		}
	}

	// -------------------------------------------------------------------------
	// Guards.
	// -------------------------------------------------------------------------

	// Guard: only run in browsers that support the WebMCP API.
	//
	// The API moved from navigator.modelContext to document.modelContext in the
	// May 2026 draft; Chrome 150 deprecated the navigator alias, which still
	// works but logs a deprecation warning on first access. Prefer document,
	// fall back to navigator for older Chrome.
	const modelContext =
		( typeof document !== 'undefined' && document.modelContext ) ||
		( typeof navigator !== 'undefined' && navigator.modelContext ) ||
		undefined;

	if ( ! modelContext ) {
		warn(
			'Neither document.modelContext nor navigator.modelContext is available — this browser does not support WebMCP. No tools registered.'
		);
		return;
	}

	log(
		typeof document !== 'undefined' && document.modelContext
			? 'Using document.modelContext.'
			: 'Using the deprecated navigator.modelContext alias.'
	);

	// Guard: wmcpBridge config must be present (injected via wp_localize_script).
	if ( typeof wmcpBridge === 'undefined' ) {
		warn(
			'wmcpBridge config is missing — the script loaded but wp_localize_script() data did not. No tools registered.'
		);
		return;
	}

	const { toolsEndpoint, executeEndpoint, nonceEndpoint } = wmcpBridge;
	let currentNonce: string = wmcpBridge.nonce;
	let restNonce: string = wmcpBridge.restNonce ?? '';

	log( 'Starting up.' );
	detail( 'config', {
		toolsEndpoint,
		executeEndpoint,
		nonceEndpoint,
		hasNonce: !! currentNonce,
		hasRestNonce: !! restNonce,
	} );

	// -------------------------------------------------------------------------
	// LocalStorage cache for tool definitions (24h TTL).
	// Nonces are NOT cached — they're returned fresh with each /tools response.
	// -------------------------------------------------------------------------

	function getCachedTools(): {
		tools: ToolDefinition[];
		etag: string;
	} | null {
		try {
			const raw = localStorage.getItem( CACHE_KEY );
			if ( ! raw ) {
				log( 'No cached tool list in localStorage.' );
				return null;
			}

			const { tools, etag, expiry } = JSON.parse( raw ) as ToolsCache;
			if ( Date.now() > expiry ) {
				log( 'Cached tool list expired — discarding it.' );
				localStorage.removeItem( CACHE_KEY );
				return null;
			}

			log(
				`Found ${
					tools.length
				} cached tool(s), expiring in ${ Math.round(
					( expiry - Date.now() ) / 60000
				) } min.`
			);
			detail( 'cached tools', {
				names: tools.map( ( t ) => t.name ),
				etag,
			} );
			return { tools, etag };
		} catch ( e ) {
			warn( 'Could not read the cached tool list:', e );
			return null;
		}
	}

	function setCachedTools( tools: ToolDefinition[], etag: string ): void {
		try {
			localStorage.setItem(
				CACHE_KEY,
				JSON.stringify( {
					tools,
					etag,
					expiry: Date.now() + CACHE_TTL,
				} )
			);
			log( `Cached ${ tools.length } tool(s) for 24h.` );
		} catch ( e ) {
			// localStorage may be unavailable (private browsing, quota exceeded).
			warn( 'Could not cache the tool list:', e );
		}
	}

	function clearCachedTools(): void {
		try {
			localStorage.removeItem( CACHE_KEY );
			log( 'Cleared the cached tool list.' );
		} catch {
			// Nothing to do — localStorage is unavailable.
		}
	}

	// -------------------------------------------------------------------------
	// Nonce refresh — called on 403 responses.
	// -------------------------------------------------------------------------

	async function refreshNonce(): Promise< void > {
		try {
			const response = await fetch( nonceEndpoint, {
				credentials: 'same-origin',
				headers: restNonce ? { 'X-WP-Nonce': restNonce } : {},
			} );
			if ( response.ok ) {
				const data = ( await response.json() ) as {
					nonce?: string;
					restNonce?: string;
				};
				currentNonce = data.nonce ?? currentNonce;
				restNonce = data.restNonce ?? restNonce;
				log( 'Refreshed the nonces.' );
			} else {
				warn(
					`Nonce refresh returned HTTP ${ response.status } — keeping the old nonce.`
				);
			}
		} catch ( e ) {
			// Silently fail — the next execution attempt will hit a 403 and retry.
			warn( 'Nonce refresh failed:', e );
		}
	}

	// -------------------------------------------------------------------------
	// Fetch tools from the REST API, respecting ETag for cache validation.
	// -------------------------------------------------------------------------

	async function fetchTools(): Promise< ToolDefinition[] > {
		const cached = getCachedTools();

		// X-WP-Nonce is not optional: rest_cookie_check_errors() calls
		// wp_set_current_user( 0 ) on any REST request that arrives without a
		// 'wp_rest' nonce, so cookie auth alone gets treated as logged out and
		// the endpoint answers 401. This must be the core nonce, not ours.
		const headers: Record< string, string > = {};

		if ( restNonce ) {
			headers[ 'X-WP-Nonce' ] = restNonce;
		} else {
			warn(
				'No wp_rest nonce available — the request will be treated as logged out.'
			);
		}

		if ( cached?.etag ) {
			headers[ 'If-None-Match' ] = `"${ cached.etag }"`;
		}

		log( `Requesting ${ toolsEndpoint }` );
		detail( 'request headers', headers );

		let response: Response;
		try {
			response = await fetch( toolsEndpoint, {
				headers,
				credentials: 'same-origin',
			} );
		} catch ( e ) {
			// Network error — use cached tools if available.
			error(
				`Could not reach the tools endpoint — falling back to ${
					cached?.tools.length ?? 0
				} cached tool(s).`,
				e
			);
			return cached?.tools ?? [];
		}

		log( `Tools endpoint responded HTTP ${ response.status }.` );

		// 304 Not Modified — cached tools are still valid.
		if ( response.status === 304 && cached?.tools ) {
			log(
				`Server reports the list is unchanged — using ${ cached.tools.length } cached tool(s).`
			);
			return cached.tools;
		}

		if ( ! response.ok ) {
			if ( response.status === 403 ) {
				warn(
					'Tool discovery was rejected (HTTP 403). The page’s security token has probably expired — reload the page. If the page is served from a cache, the token it carries may be older than 24h.'
				);
			} else if ( response.status === 401 ) {
				warn(
					'Not authenticated for tool discovery (HTTP 401). You are browsing logged out, or the security token was not accepted. Log in to this site, or enable public discovery under Settings → WebMCP. Only tools your user is permitted to see are ever returned.'
				);
			} else {
				error(
					`Tool discovery failed with HTTP ${ response.status }.`
				);
			}

			if ( cached?.tools.length ) {
				warn(
					`Falling back to ${ cached.tools.length } cached tool(s) from an earlier visit — this list may be stale. Run localStorage.removeItem( '${ CACHE_KEY }' ) and reload to drop it.`
				);
			}
			return cached?.tools ?? [];
		}

		const data = ( await response.json() ) as ToolsResponse;
		const tools = data.tools ?? [];
		const etag = response.headers.get( 'ETag' )?.replace( /"/g, '' ) ?? '';

		log( `Server returned ${ tools.length } tool(s).` );
		detail( 'tools from server', {
			names: tools.map( ( t ) => t.name ),
			etag,
		} );

		if ( tools.length === 0 ) {
			warn(
				'The server returned an empty tool list. Check that tools are ticked under Settings → WebMCP, and that your user passes each ability’s permission check.'
			);
		}

		// Update the nonces from the response. Core also sends a refreshed
		// X-WP-Nonce header whenever it accepted the one we sent.
		if ( data.nonce ) {
			currentNonce = data.nonce;
		}
		restNonce =
			response.headers.get( 'X-WP-Nonce' ) ?? data.restNonce ?? restNonce;

		setCachedTools( tools, etag );
		return tools;
	}

	// -------------------------------------------------------------------------
	// Execute a tool via the REST API.
	// Auto-retries once on 403 (expired nonce).
	// -------------------------------------------------------------------------

	async function executeTool(
		toolName: string,
		input: Record< string, unknown >,
		readOnly = false
	): Promise< McpResult > {
		const url = executeEndpoint + encodeURIComponent( toolName );

		log( `Executing "${ toolName }"${ readOnly ? ' (read-only)' : '' }.` );
		detail( 'input', input );

		async function doRequest( nonce: string ): Promise< Response > {
			const reqHeaders: Record< string, string > = {
				'Content-Type': 'application/json',
			};
			// X-WP-Nonce carries core's 'wp_rest' nonce, without which WordPress
			// treats the request as logged out. Our own CSRF token travels
			// separately in X-WMCP-Nonce, and only write tools need it.
			if ( restNonce ) {
				reqHeaders[ 'X-WP-Nonce' ] = restNonce;
			}
			if ( ! readOnly && nonce ) {
				reqHeaders[ 'X-WMCP-Nonce' ] = nonce;
			}
			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: reqHeaders,
				body: JSON.stringify( input ),
			} );
		}

		let response = await doRequest( currentNonce );

		// Refresh and retry once on 403: either nonce may have expired, and a
		// stale wp_rest nonce fails read-only calls just as readily.
		if ( response.status === 403 ) {
			warn(
				`"${ toolName }" returned HTTP 403 — refreshing the nonces and retrying once.`
			);
			await refreshNonce();
			response = await doRequest( currentNonce );
		}

		if ( ! response.ok ) {
			let errorMessage = `HTTP ${ response.status }`;
			try {
				const errData = ( await response.json() ) as {
					message?: string;
				};
				errorMessage = errData.message ?? errorMessage;
			} catch {
				/* non-JSON body — use status code */
			}
			error( `"${ toolName }" failed: ${ errorMessage }` );
			throw new Error( `WebMCP Abilities: ${ errorMessage }` );
		}

		const data = ( await response.json() ) as { result: unknown };

		log( `"${ toolName }" succeeded.` );
		detail( 'result', data.result );

		// Chrome's WebMCP implementation expects the MCP content-array format.
		return {
			content: [ { type: 'text', text: JSON.stringify( data.result ) } ],
		};
	}

	// -------------------------------------------------------------------------
	// Register all tools with the model context.
	// -------------------------------------------------------------------------

	async function registerTools(): Promise< void > {
		let tools: ToolDefinition[];
		try {
			tools = await fetchTools();
		} catch ( e ) {
			error( 'Could not load the tool list:', e );
			return;
		}

		if ( ! Array.isArray( tools ) || tools.length === 0 ) {
			warn( 'No tools to register.' );
			return;
		}

		// Build the tool list in the format the spec requires.
		const usable = tools.filter(
			( tool ) => tool.name && tool.description
		);

		if ( usable.length !== tools.length ) {
			warn(
				`Skipped ${
					tools.length - usable.length
				} tool(s) missing a name or description.`
			);
			detail(
				'skipped tools',
				tools.filter( ( tool ) => ! tool.name || ! tool.description )
			);
		}

		const mcpTools: McpTool[] = usable.map( ( tool ) => {
			// Gemini rejects tool names containing '/'. Sanitize for WebMCP
			// registration while keeping the original name for the execute URL.
			const safeName = tool.name.replace( /\//g, '_' );

			const entry: McpTool = {
				name: safeName,
				description: tool.description,
				inputSchema: tool.inputSchema ?? {
					type: 'object',
					properties: {},
				},
				execute: async (
					input: Record< string, unknown >
				): Promise< McpResult > =>
					executeTool(
						tool.name,
						input,
						!! tool.annotations?.readOnlyHint
					),
			};
			if ( tool.annotations ) {
				entry.annotations = tool.annotations;
			}
			return entry;
		} );

		if ( mcpTools.length === 0 ) {
			warn( 'Every tool was filtered out — nothing registered.' );
			return;
		}

		await publishTools( mcpTools );
	}

	// -------------------------------------------------------------------------
	// Hand the tools to the browser.
	//
	// registerTool() is the current spec: one call per tool, unregistered by
	// aborting the signal it was given. provideContext() was the older whole-set
	// call, removed from the spec in March 2026 but still what Chrome 146-149
	// implements — so support whichever this browser actually has.
	// -------------------------------------------------------------------------

	let registration: AbortController | null = null;

	async function publishTools( mcpTools: McpTool[] ): Promise< void > {
		if ( typeof modelContext!.registerTool === 'function' ) {
			// Aborting the previous batch unregisters it, so a re-run replaces
			// the set rather than colliding on duplicate names.
			registration?.abort();
			registration = new AbortController();

			const registered: string[] = [];
			for ( const tool of mcpTools ) {
				try {
					await modelContext!.registerTool!( tool, {
						signal: registration.signal,
					} );
					registered.push( tool.name );
				} catch ( e ) {
					error( `Could not register "${ tool.name }":`, e );
				}
			}

			log(
				`Registered ${ registered.length } of ${ mcpTools.length } tool(s) via registerTool():`,
				registered.join( ', ' ) || '(none)'
			);
			return;
		}

		if ( typeof modelContext!.provideContext === 'function' ) {
			// provideContext() atomically replaces the full tool set.
			modelContext!.provideContext( { tools: mcpTools } );
			log(
				`Registered ${ mcpTools.length } tool(s) via the older provideContext():`,
				mcpTools.map( ( t ) => t.name ).join( ', ' )
			);
			return;
		}

		error(
			'This browser exposes modelContext but neither registerTool() nor provideContext() — nothing registered.'
		);
	}

	// -------------------------------------------------------------------------
	// Entry point.
	// -------------------------------------------------------------------------

	if ( debug ) {
		// A console handle for working out why a list looks wrong. Only defined
		// while debugging, so it is not part of the plugin's public surface.
		( window as unknown as Record< string, unknown > ).wmcpDebug = {
			clearCache: clearCachedTools,
			reload: registerTools,
			getCached: getCachedTools,
		};
		log(
			'Debug logging is on. window.wmcpDebug provides clearCache(), reload() and getCached().'
		);
	}

	registerTools();
} )();
