<?php
/**
 * Admin settings page renderer.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Settings → WebMCP admin page and registers its fields.
 */
class Admin_Page {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	private Ability_Bridge $bridge;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Plugin settings.
	 * @param Ability_Bridge $bridge   Ability bridge.
	 */
	public function __construct( Settings $settings, Ability_Bridge $bridge ) {
		$this->settings = $settings;
		$this->bridge   = $bridge;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this->settings, 'register' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
	}

	/**
	 * Add the settings page under Settings → WebMCP.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'WebMCP Abilities for WordPress', 'webmcp-abilities' ),
			__( 'WebMCP', 'webmcp-abilities' ),
			'manage_options',
			'webmcp-abilities',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * The tabs on the settings page, in order. The first is the default.
	 *
	 * @return array<string, string> Slug => label.
	 */
	private function tabs(): array {
		return [
			'tools'    => __( 'Tools', 'webmcp-abilities' ),
			'settings' => __( 'Settings', 'webmcp-abilities' ),
			'status'   => __( 'Status', 'webmcp-abilities' ),
		];
	}

	/**
	 * The URL of one tab of this page.
	 *
	 * @param string $tab Tab slug.
	 */
	private function page_url( string $tab ): string {
		return add_query_arg(
			[
				'page' => 'webmcp-abilities',
				'tab'  => $tab,
			],
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing a tab changes nothing.

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = (string) array_key_first( $tabs );
		}

		?>
		<div class="wrap wmcp">
			<h1><?php esc_html_e( 'WebMCP Abilities', 'webmcp-abilities' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Allow AI agents visiting your site in Chrome 146+ to discover and use WordPress features as structured tools.', 'webmcp-abilities' ); ?>
			</p>

			<?php if ( ! is_ssl() ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'This site is served over plain HTTP. The WebMCP standard requires a secure context, so the front-end bridge stays disabled until the site uses HTTPS.', 'webmcp-abilities' ); ?>
				</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( $this->page_url( $id ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'tools' === $tab ) {
				$this->render_tools_table( $this->bridge->report(), $this->settings->is_discovery_public() );
			} elseif ( 'settings' === $tab ) {
				$this->render_settings_form( $this->settings->is_enabled(), $this->settings->is_discovery_public() );
			} else {
				$this->render_status();
			}
			?>

			<?php $this->render_assets( 'tools' === $tab ); ?>
		</div>
		<?php
	}

	/**
	 * The two site-wide switches.
	 *
	 * @param bool $is_enabled Whether the bridge is on.
	 * @param bool $is_public  Whether logged-out visitors may discover tools.
	 */
	private function render_settings_form( bool $is_enabled, bool $is_public ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wmcp_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wmcp_enabled">
							<?php esc_html_e( 'Enable WebMCP Abilities', 'webmcp-abilities' ); ?>
						</label>
					</th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( Settings::OPTION_ENABLED ); ?>"
								id="wmcp_enabled"
								value="1"
								<?php checked( $is_enabled ); ?>>
							<?php esc_html_e( 'Allow AI agents to use WordPress features as tools', 'webmcp-abilities' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When disabled, no WebMCP tools will be registered in the browser.', 'webmcp-abilities' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Tool Discovery', 'webmcp-abilities' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( Settings::OPTION_DISCOVERY_PUBLIC ); ?>"
								id="wmcp_discovery_public"
								value="1"
								<?php checked( $is_public ); ?>>
							<?php esc_html_e( 'Allow agents to discover tools without logging in', 'webmcp-abilities' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'The master switch for logged-out visitors. When unchecked, no tool is advertised to them whatever the Tools tab says. When checked, the tools ticked there are — names and descriptions only; execution still requires the appropriate permissions.', 'webmcp-abilities' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Every registered ability, with an eye to hide it and a box to open it up
	 * to logged-out visitors.
	 *
	 * Applied as they are clicked rather than on a Save button: these are one
	 * decision each, and there can be a hundred of them.
	 *
	 * @param array<int, array<string, mixed>> $rows      Report rows.
	 * @param bool                             $is_public Whether public discovery is on.
	 */
	private function render_tools_table( array $rows, bool $is_public ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No abilities are registered on this site yet.', 'webmcp-abilities' ) . '</p>';
			return;
		}

		$shown     = count( array_filter( array_column( $rows, 'visible' ) ) );
		$anonymous = count( array_filter( array_column( $rows, 'anonymous' ) ) );
		?>
		<h2><?php esc_html_e( 'Tools agents can reach', 'webmcp-abilities' ); ?></h2>

		<p class="description">
			<?php
			printf(
				/* translators: 1: number advertised, 2: number of registered abilities */
				esc_html__( 'Agents can reach %1$s of the %2$s abilities registered on this site. A plugin\'s tools are advertised as soon as it is active — each one checks its own permissions for whoever is asking, and that, not a list here, is what limits what an agent can do.', 'webmcp-abilities' ),
				'<strong id="wmcp-count-shown">' . esc_html( (string) $shown ) . '</strong>',
				'<strong>' . esc_html( (string) count( $rows ) ) . '</strong>'
			);
			?>
			<br>
			<?php esc_html_e( 'Click the eye to hide a tool from agents, or to show it again. Tick the box to advertise it to logged-out visitors as well.', 'webmcp-abilities' ); ?>
			<?php
			printf(
				/* translators: %s: number of tools advertised to logged-out visitors */
				esc_html__( 'Right now %s are.', 'webmcp-abilities' ),
				'<strong id="wmcp-count-anonymous">' . esc_html( (string) $anonymous ) . '</strong>'
			);
			?>
		</p>

		<?php if ( ! $is_public ) : ?>
			<p class="description wmcp-warn">
				<?php
				printf(
					/* translators: %s: link to the Settings tab */
					esc_html__( 'Tool discovery is currently limited to signed-in users on the %s tab, so nothing reaches a logged-out visitor whatever these boxes say.', 'webmcp-abilities' ),
					'<a href="' . esc_url( $this->page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'webmcp-abilities' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

		<div class="wmcp-scroll">
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th style="width:1.5em;"></th>
					<th><?php esc_html_e( 'Tool', 'webmcp-abilities' ); ?></th>
					<th><?php esc_html_e( 'What it does', 'webmcp-abilities' ); ?></th>
					<th style="width:11em;" class="wmcp-anon-cell"><?php esc_html_e( 'Logged-out visitors', 'webmcp-abilities' ); ?></th>
					<th style="width:16em;"><?php esc_html_e( 'Why', 'webmcp-abilities' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
				<tr class="wmcp-ability<?php echo $row['visible'] ? '' : ' is-hidden'; ?>" data-ability="<?php echo esc_attr( $row['name'] ); ?>">
					<td>
						<button type="button"
							class="wmcp-eye"
							data-ability="<?php echo esc_attr( $row['name'] ); ?>"
							data-visible="<?php echo $row['visible'] ? '1' : '0'; ?>"
							aria-pressed="<?php echo $row['visible'] ? 'true' : 'false'; ?>"
							<?php disabled( $row['locked'] ); ?>
							title="<?php echo esc_attr( $this->eye_title( (bool) $row['visible'], (bool) $row['locked'] ) ); ?>">
							<span class="dashicons <?php echo $row['visible'] ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"<?php echo $row['visible'] ? ' style="color:#00a32a"' : ''; ?>></span>
						</button>
					</td>
					<td>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
						<br><code><?php echo esc_html( $row['name'] ); ?></code>
					</td>
					<td><?php echo esc_html( $row['description'] ); ?></td>
					<td class="wmcp-anon-cell">
						<input type="checkbox"
							class="wmcp-anon"
							data-ability="<?php echo esc_attr( $row['name'] ); ?>"
							<?php checked( $row['anonymous'] ); ?>
							<?php disabled( $row['locked'] || ! $row['visible'] ); ?>
							title="<?php echo esc_attr( $this->anon_title( (bool) $row['visible'], (bool) $row['locked'] ) ); ?>">
					</td>
					<td class="wmcp-reason<?php echo $row['override'] ? ' is-override' : ''; ?>"><?php echo esc_html( $row['reason'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Tooltip for the eye button.
	 *
	 * @param bool $visible Whether the tool is advertised.
	 * @param bool $locked  Whether its own plugin opted it out.
	 */
	private function eye_title( bool $visible, bool $locked ): string {
		if ( $locked ) {
			return __( 'Its own plugin asked to keep this hidden', 'webmcp-abilities' );
		}

		return $visible
			? __( 'Advertised — click to hide', 'webmcp-abilities' )
			: __( 'Hidden — click to advertise', 'webmcp-abilities' );
	}

	/**
	 * Tooltip for the logged-out visitors checkbox.
	 *
	 * @param bool $visible Whether the tool is advertised.
	 * @param bool $locked  Whether its own plugin opted it out.
	 */
	private function anon_title( bool $visible, bool $locked ): string {
		if ( $locked || ! $visible ) {
			return __( 'A hidden tool reaches nobody', 'webmcp-abilities' );
		}

		return __( 'Advertise this tool to logged-out visitors too', 'webmcp-abilities' );
	}

	/**
	 * The status readout.
	 */
	private function render_status(): void {
		$count = function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0;
		?>
		<h2><?php esc_html_e( 'Status', 'webmcp-abilities' ); ?></h2>
		<ul>
			<li>
				<?php esc_html_e( 'HTTPS:', 'webmcp-abilities' ); ?>
				<?php if ( is_ssl() ) : ?>
					<span style="color:#00a32a;">✓ <?php esc_html_e( 'Enabled', 'webmcp-abilities' ); ?></span>
				<?php else : ?>
					<span style="color:#d63638;">✗ <?php esc_html_e( 'Not enabled — WebMCP will not work', 'webmcp-abilities' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<?php esc_html_e( 'WordPress Abilities API:', 'webmcp-abilities' ); ?>
				<?php if ( function_exists( 'wp_get_abilities' ) ) : ?>
					<span style="color:#00a32a;">✓ <?php esc_html_e( 'Available', 'webmcp-abilities' ); ?></span>
				<?php else : ?>
					<span style="color:#d63638;">✗ <?php esc_html_e( 'Not available', 'webmcp-abilities' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %d: number of registered abilities */
					esc_html__( 'Registered abilities: %d', 'webmcp-abilities' ),
					esc_html( $count )
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Browser support: Chrome 146+ required for WebMCP.', 'webmcp-abilities' ); ?>
			</li>
		</ul>

		<p>
			<a href="https://github.com/code-atlantic/webmcp-abilities" target="_blank">
				<?php esc_html_e( 'Plugin documentation & source code →', 'webmcp-abilities' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * The styles and the script behind the eye and the checkbox.
	 *
	 * Only the Tools tab has anything to drive, so the other tabs get nothing.
	 *
	 * @param bool $needed Whether the tools table is on screen.
	 */
	private function render_assets( bool $needed ): void {
		if ( ! $needed ) {
			return;
		}

		$endpoints = [
			'visibility' => rest_url( REST_API::NAMESPACE . '/abilities/visibility' ),
			'anonymous'  => rest_url( REST_API::NAMESPACE . '/abilities/anonymous' ),
		];

		$i18n = [
			'eyeShown'  => __( 'Advertised — click to hide', 'webmcp-abilities' ),
			'eyeHidden' => __( 'Hidden — click to advertise', 'webmcp-abilities' ),
			'anonOn'    => __( 'Advertise this tool to logged-out visitors too', 'webmcp-abilities' ),
			'anonOff'   => __( 'A hidden tool reaches nobody', 'webmcp-abilities' ),
			'failed'    => __( 'Could not save that change. Reload the page and try again.', 'webmcp-abilities' ),
		];
		?>
		<style>
			.wmcp .nav-tab-wrapper { margin-bottom:16px; }
			.wmcp .wmcp-scroll { overflow-x:auto; max-width:100%; }
			.wmcp .wmcp-eye { background:none; border:0; padding:0; cursor:pointer; line-height:1; }
			.wmcp .wmcp-eye:disabled { opacity:.5; cursor:default; }
			.wmcp .wmcp-ability.is-hidden { color:#646970; }
			.wmcp .wmcp-anon-cell { text-align:center; }
			.wmcp .wmcp-anon-cell input { margin:0; }
			.wmcp .wmcp-reason.is-override { color:#b26200; font-weight:600; }
			.wmcp .wmcp-warn { color:#b26200; }
		</style>
		<script>
		( function () {
			var endpoints = <?php echo wp_json_encode( $endpoints ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var i18n = <?php echo wp_json_encode( $i18n ); ?>;

			function post( url, body ) {
				return window.fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( body )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } );
			}

			function paint( row, data ) {
				var eye = row.querySelector( '.wmcp-eye' );
				eye.dataset.visible = data.visible ? '1' : '0';
				eye.setAttribute( 'aria-pressed', data.visible ? 'true' : 'false' );
				eye.title = data.visible ? i18n.eyeShown : i18n.eyeHidden;
				eye.firstElementChild.className = 'dashicons ' + ( data.visible ? 'dashicons-visibility' : 'dashicons-hidden' );
				eye.firstElementChild.style.color = data.visible ? '#00a32a' : '';
				row.classList.toggle( 'is-hidden', ! data.visible );

				// A hidden tool reaches nobody, so it cannot reach logged-out visitors either.
				var anon = row.querySelector( '.wmcp-anon' );
				anon.checked = !! data.anonymous;
				anon.disabled = ! data.visible;
				anon.title = data.visible ? i18n.anonOn : i18n.anonOff;

				var reason = row.querySelector( '.wmcp-reason' );
				reason.textContent = data.reason;
				reason.classList.toggle( 'is-override', !! data.override );

				[ [ 'wmcp-count-shown', data.count_shown ], [ 'wmcp-count-anonymous', data.count_anonymous ] ].forEach( function ( pair ) {
					var el = document.getElementById( pair[0] );
					if ( el ) { el.textContent = pair[1]; }
				} );
			}

			document.querySelectorAll( '.wmcp-eye' ).forEach( function ( eye ) {
				eye.addEventListener( 'click', function () {
					var hide = eye.dataset.visible === '1';
					var row = eye.closest( 'tr' );
					eye.disabled = true;
					post( endpoints.visibility, { ability: eye.dataset.ability, hide: hide } )
						.then( function ( data ) { paint( row, data ); } )
						.catch( function () { window.alert( i18n.failed ); } )
						.then( function () { eye.disabled = false; } );
				} );
			} );

			document.querySelectorAll( '.wmcp-anon' ).forEach( function ( anon ) {
				anon.addEventListener( 'change', function () {
					var wanted = anon.checked;
					var row = anon.closest( 'tr' );
					anon.disabled = true;
					post( endpoints.anonymous, { ability: anon.dataset.ability, anonymous: wanted } )
						.then( function ( data ) { paint( row, data ); } )
						.catch( function () {
							anon.checked = ! wanted;
							window.alert( i18n.failed );
						} )
						.then( function () { anon.disabled = false; } );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
