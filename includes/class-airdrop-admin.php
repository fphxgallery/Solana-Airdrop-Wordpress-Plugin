<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Airdrop_Admin {

	public function __construct() {
		add_action( 'admin_menu',    [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_airdrop_save_campaign',   [ $this, 'handle_save_campaign' ] );
		add_action( 'admin_post_airdrop_delete_campaign', [ $this, 'handle_delete_campaign' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			'Airdrop',
			'Airdrop',
			'manage_options',
			'airdrop',
			[ $this, 'render_campaigns_page' ],
			'dashicons-parachute-box',
			30
		);

		add_submenu_page(
			'airdrop',
			'Campaigns',
			'Campaigns',
			'manage_options',
			'airdrop',
			[ $this, 'render_campaigns_page' ]
		);

		add_submenu_page(
			'airdrop',
			'New Campaign',
			'New Campaign',
			'manage_options',
			'airdrop-new',
			[ $this, 'render_campaign_form' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'airdrop' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'airdrop-admin',
			AIRDROP_PLUGIN_URL . 'assets/css/airdrop.css',
			[],
			AIRDROP_VERSION
		);
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'airdrop-admin-js',
			AIRDROP_PLUGIN_URL . 'assets/js/airdrop.js',
			[ 'jquery' ],
			AIRDROP_VERSION,
			true
		);
		wp_enqueue_script(
			'airdrop-colors-js',
			AIRDROP_PLUGIN_URL . 'assets/js/airdrop-colors.js',
			[ 'jquery', 'wp-color-picker' ],
			AIRDROP_VERSION,
			true
		);
		wp_localize_script( 'airdrop-admin-js', 'airdropAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'airdrop_admin_nonce' ),
		] );
	}

	// ── Campaigns List ──────────────────────────────────────────────────

	public function render_campaigns_page(): void {
		$campaigns = Airdrop_DB::get_campaigns();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Airdrop Campaigns</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=airdrop-new' ) ); ?>" class="page-title-action">Add New</a>
			<hr class="wp-header-end">

			<?php if ( empty( $campaigns ) ) : ?>
				<p>No campaigns yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=airdrop-new' ) ); ?>">Create one.</a></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Shortcode</th>
							<th>Status</th>
							<th>Entries</th>
							<th>Winners</th>
							<th>Mint</th>
							<th>Created</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $campaigns as $c ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $c->name ); ?></strong></td>
							<td>
								<code id="sc-<?php echo (int) $c->id; ?>">[airdrop id="<?php echo (int) $c->id; ?>"]</code>
								<button type="button" class="button button-small airdrop-copy-sc" data-target="sc-<?php echo (int) $c->id; ?>" style="margin-left:4px;">Copy</button>
							</td>
							<td><span class="airdrop-badge airdrop-badge--<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( $c->status ); ?></span></td>
							<td><?php echo (int) $c->entry_count; ?> / <?php echo (int) $c->wallet_threshold; ?></td>
							<td><?php echo (int) $c->num_winners; ?></td>
							<td><code title="<?php echo esc_attr( $c->token_mint ); ?>"><?php echo esc_html( substr( $c->token_mint, 0, 8 ) . '…' ); ?></code></td>
							<td><?php echo esc_html( substr( $c->created_at, 0, 10 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=airdrop-new&edit=' . $c->id ) ); ?>">Edit</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=airdrop&view_entries=' . $c->id ) ); ?>">Entries</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url(
									wp_nonce_url(
										admin_url( 'admin-post.php?action=airdrop_delete_campaign&campaign_id=' . $c->id ),
										'airdrop_delete_' . $c->id
									)
								); ?>" onclick="return confirm('Delete this campaign and all entries?');" style="color:#a00;">Delete</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			// Entries sub-view.
			$view_id = (int) ( $_GET['view_entries'] ?? 0 );
			if ( $view_id ) {
				$this->render_entries_table( $view_id );
			}
			?>
		</div>
		<?php
	}

	private function render_entries_table( int $campaign_id ): void {
		$campaign = Airdrop_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return;
		}
		$entries = Airdrop_DB::get_entries( $campaign_id );
		$dec     = (int) $campaign->token_decimals;
		?>
		<h2 style="margin-top:2em;">Entries — <?php echo esc_html( $campaign->name ); ?></h2>

		<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<?php if ( in_array( $campaign->status, [ 'pending', 'countdown' ], true ) ) : ?>
			<button class="button airdrop-trigger-btn" data-campaign="<?php echo $campaign_id; ?>">
				⚡ Force Process Now
			</button>
			<?php endif; ?>
			<button class="button airdrop-reset-btn" data-campaign="<?php echo $campaign_id; ?>"
				onclick="return confirm('Reset campaign to pending? All entry statuses and TX signatures will be cleared.');">
				↩ Reset Campaign
			</button>
			<span class="airdrop-trigger-msg"></span>
		</p>

		<table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
			<thead>
				<tr>
					<th>Wallet</th>
					<th>Status</th>
					<th>Balance (raw)</th>
					<th>Transaction</th>
					<th>Submitted</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="5">No entries yet.</td></tr>
				<?php else : foreach ( $entries as $e ) : ?>
					<tr>
						<td><code><?php echo esc_html( $e->wallet_address ); ?></code></td>
						<td><span class="airdrop-badge airdrop-badge--<?php echo esc_attr( $e->status ); ?>"><?php echo esc_html( $e->status ); ?></span></td>
						<td><?php echo $e->token_balance !== null ? number_format( (int) $e->token_balance ) : '—'; ?></td>
						<td>
							<?php if ( ! empty( $e->tx_signature ) ) : ?>
								<a href="https://solscan.io/tx/<?php echo esc_attr( $e->tx_signature ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( substr( $e->tx_signature, 0, 8 ) . '…' ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( substr( $e->submitted_at, 0, 16 ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	// ── Campaign Form ───────────────────────────────────────────────────

	public function render_campaign_form(): void {
		$edit_id  = (int) ( $_GET['edit'] ?? 0 );
		$campaign = $edit_id ? Airdrop_DB::get_campaign( $edit_id ) : null;
		$title    = $campaign ? 'Edit Campaign' : 'New Campaign';

		$defaults = [
			'name'              => '',
			'token_mint'        => '',
			'token_decimals'    => 9,
			'required_holding'  => 0,
			'prize_amount'      => 0,
			'num_winners'       => 1,
			'wallet_threshold'  => 10,
			'max_entries'       => 0,
			'countdown_seconds' => 3600,
			'rpc_endpoint'      => 'https://api.mainnet-beta.solana.com',
			'sender_pubkey'     => '',
		];

		$v = $campaign ? (array) $campaign : $defaults;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php if ( ! extension_loaded( 'sodium' ) || ! extension_loaded( 'gmp' ) ) : ?>
			<div class="notice notice-error">
				<p><strong>Warning:</strong> Auto-send requires the PHP <code>sodium</code> and <code>gmp</code> extensions.
				<?php
				$missing = [];
				if ( ! extension_loaded( 'sodium' ) ) $missing[] = 'sodium';
				if ( ! extension_loaded( 'gmp' ) )    $missing[] = 'gmp';
				echo 'Missing: ' . implode( ', ', $missing ) . '.';
				?>
				</p>
			</div>
			<?php endif; ?>

			<div class="notice notice-warning">
				<p><strong>Security:</strong> The sender wallet is a hot wallet. Store only the tokens needed for this airdrop. The private key is AES-256 encrypted using your site's auth salt.</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'airdrop_save_campaign', 'airdrop_nonce' ); ?>
				<input type="hidden" name="action" value="airdrop_save_campaign">
				<?php if ( $edit_id ) : ?>
					<input type="hidden" name="campaign_id" value="<?php echo $edit_id; ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="name">Campaign Name</label></th>
						<td><input type="text" id="name" name="name" value="<?php echo esc_attr( $v['name'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="token_mint">Token Mint Address</label></th>
						<td><input type="text" id="token_mint" name="token_mint" value="<?php echo esc_attr( $v['token_mint'] ); ?>" class="regular-text" placeholder="So11111111111111111111111111111111111111112" required>
						<p class="description">SPL token mint address (base58).</p></td>
					</tr>
					<tr>
						<th><label for="token_decimals">Token Decimals</label></th>
						<td><input type="number" id="token_decimals" name="token_decimals" value="<?php echo (int) $v['token_decimals']; ?>" min="0" max="18" style="width:80px;">
						<p class="description">Decimal places for this token (e.g. 9 for SOL, 6 for USDC). Used for display only.</p></td>
					</tr>
					<tr>
						<th><label for="required_holding">Required Holding (raw)</label></th>
						<td><input type="number" id="required_holding" name="required_holding" value="<?php echo (int) $v['required_holding']; ?>" min="0" style="width:160px;" required>
						<p class="description">Minimum token balance a wallet must hold to qualify. In raw units (multiply human amount × 10^decimals).</p></td>
					</tr>
					<tr>
						<th><label for="prize_amount">Prize Per Winner (raw)</label></th>
						<td><input type="number" id="prize_amount" name="prize_amount" value="<?php echo (int) $v['prize_amount']; ?>" min="1" style="width:160px;" required>
						<p class="description">Tokens sent to each winner. Raw units.</p></td>
					</tr>
					<tr>
						<th><label for="num_winners">Number of Winners</label></th>
						<td><input type="number" id="num_winners" name="num_winners" value="<?php echo (int) $v['num_winners']; ?>" min="1" style="width:80px;" required></td>
					</tr>
					<tr>
						<th><label for="wallet_threshold">Wallet Threshold</label></th>
						<td><input type="number" id="wallet_threshold" name="wallet_threshold" value="<?php echo (int) $v['wallet_threshold']; ?>" min="1" style="width:100px;" required>
						<p class="description">Number of wallet entries that triggers the countdown.</p></td>
					</tr>
					<tr>
						<th><label for="countdown_seconds">Countdown Duration (seconds)</label></th>
						<td><input type="number" id="countdown_seconds" name="countdown_seconds" value="<?php echo (int) $v['countdown_seconds']; ?>" min="60" style="width:120px;" required>
						<p class="description">E.g. 3600 = 1 hour, 86400 = 24 hours.</p></td>
					</tr>
					<tr>
						<th><label for="rpc_endpoint">Solana RPC Endpoint</label></th>
						<td><input type="url" id="rpc_endpoint" name="rpc_endpoint" value="<?php echo esc_attr( $v['rpc_endpoint'] ); ?>" class="regular-text" required>
						<p class="description">E.g. <code>https://api.mainnet-beta.solana.com</code> or a private RPC like Helius/QuickNode.</p></td>
					</tr>
					<tr>
						<th><label for="max_entries">Max Entries</label></th>
						<td><input type="number" id="max_entries" name="max_entries" value="<?php echo (int) ( $v['max_entries'] ?? 0 ); ?>" min="0" style="width:120px;">
						<p class="description">Maximum total entries allowed. <code>0</code> = unlimited.</p></td>
					</tr>
					<tr>
						<th><label for="sender_pubkey">Sender Wallet Public Key</label></th>
						<td><input type="text" id="sender_pubkey" name="sender_pubkey" value="<?php echo esc_attr( $v['sender_pubkey'] ); ?>" class="regular-text" placeholder="Base58 public key">
						<p class="description">The wallet that will send tokens to winners. Must hold enough tokens and SOL for fees.</p></td>
					</tr>
					<tr>
						<th><label for="sender_privkey">Sender Private Key</label></th>
						<td>
							<input type="password" id="sender_privkey" name="sender_privkey" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $campaign ? '(leave blank to keep current)' : 'Base58 private key (32 or 64 bytes)'; ?>">
							<p class="description">Stored AES-256 encrypted. <?php echo $campaign ? 'Leave blank to keep the existing key.' : 'Base58-encoded keypair.'; ?></p>
						</td>
					</tr>
				</table>

				<?php
				// Parse stored colors for the pickers.
				$stored_colors = [];
				if ( ! empty( $v['custom_colors'] ) ) {
					$stored_colors = json_decode( $v['custom_colors'], true ) ?: [];
				}
				$color_defaults = [
					'grad_start'  => '#7c3aed',
					'grad_end'    => '#ec4899',
					'card_bg'     => 'rgba(255,255,255,0.08)',
					'card_border' => 'rgba(255,255,255,0.15)',
					'text_color'  => 'rgba(255,255,255,1)',
					'btn_text'    => '#ffffff',
				];
				$colors = array_merge( $color_defaults, $stored_colors );
				?>

				<h2 style="margin-top:2rem;padding-top:1rem;border-top:1px solid #ddd;">🎨 Visual Styling</h2>
				<p class="description" style="margin-bottom:1rem;">Customize card appearance per campaign. Changes take effect on the shortcode immediately after saving.</p>

				<table class="form-table" role="presentation">
					<tr class="airdrop-style-section-header">
						<th colspan="2" style="padding-bottom:0;"><strong>Colors</strong></th>
					</tr>

					<?php
					$color_fields = [
						[
							'key'     => 'grad_start',
							'label'   => 'Accent Start',
							'desc'    => 'Gradient start — used for title, button, progress fill, spinner.',
							'alpha'   => false,
							'default' => '#7c3aed',
						],
						[
							'key'     => 'grad_end',
							'label'   => 'Accent End',
							'desc'    => 'Gradient end — pairs with Accent Start.',
							'alpha'   => false,
							'default' => '#ec4899',
						],
						[
							'key'     => 'card_bg',
							'label'   => 'Card Background',
							'desc'    => 'Card fill. Reduce alpha for glassmorphism; increase for opaque cards.',
							'alpha'   => true,
							'default' => 'rgba(255,255,255,0.08)',
						],
						[
							'key'     => 'card_border',
							'label'   => 'Card Border',
							'desc'    => 'Card outline color.',
							'alpha'   => true,
							'default' => 'rgba(255,255,255,0.15)',
						],
						[
							'key'     => 'text_color',
							'label'   => 'Body Text',
							'desc'    => 'Wallet input + hint text color. Full alpha = opaque.',
							'alpha'   => true,
							'default' => 'rgba(255,255,255,1)',
						],
						[
							'key'     => 'btn_text',
							'label'   => 'Button Text',
							'desc'    => 'Color of the "Enter" button label.',
							'alpha'   => false,
							'default' => '#ffffff',
						],
					];

					foreach ( $color_fields as $cf ) :
						$val = $colors[ $cf['key'] ] ?? $cf['default'];
					?>
					<tr>
						<th><label><?php echo esc_html( $cf['label'] ); ?></label></th>
						<td>
							<div class="airdrop-cpicker" data-alpha="<?php echo $cf['alpha'] ? '1' : '0'; ?>" data-default="<?php echo esc_attr( $cf['default'] ); ?>">
								<div class="airdrop-cp-swatch">
									<div class="airdrop-cp-swatch-inner"></div>
								</div>
								<div>
									<input
										type="text"
										class="airdrop-cp-hex"
										value="<?php echo esc_attr( $val ); ?>"
										data-alpha-color-picker="<?php echo $cf['alpha'] ? 'true' : 'false'; ?>"
										style="max-width:100px;"
									>
									<?php if ( $cf['alpha'] ) : ?>
									<div class="airdrop-cp-alpha-wrap" style="margin-top:8px;">
										<span class="airdrop-cp-alpha-label">Transparency</span>
										<div class="airdrop-cp-alpha-row">
											<input type="range" class="airdrop-cp-alpha" min="0" max="1" step="0.01" value="1">
											<span class="airdrop-cp-alpha-val">1.00</span>
										</div>
									</div>
									<?php endif; ?>
								</div>
								<input type="hidden" name="colors[<?php echo esc_attr( $cf['key'] ); ?>]" class="airdrop-cp-value" value="<?php echo esc_attr( $val ); ?>">
							</div>
							<p class="description" style="margin-top:6px;"><?php echo esc_html( $cf['desc'] ); ?></p>
						</td>
					</tr>
					<?php endforeach; ?>

					<tr class="airdrop-style-section-header">
						<th colspan="2" style="padding-bottom:0;"><strong>Shape &amp; Effect</strong></th>
					</tr>
					<tr>
						<th><label for="style_radius">Border Radius (px)</label></th>
						<td>
							<input type="number" id="style_radius" name="colors[radius]"
								value="<?php echo esc_attr( $colors['radius'] ?? 16 ); ?>"
								min="0" max="64" style="width:80px;">
							<p class="description">Card corner rounding. 0 = square, 16 = default, 32 = pill.</p>
						</td>
					</tr>
					<tr>
						<th><label for="style_blur">Backdrop Blur (px)</label></th>
						<td>
							<input type="number" id="style_blur" name="colors[blur]"
								value="<?php echo esc_attr( $colors['blur'] ?? 12 ); ?>"
								min="0" max="40" style="width:80px;">
							<p class="description">Frosted glass blur behind the card. 0 = none.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( $campaign ? 'Update Campaign' : 'Create Campaign' ); ?>
			</form>
		</div>
		<?php
	}

	// ── Form Handlers ───────────────────────────────────────────────────

	public function handle_save_campaign(): void {
		check_admin_referer( 'airdrop_save_campaign', 'airdrop_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );

		$data = [
			'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
			'token_mint'        => sanitize_text_field( $_POST['token_mint'] ?? '' ),
			'token_decimals'    => (int) ( $_POST['token_decimals'] ?? 9 ),
			'required_holding'  => (int) ( $_POST['required_holding'] ?? 0 ),
			'prize_amount'      => (int) ( $_POST['prize_amount'] ?? 0 ),
			'num_winners'       => max( 1, (int) ( $_POST['num_winners'] ?? 1 ) ),
			'wallet_threshold'  => max( 1, (int) ( $_POST['wallet_threshold'] ?? 10 ) ),
			'max_entries'       => max( 0, (int) ( $_POST['max_entries'] ?? 0 ) ),
			'countdown_seconds' => max( 60, (int) ( $_POST['countdown_seconds'] ?? 3600 ) ),
			'rpc_endpoint'      => esc_url_raw( $_POST['rpc_endpoint'] ?? '' ),
			'sender_pubkey'     => sanitize_text_field( $_POST['sender_pubkey'] ?? '' ),
		];

		// Handle private key — only update if a new value was provided.
		$privkey_raw = sanitize_text_field( $_POST['sender_privkey'] ?? '' );
		if ( $privkey_raw ) {
			$data['sender_privkey_enc'] = Airdrop_Solana::encrypt_privkey( $privkey_raw );
		}

		// Sanitize and encode color/style settings.
		$raw_colors   = $_POST['colors'] ?? [];
		$allowed_keys = [ 'grad_start', 'grad_end', 'card_bg', 'card_border', 'text_color', 'btn_text', 'radius', 'blur' ];
		$clean_colors = [];
		foreach ( $allowed_keys as $k ) {
			if ( ! isset( $raw_colors[ $k ] ) ) continue;
			$val = sanitize_text_field( $raw_colors[ $k ] );
			if ( in_array( $k, [ 'radius', 'blur' ], true ) ) {
				$clean_colors[ $k ] = max( 0, (int) $val );
			} elseif ( preg_match( '/^rgba?\s*\(\s*[\d.,\s]+\)$/', $val ) || preg_match( '/^#[0-9a-fA-F]{3,8}$/', $val ) ) {
				$clean_colors[ $k ] = $val;
			}
		}
		$data['custom_colors'] = wp_json_encode( $clean_colors );

		if ( $campaign_id ) {
			Airdrop_DB::update_campaign( $campaign_id, $data );
			wp_redirect( add_query_arg( [ 'page' => 'airdrop-new', 'edit' => $campaign_id, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		} else {
			if ( ! $privkey_raw ) {
				wp_die( 'Private key is required for new campaigns.' );
			}
			$new_id = Airdrop_DB::create_campaign( $data );
			wp_redirect( add_query_arg( [ 'page' => 'airdrop-new', 'edit' => $new_id, 'created' => 1 ], admin_url( 'admin.php' ) ) );
		}
		exit;
	}

	public function handle_delete_campaign(): void {
		$campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
		check_admin_referer( 'airdrop_delete_' . $campaign_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		Airdrop_DB::delete_campaign( $campaign_id );
		wp_redirect( add_query_arg( [ 'page' => 'airdrop', 'deleted' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'airdrop' ) === false ) {
			return;
		}

		if ( isset( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Campaign created. Add <code>[airdrop id="' . (int) $_GET['edit'] . '"]</code> to a page.</p></div>';
		}
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Campaign updated.</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Campaign deleted.</p></div>';
		}
	}
}
