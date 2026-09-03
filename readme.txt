=== WebMCP Abilities ===
Contributors: codeatlantic
Tags: ai, agents, webmcp, abilities, mcp
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridges WordPress Abilities to the WebMCP browser API, making your site's capabilities discoverable by AI agents in Chrome 146+.

== Description ==

**WebMCP Abilities** connects the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) to the [WebMCP browser standard](https://webmachinelearning.github.io/webmcp/), allowing AI agents running in Chrome 146+ to discover and invoke your site's registered capabilities as structured tools.

Already running in production on [wppopupmaker.com](https://wppopupmaker.com). The WordPress core team is exploring the same direction — see the [WebMCP adapter experiment](https://github.com/WordPress/ai/pull/224).

[Learn more on the product page](https://code-atlantic.com/products/webmcp-abilities-for-wordpress/).

[youtube https://youtu.be/7A34ZNz2bMM]

= How It Works =

When enabled, the plugin:

1. Registers a lightweight JavaScript bridge on your site's front end
2. Fetches all registered WordPress Abilities visible to the current user
3. Exposes them to the browser's AI agent via `document.modelContext.registerTool()`
4. Agents can then invoke tools, which execute server-side via a secure REST API

= Built-in Tools =

The plugin ships four starter tools that work immediately — no other plugins needed:

* **Search Posts** — Search published posts by keyword (public)
* **Get Post** — Retrieve a post by ID or slug (public)
* **Get Categories** — List all post categories (public)
* **Submit Comment** — Submit a comment on a post (respects WordPress comment settings)

= Ecosystem Fit =

* **Complements [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)** — which handles CLI and API agents via the MCP protocol. This plugin handles browser-based agents.
* **Complements [wmcp.dev](https://www.wmcp.dev/)** — which handles declarative form annotations. This plugin handles registered Abilities as imperative tools.

= Browser Support =

WebMCP requires Chrome 146 or higher. On other browsers, the plugin loads but silently does nothing — no errors.

= HTTPS Required =

The WebMCP standard requires a secure context. The front-end bridge will not load on HTTP sites. The plugin displays a warning in the admin if HTTPS is not detected.

= For Plugin Developers =

Any ability registered via `wp_register_ability()` automatically becomes a WebMCP tool. The site admin must enable it in **Settings → WebMCP** (third-party tools default to hidden on fresh installs).

`
// Register your category first (on the wp_abilities_api_categories_init hook).
add_action( 'wp_abilities_api_categories_init', function () {
    wp_register_ability_category( 'my-plugin', array(
        'label'       => 'My Plugin',
        'description' => 'Tools provided by My Plugin.',
    ) );
} );

// Then register abilities (on the wp_abilities_api_init hook).
add_action( 'wp_abilities_api_init', function () {
    wp_register_ability( 'my-plugin/my-action', array(
        'label'               => 'My Action',
        'description'         => 'Does something useful for agents.',
        'category'            => 'my-plugin',
        'input_schema'        => array( ... ),
        'execute_callback'    => function( $input ) { ... },
        'permission_callback' => function() { return current_user_can( 'read' ); },
        'meta'                => array( 'wmcp_visibility' => 'public' ),
    ) );
} );
`

**Important:** WordPress requires the category to be registered via `wp_register_ability_category()` before any ability can use it. Abilities with unregistered categories are silently dropped by WordPress core. Alternatively, you can use the `'webmcp'` category registered by this plugin.

Visibility options via the `meta` array:

* `'wmcp_visibility' => 'public'` — advertised to logged-out visitors too, if the site allows public discovery
* `'wmcp_visibility' => 'authenticated'` (default) — advertised only to signed-in users
* `'wmcp_visibility' => 'private'` — never advertised, and the admin cannot override it

An ability is advertised as soon as its plugin is active; there is no allowlist to tick. On the **Tools** tab of the settings page the admin can hide any ability with the eye, or tick its box to advertise it to logged-out visitors as well.

== Installation ==

1. Upload the `webmcp-abilities` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Go to **Settings → WebMCP** to enable and configure the plugin
4. Ensure your site is served over HTTPS

= Requirements =

* WordPress 6.9 or higher (requires the Abilities API)
* PHP 8.0 or higher
* Chrome 146+ on the visitor's browser for WebMCP to be active

== Frequently Asked Questions ==

= Do I need to configure anything? =

Just enable the plugin on the Settings → WebMCP page. Four built-in tools work immediately. Third-party abilities appear in the settings panel but are hidden by default — check the box next to each tool you want to expose.

= Is this safe? =

Yes. Every tool is advertised to signed-in users only unless it is explicitly marked public, and the admin can hide any tool outright. Each tool's own `permission_callback` enforces WordPress capabilities at execution time.

= Can anonymous visitors use tools? =

It depends on the tool. Public tools (like the built-in search and category tools) can be executed by anyone. Write tools and tools with custom permission callbacks may require authentication. A tool a visitor is not shown is also a tool that visitor cannot run.

= Does this work with the WordPress MCP Adapter? =

Yes — they are complementary. Built-in tools are registered as real WordPress Abilities, so they also appear via the MCP Adapter for CLI/API agents. The two plugins serve different transports (browser vs. API/CLI).

= What about the `.well-known/webmcp.json` manifest? =

This feature (which allows agents to discover tools before visiting the page) is planned for a future release. In the current version, agents discover tools when they load a page on your site.

== Screenshots ==

1. The WebMCP Abilities settings page — the Tools tab lists every registered ability with an eye to hide it and a checkbox to open it up to logged-out visitors; the Settings tab enables the bridge and controls tool discovery.

== Changelog ==

= 0.7.0 =
* Abilities registered by a plugin are advertised as soon as the plugin is active — no allowlist to tick
* `wmcp_visibility` is now three states: `public`, `authenticated` (the default) and `private`
* New: advertise a tool to signed-in users only, without hiding it entirely
* An ability that opted out with `meta.mcp.public = false` is honoured here too
* Settings page rebuilt with WordPress tabs and a table: an eye to hide a tool, a checkbox to open it to logged-out visitors
* Execution now applies the same visibility rules as discovery
* New `wmcp_tool_visibility` filter has the final say over both the ability and the admin
* Upgrades convert an existing exposed-tools list, so tools left unticked stay hidden

= 0.6.1 =
* Security: third-party abilities now default to hidden on fresh installs
* Only built-in tools (search, get post, categories, comments) are exposed by default
* Admins must explicitly enable new tools via Settings → WebMCP

= 0.6.0 =
* Renamed plugin from "WebMCP for WordPress" to "WebMCP Abilities for WordPress" for WordPress.org compliance
* Updated text domain, slugs, and all references

= 0.5.0 =
* TypeScript conversion: front-end bridge rewritten in TypeScript with full type safety
* Build pipeline: @wordpress/scripts v31.5 with webpack, output to dist/ with .asset.php manifests
* PHPCS compliance: CodeAtlantic coding standards, zero violations
* PHPDoc: comprehensive documentation across all PHP source and test files
* Cleanup: removed stale build artifacts

= 0.4.0 =
* Initial release
* Four built-in tools: wp/search-posts, wp/get-post, wp/get-categories, wp/submit-comment
* Per-tool visibility control via Settings
* Public discovery toggle
* Rate limiting: 30 executions/min per user, 100 discovery requests/min per IP
* Full WordPress Abilities API integration
* ETag-based client-side caching (24h TTL)

== Upgrade Notice ==

= 0.7.0 =
Abilities now advertise themselves instead of waiting to be enabled. Any tool you had already unticked stays hidden; everything else becomes visible to signed-in agents only, never to logged-out visitors unless you tick its box.

= 0.6.0 =
Plugin renamed to "WebMCP Abilities for WordPress". Please update any references in your code or configuration.

= 0.5.0 =
TypeScript rewrite, PHPCS compliance, improved build pipeline.

= 0.4.0 =
Initial release.
