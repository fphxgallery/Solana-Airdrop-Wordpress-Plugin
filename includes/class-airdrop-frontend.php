<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Airdrop_Frontend {

	public function __construct() {
		add_shortcode( 'airdrop', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'airdrop-style',
			AIRDROP_PLUGIN_URL . 'assets/css/airdrop.css',
			[],
			AIRDROP_VERSION
		);

		wp_enqueue_script(
			'airdrop-main',
			AIRDROP_PLUGIN_URL . 'assets/js/airdrop.js',
			[ 'jquery' ],
			AIRDROP_VERSION,
			true
		);
	}

	private function build_custom_css( int $campaign_id, string $colors_json ): string {
		$colors = json_decode( $colors_json, true );
		if ( empty( $colors ) || ! is_array( $colors ) ) {
			return '';
		}

		$props_map = [
			'grad_start'  => '--airdrop-grad-start',
			'grad_end'    => '--airdrop-grad-end',
			'card_bg'     => '--airdrop-card-bg',
			'card_border' => '--airdrop-card-border',
			'text_color'  => '--airdrop-text-color',
			'btn_text'    => '--airdrop-btn-text',
		];

		$lines = [];
		foreach ( $props_map as $key => $prop ) {
			if ( isset( $colors[ $key ] ) && $colors[ $key ] !== '' ) {
				$val = esc_attr( $colors[ $key ] );
				$lines[] = "\t$prop: $val;";
			}
		}
		if ( isset( $colors['radius'] ) ) {
			$lines[] = "\t--airdrop-radius: " . (int) $colors['radius'] . "px;";
		}
		if ( isset( $colors['blur'] ) ) {
			$lines[] = "\t--airdrop-blur: " . (int) $colors['blur'] . "px;";
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$selector = '#airdrop-' . $campaign_id;
		return $selector . " {\n" . implode( "\n", $lines ) . "\n}";
	}

	public function render_shortcode( array $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'airdrop' );
		$campaign_id = (int) $atts['id'];

		if ( ! $campaign_id ) {
			return '<p class="airdrop-error">No campaign ID specified. Use <code>[airdrop id="1"]</code>.</p>';
		}

		$campaign = Airdrop_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return '<p class="airdrop-error">Campaign not found.</p>';
		}

		$countdown_target = null;
		if ( $campaign->status === 'countdown' && $campaign->countdown_start ) {
			$countdown_target = strtotime( $campaign->countdown_start ) + (int) $campaign->countdown_seconds;
		}

		$entry_count = Airdrop_DB::get_entry_count( $campaign_id );
		$winners     = $campaign->status === 'complete' ? Airdrop_DB::get_winners( $campaign_id ) : [];

		// Pass data to JS.
		wp_localize_script( 'airdrop-main', 'airdropData_' . $campaign_id, [
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'airdrop_nonce' ),
			'campaignId'       => $campaign_id,
			'status'           => $campaign->status,
			'entryCount'       => $entry_count,
			'walletThreshold'  => (int) $campaign->wallet_threshold,
			'countdownTarget'  => $countdown_target,
			'tokenDecimals'    => (int) $campaign->token_decimals,
			'prizeAmount'      => (int) $campaign->prize_amount,
			'numWinners'       => (int) $campaign->num_winners,
		] );

		// Build inline CSS custom properties from campaign colors.
		$custom_css = $this->build_custom_css( $campaign_id, $campaign->custom_colors ?? '{}' );

		ob_start();
		if ( $custom_css ) {
			echo '<style>' . $custom_css . '</style>';
		}
		include AIRDROP_PLUGIN_DIR . 'templates/airdrop-form.php';
		return ob_get_clean();
	}
}
