<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Airdrop_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_airdrop_submit_wallet',        [ $this, 'submit_wallet' ] );
		add_action( 'wp_ajax_nopriv_airdrop_submit_wallet', [ $this, 'submit_wallet' ] );
		add_action( 'wp_ajax_airdrop_poll_status',          [ $this, 'poll_status' ] );
		add_action( 'wp_ajax_nopriv_airdrop_poll_status',   [ $this, 'poll_status' ] );
		add_action( 'wp_ajax_airdrop_check_overdue',        [ $this, 'check_overdue' ] );
		add_action( 'wp_ajax_nopriv_airdrop_check_overdue', [ $this, 'check_overdue' ] );
		add_action( 'wp_ajax_airdrop_trigger_process',      [ $this, 'trigger_process' ] );
		add_action( 'wp_ajax_airdrop_reset_campaign',       [ $this, 'reset_campaign' ] );
	}

	public function submit_wallet(): void {
		check_ajax_referer( 'airdrop_nonce', 'nonce' );

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$wallet      = sanitize_text_field( $_POST['wallet'] ?? '' );

		if ( ! $campaign_id || ! $wallet ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
		}

		$campaign = Airdrop_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}

		if ( in_array( $campaign->status, [ 'distributing', 'complete' ], true ) ) {
			wp_send_json_error( [ 'message' => 'This airdrop has ended.' ] );
		}

		// Max entries cap (0 = unlimited).
		$max = (int) $campaign->max_entries;
		if ( $max > 0 ) {
			$current_count = Airdrop_DB::get_entry_count( $campaign_id );
			if ( $current_count >= $max ) {
				wp_send_json_error( [ 'message' => 'Entry limit reached. The pool is full.' ] );
			}
		}

		// Validate Solana wallet address: base58, 32–44 chars.
		if ( ! preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet ) ) {
			wp_send_json_error( [ 'message' => 'Invalid Solana wallet address.' ] );
		}

		// Rate-limit: 1 entry per IP per campaign.
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( Airdrop_DB::get_ip_entry_count( $campaign_id, $ip ) > 0 ) {
			wp_send_json_error( [ 'message' => 'You have already entered from this IP address.' ] );
		}

		$already_entered = Airdrop_DB::wallet_already_entered( $campaign_id, $wallet );
		$entry_id        = Airdrop_DB::insert_entry( $campaign_id, $wallet, $ip );

		// Authoritative max-entries enforcement. The pre-insert check above is a
		// fast path; this closes the check-then-insert race. A row's ordinal
		// (count of rows with id <= its own) is monotonic, so under concurrency
		// exactly $max rows get ordinal <= $max — roll back anything past the cap.
		if ( $max > 0 && $entry_id ) {
			$ordinal = Airdrop_DB::get_entry_ordinal( $campaign_id, (int) $entry_id );
			if ( $ordinal > $max ) {
				Airdrop_DB::delete_entry( (int) $entry_id );
				wp_send_json_error( [ 'message' => 'Entry limit reached. The pool is full.' ] );
			}
		}

		$entry_count = Airdrop_DB::get_entry_count( $campaign_id );

		// Start countdown when threshold is reached. The conditional UPDATE flips
		// status pending→countdown atomically; only the request that wins the flip
		// schedules the cron, preventing double-scheduling under concurrency.
		$countdown_target = null;
		if ( $campaign->status === 'pending' && $entry_count >= (int) $campaign->wallet_threshold ) {
			if ( Airdrop_DB::start_countdown_if_pending( $campaign_id ) ) {
				$fire_at = time() + (int) $campaign->countdown_seconds;
				Airdrop_Cron::schedule( $campaign_id, $fire_at );
			}
			// Re-fetch authoritative status + countdown_start (set by whichever request won the flip).
			$campaign = Airdrop_DB::get_campaign( $campaign_id );
		}

		if ( $campaign->status === 'countdown' && $campaign->countdown_start ) {
			$countdown_target = strtotime( $campaign->countdown_start ) + (int) $campaign->countdown_seconds;
		}

		wp_send_json_success( [
			'entry_count'      => $entry_count,
			'status'           => $campaign->status,
			'countdown_target' => $countdown_target,
			'already_entered'  => $already_entered,
		] );
	}

	public function poll_status(): void {
		check_ajax_referer( 'airdrop_nonce', 'nonce' );

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = Airdrop_DB::get_campaign( $campaign_id );

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}

		$countdown_target = null;
		if ( $campaign->status === 'countdown' && $campaign->countdown_start ) {
			$countdown_target = strtotime( $campaign->countdown_start ) + (int) $campaign->countdown_seconds;
		}

		$winners = [];
		if ( $campaign->status === 'complete' ) {
			foreach ( Airdrop_DB::get_winners( $campaign_id ) as $w ) {
				$winners[] = [
					'wallet' => $w->wallet_address,
					'status' => $w->status,
					'tx'     => $w->tx_signature ?? '',
				];
			}
		}

		wp_send_json_success( [
			'status'           => $campaign->status,
			'entry_count'      => Airdrop_DB::get_entry_count( $campaign_id ),
			'countdown_target' => $countdown_target,
			'winners'          => $winners,
		] );
	}

	/**
	 * Cron reliability fallback: if countdown expired but WP cron didn't fire,
	 * trigger processing directly on next page load.
	 */
	public function check_overdue(): void {
		check_ajax_referer( 'airdrop_nonce', 'nonce' );

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = Airdrop_DB::get_campaign( $campaign_id );

		if ( ! $campaign || $campaign->status !== 'countdown' || ! $campaign->countdown_start ) {
			wp_send_json_success( [ 'triggered' => false ] );
		}

		$fire_at = strtotime( $campaign->countdown_start ) + (int) $campaign->countdown_seconds;
		if ( time() < $fire_at ) {
			wp_send_json_success( [ 'triggered' => false ] );
		}

		// Countdown expired but status still 'countdown' — WP cron missed it.
		do_action( 'airdrop_process_campaign', $campaign_id );
		wp_send_json_success( [ 'triggered' => true ] );
	}

	/**
	 * Admin-only: manually trigger processing.
	 */
	public function trigger_process(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
		}
		check_ajax_referer( 'airdrop_admin_nonce', 'nonce' );

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = Airdrop_DB::get_campaign( $campaign_id );

		if ( ! $campaign || ! in_array( $campaign->status, [ 'countdown', 'pending' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Campaign cannot be processed in its current state.' ] );
		}

		Airdrop_DB::update_campaign( $campaign_id, [ 'status' => 'countdown' ] );
		do_action( 'airdrop_process_campaign', $campaign_id );

		wp_send_json_success( [ 'message' => 'Processing complete.' ] );
	}

	/**
	 * Admin-only: reset campaign back to pending.
	 */
	public function reset_campaign(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
		}
		check_ajax_referer( 'airdrop_admin_nonce', 'nonce' );

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		$campaign    = Airdrop_DB::get_campaign( $campaign_id );

		if ( ! $campaign ) {
			wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
		}

		// Clear scheduled cron.
		wp_clear_scheduled_hook( 'airdrop_process_campaign', [ $campaign_id ] );

		// Reset campaign.
		Airdrop_DB::update_campaign( $campaign_id, [
			'status'          => 'pending',
			'countdown_start' => null,
		] );

		// Reset all entries.
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}airdrop_entries",
			[ 'status' => 'pending', 'token_balance' => null, 'tx_signature' => null ],
			[ 'campaign_id' => $campaign_id ]
		);

		wp_send_json_success( [ 'message' => 'Campaign reset to pending.' ] );
	}
}
