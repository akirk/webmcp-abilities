# WebMCP Abilities for WordPress

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Chrome](https://img.shields.io/badge/Chrome-146%2B-4285F4?logo=googlechrome&logoColor=white)](https://developer.chrome.com/blog/webmcp-epp)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-51%20passing-brightgreen)](#testing)
[![WebMCP Spec](https://img.shields.io/badge/spec-WebMCP%20W3C-orange)](https://webmachinelearning.github.io/webmcp/)

**Turn any WordPress site into a structured tool server for AI agents** — no custom API, no scraping, no prompt engineering required.

> Already running in production on [wppopupmaker.com](https://wppopupmaker.com). See the [WordPress core WebMCP experiment](https://github.com/WordPress/ai/pull/224) for where this is heading.

**[Product Page](https://code-atlantic.com/products/webmcp-abilities-for-wordpress/)** · **[GitHub](https://github.com/code-atlantic/webmcp-abilities)** · **[WordPress.org](https://wordpress.org/plugins/webmcp-abilities/)** *(pending review)*

WebMCP Abilities connects the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) to the [WebMCP browser standard](https://webmachinelearning.github.io/webmcp/), so AI agents running in Chrome 146+ can discover and call your site's capabilities as reliable, schema-driven tools.

### Demo

[![WebMCP Abilities Demo](https://img.youtube.com/vi/7A34ZNz2bMM/maxresdefault.jpg)](https://youtu.be/7A34ZNz2bMM)

> Gemini 2.5 Flash discovering and calling WordPress tools via Chrome's `document.modelContext` API on a live production site.

---

## What Is WebMCP?

[WebMCP](https://webmachinelearning.github.io/webmcp/) is a browser API (`document.modelContext`) that lets websites register structured tools directly discoverable by AI agents. Instead of agents clicking through UIs, taking screenshots, and guessing at intent, they get:

- **Structured tool definitions** with JSON Schema inputs
- **Direct execution** via `document.modelContext.registerTool()`
- **Security enforced by the browser** — same-origin, HTTPS-only
- **~98% task accuracy** vs ~45% for vision-based approaches

> Currently in Early Preview — enable at `chrome://flags` → **WebMCP for testing** in Chrome 146+.

**References:**
- [WebMCP W3C Specification](https://webmachinelearning.github.io/webmcp/)
- [Google Chrome Blog: WebMCP Early Preview](https://developer.chrome.com/blog/webmcp-epp)
- [GitHub: webmachinelearning/webmcp](https://github.com/webmachinelearning/webmcp)

---

## What This Plugin Does

```
WordPress Site                          AI Agent (Claude, ChatGPT, etc.)
─────────────────                       ──────────────────────────────────
┌──────────────────────┐                ┌────────────────────────────────┐
│  WP Abilities API    │                │  Chrome 146+ browser           │
│  (register tools     │──── bridge ───▶│  document.modelContext         │
│   with schema +      │                │  .registerTool(...)            │
│   permissions)       │                └────────────────────────────────┘
└──────────────────────┘                          │
                                                  │ tool call
                                                  ▼
                                        ┌────────────────────────────────┐
                                        │  POST /wp-json/webmcp/v1/      │
                                        │  execute/{ability}             │
                                        │  with nonce + auth             │
                                        └────────────────────────────────┘
```

1. **Plugins register WordPress Abilities** — structured capabilities with labels, descriptions, JSON Schema inputs, permission callbacks, and execute callbacks.
2. **This plugin bridges them to WebMCP** — the bridge script calls `document.modelContext.registerTool()` for each exposed ability. It loads on the front end and on wp-admin screens, where a logged-in user has the capabilities most abilities check for.
3. **AI agents discover and call tools** — structured JSON in, structured JSON out. No DOM parsing. No screenshots.

---

## Built-in Tools

Four starter tools are included out of the box:

| Tool | Description | Auth |
|------|-------------|------|
| `wp/search-posts` | Full-text search across published posts | Public |
| `wp/get-post` | Retrieve a post by ID or slug with full content | Public |
| `wp/get-categories` | List all categories with counts and descriptions | Public |
| `wp/submit-comment` | Submit a comment (respects WP comment settings) | Configurable |

Disable all built-ins with one filter:
```php
add_filter( 'wmcp_include_builtin_tools', '__return_false' );
```

---

## Installation

### Requirements

- WordPress **6.9+** (requires the Abilities API)
- PHP **8.0+**
- **HTTPS** (WebMCP is a secure context API)
- Chrome **146+** with WebMCP flag enabled (for AI agents)

### From Source

```bash
git clone https://github.com/code-atlantic/webmcp-abilities.git
cd webmcp-abilities
composer install --no-dev
```

Upload to `wp-content/plugins/webmcp-abilities/` and activate.

---

## Registering Custom Tools

Any plugin can expose tools to AI agents by registering WordPress Abilities. First register your category, then your abilities:

```php
// 1. Register your category.
add_action( 'wp_abilities_api_categories_init', function () {
    wp_register_ability_category( 'my-plugin', array(
        'label'       => __( 'My Plugin', 'my-plugin' ),
        'description' => __( 'Tools provided by My Plugin.', 'my-plugin' ),
    ) );
} );

// 2. Register abilities that use the category.
add_action( 'wp_abilities_api_init', function () {
    wp_register_ability(
        'my-plugin/get-products',
        array(
            'label'               => __( 'Get Products', 'my-plugin' ),
            'description'         => __( 'Search the product catalog by keyword.', 'my-plugin' ),
            'category'            => 'my-plugin',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'description' => 'Search term' ),
                    'limit' => array( 'type' => 'integer', 'default' => 10 ),
                ),
                'required'   => array( 'query' ),
            ),
            'execute_callback'    => 'my_plugin_get_products',
            'permission_callback' => '__return_true',
            'meta'                => array( 'wmcp_visibility' => 'public' ),
        )
    );
} );
```

> **Important:** WordPress requires the category to be registered via `wp_register_ability_category()` before any ability can use it. Abilities with unregistered categories are silently dropped by core. You can also use the `'webmcp'` category registered by this plugin.

WebMCP Abilities picks up any registered ability automatically: a plugin's tools are
advertised to signed-in agents as soon as the plugin is active, with no trip to the
settings page. There is no allowlist to tick, because an allowlist duplicates work the
abilities' own `permission_callback`s already do — an agent is only ever shown a tool
whose permission callback passes for the visitor behind it.

### Visibility Control

Three states, settable by the plugin that registers the ability and overridable per
ability by the site administrator on the **Tools** tab of **Settings → WebMCP** — an eye
to hide a tool or show it again, and a checkbox to advertise it to logged-out visitors as
well. Both apply as you click them; a hidden tool's checkbox is disabled, because a tool
nobody is shown reaches nobody:

```php
// Anyone: advertised to logged-out visitors too, if the site allows public discovery
'meta' => array( 'wmcp_visibility' => 'public' )

// Signed-in users only: the default when nothing is declared
'meta' => array( 'wmcp_visibility' => 'authenticated' )

// Nobody: never advertised, and the administrator cannot override this
'meta' => array( 'wmcp_visibility' => 'private' )
```

`authenticated` is the default because being *executable* by a logged-out visitor is not
the same as being worth *advertising* to one: an ability with
`'permission_callback' => '__return_true'` is fine to run anonymously but need not appear
in every passing agent's tool list.

The two levels compose. Site-wide, **Tool Discovery** decides whether logged-out visitors
see anything at all; per ability, `public` decides whether this particular tool is part of
what they see. An ability marked `public` on a site with public discovery off stays
invisible to them.

An ability that opted out of the [MCP Adapter](https://github.com/WordPress/mcp-adapter)
with `meta.mcp.public = false` is treated as `private` here too, so a tool only has to
withdraw once.

Both the resolved state and the administrator's choice can be overridden in code:

```php
add_filter( 'wmcp_tool_visibility', function ( $visibility, $name, $ability ) {
	return 'my-plugin/danger' === $name ? 'private' : $visibility;
}, 10, 3 );
```

---

## REST API Endpoints

The plugin registers three endpoints under `/wp-json/webmcp/v1/`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/tools` | List all tools visible to the current user |
| `POST` | `/execute/{ability}` | Run a tool (nonce required for write tools) |
| `GET` | `/nonce` | Refresh the execution nonce |

The `/tools` endpoint supports:
- **Conditional requests** (`If-None-Match` / `ETag`) for efficient polling
- **`Cache-Control: private, max-age=300`** to reduce load
- **Public discovery mode** (opt-in) for unauthenticated tool listing

---

## Admin Settings

**Settings → WebMCP** provides:

- **Enable/disable** the bridge globally
- **Public tool discovery** — allow unauthenticated agents to list tools (execution still requires auth)
- **Per-tool toggle** — hide specific tools from discovery without unregistering them
- **Status panel** — HTTPS check, Abilities API availability, registered count

---

## Hooks & Filters

```php
// Allow/block a tool from appearing at all
add_filter( 'wmcp_expose_ability', function ( $expose, $name, $ability ) {
    return $name !== 'wp/submit-comment'; // hide comment tool
}, 10, 3 );

// Customize the tool definition before it's sent to the browser
add_filter( 'wmcp_tool_definition', function ( $tool, $name, $ability ) {
    $tool['description'] .= ' Powered by Acme Corp.';
    return $tool;
}, 10, 3 );

// Block execution with context (user ID, input)
add_filter( 'wmcp_allow_execution', function ( $allow, $name, $input, $user_id ) {
    if ( $name === 'my-plugin/delete-data' && ! is_vip_user( $user_id ) ) {
        return new WP_Error( 'forbidden', 'VIP only.' );
    }
    return $allow;
}, 10, 4 );

// Adjust rate limits
add_filter( 'wmcp_rate_limit', fn() => 30 );          // requests per minute per user/tool
add_filter( 'wmcp_rate_limit_window', fn() => 60 );   // window in seconds

// Disable built-in tools
add_filter( 'wmcp_include_builtin_tools', '__return_false' );

// Conditionally load the bridge script. It loads on the front end and in
// wp-admin; $context is 'front' or 'admin'.
add_filter( 'wmcp_should_enqueue', fn( $enqueue, $context ) => 'admin' !== $context, 10, 2 );
```

---

## Security

- **HTTPS enforced** — bridge script does not load over HTTP
- **Nonce verification** on write tool execute requests (`X-WP-Nonce` header) — read-only tools skip this
- **Permission callbacks** re-evaluated at execution time (not just discovery)
- **Private visibility** flag prevents internal abilities from appearing
- **Per-ability visibility** — every tool is public, signed-in only, or hidden; the site owner has the last word, except over an ability that withdrew itself
- **Rate limiting** per user+tool pair plus global IP-based discovery limit
- **Input size cap** — 100 KB max payload (filterable)
- **Schema validation** — depth limit and `$ref` rejection to prevent injection
- **IP-based rate limiting** on tool discovery (REMOTE_ADDR only, no proxy header trust)

---

## Testing

51 PHPUnit integration tests run against a real WordPress 6.9 environment via [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

```bash
# Start the test environment
npx wp-env start

# Run the suite
npx wp-env run tests-cli \
  "bash -c 'cd /var/www/html/wp-content/plugins/webmcp-abilities && WP_TESTS_DIR=/wordpress-phpunit ./vendor/bin/phpunit'"
```

Test coverage:
- `Ability_Bridge` — visibility filtering, permission checks, schema validation, filters
- `Builtin_Tools` — all four tools including edge cases and sanitization
- `REST_API` — all endpoints: auth, nonce, rate limiting, execution lifecycle
- `Rate_Limiter` — per-user and global ceiling enforcement

---

## Architecture

```
webmcp-abilities/
├── webmcp-abilities.php          # Bootstrap, version guard
├── includes/
│   ├── class-plugin.php       # Singleton wiring
│   ├── class-settings.php     # Options: enabled, discovery, per-tool visibility
│   ├── class-ability-bridge.php  # WP_Ability → WebMCP tool definition
│   ├── class-builtin-tools.php   # 4 starter abilities
│   ├── class-rest-api.php     # /tools, /execute, /nonce endpoints
│   ├── class-rate-limiter.php # Transient-based rate limiting
│   └── class-admin-page.php   # Settings UI
├── src/
│   ├── webmcp-abilities.ts       # TypeScript source (document.modelContext bridge)
│   └── types/webmcp.d.ts             # WebMCP type declarations
├── dist/                             # Built output (@wordpress/scripts + webpack)
│   ├── webmcp-abilities.js       # Compiled bundle
│   └── webmcp-abilities.asset.php # WP dependency manifest with version hash
└── tests/phpunit/             # 51 integration tests
```

---

## Roadmap

- [ ] MCP Adapter integration (expose tools to CLI/API agents, not just browser)
- [ ] WooCommerce tools (products, cart, checkout)
- [ ] BuddyPress / bbPress community tools
- [ ] Declarative WebMCP API support (HTML form population)
- [ ] Tool annotations (`readonly`, `destructive`, `idempotent`)
- [x] WordPress.org plugin directory submission

---

## Contributing

PRs welcome. Please include tests for any new tools or behavior changes.

```bash
composer install
npx wp-env start
# make changes
npx wp-env run tests-cli "..."  # verify green
```

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0](https://www.gnu.org/licenses/gpl-2.0.html).

Built by [Code Atlantic](https://code-atlantic.com) · [Product Page](https://code-atlantic.com/products/webmcp-abilities-for-wordpress/).
