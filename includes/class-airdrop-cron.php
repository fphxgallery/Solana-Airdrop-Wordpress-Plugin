<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Airdrop_Cron {

	public function __construct() {
		add_action( 'airdrop_process_campaign', [ $this, 'process_campaign' ] );
	}

	public static function schedule( int $campaign_id, int $fire_at ): void {
		if ( ! wp_next_scheduled( 'airdrop_process_campaign', [ $campaign_id ] ) ) {
			wp_schedule_single_event( $fire_at, 'airdrop_process_campaign', [ $campaign_id ] );
		}
	}

	/**
	 * Main cron handler: qualify → draw → send → email admin.
	 */
	public function process_campaign( int $campaign_id ): void {
		$campaign = Airdrop_DB::get_campaign( $campaign_id );
		if ( ! $campaign || $campaign->status !== 'countdown' ) {
			return; // Idempotent guard.
		}

		Airdrop_DB::update_campaign( $campaign_id, [ 'status' => 'distributing' ] );

		$solana  = new Airdrop_Solana( $campaign->rpc_endpoint );
		$entries = Airdrop_DB::get_entries( $campaign_id, 'pending' );

		// Qualify / disqualify.
		foreach ( $entries as $entry ) {
			$balance = $solana->check_token_balance( $entry->wallet_address, $campaign->token_mint );
			$status  = $balance >= (int) $campaign->required_holding ? 'qualified' : 'disqualified';
			Airdrop_DB::update_entry( (int) $entry->id, [
				'token_balance' => $balance,
				'status'        => $status,
			] );
		}

		$qualified = Airdrop_DB::get_entries( $campaign_id, 'qualified' );

		if ( empty( $qualified ) ) {
			Airdrop_DB::update_campaign( $campaign_id, [ 'status' => 'complete' ] );
			self::send_admin_email( $campaign, [] );
			return;
		}

		// Random draw.
		shuffle( $qualified );
		$winners = array_slice( $qualified, 0, (int) $campaign->num_winners );

		foreach ( $winners as $winner ) {
			Airdrop_DB::update_entry( (int) $winner->id, [ 'status' => 'winner' ] );
		}

		// Send tokens.
		$privkey      = Airdrop_Solana::decrypt_privkey( $campaign->sender_privkey_enc );
		$sent_winners = [];

		foreach ( $winners as $winner ) {
			$result = $solana->send_spl_transfer(
				$privkey,
				$winner->wallet_address,
				$campaign->token_mint,
				(int) $campaign->prize_amount
			);

			if ( is_wp_error( $result ) ) {
				Airdrop_DB::update_entry( (int) $winner->id, [
					'status' => 'failed',
				] );
				error_log( "[Airdrop] Send failed for {$winner->wallet_address}: " . $result->get_error_message() );
				$sent_winners[] = [ 'wallet' => $winner->wallet_address, 'status' => 'failed', 'tx' => '' ];
			} else {
				// $result is the transaction signature string.
				Airdrop_DB::update_entry( (int) $winner->id, [
					'status'       => 'sent',
					'tx_signature' => $result,
				] );
				$sent_winners[] = [ 'wallet' => $winner->wallet_address, 'status' => 'sent', 'tx' => $result ];
			}
		}

		Airdrop_DB::update_campaign( $campaign_id, [ 'status' => 'complete' ] );
		self::send_admin_email( $campaign, $sent_winners );
	}

	/**
	 * Email the site admin when an airdrop completes.
	 */
	private static function send_admin_email( object $campaign, array $winners ): void {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}

		$subject = '[Airdrop] ' . $campaign->name . ' — complete';

		$body  = "Airdrop campaign \"{$campaign->name}\" has finished.\n\n";
		$body .= "Total entries: " . Airdrop_DB::get_entry_count( (int) $campaign->id ) . "\n";
		$body .= "Winners drawn: " . count( $winners ) . "\n\n";

		if ( empty( $winners ) ) {
			$body .= "No qualified winners were found.\n";
		} else {
			$body .= "Winners:\n";
			foreach ( $winners as $w ) {
				$line = "  {$w['wallet']} — {$w['status']}";
				if ( $w['tx'] ) {
					$line .= "\n    Explorer: https://solscan.io/tx/{$w['tx']}";
				}
				$body .= $line . "\n";
			}
		}

		$body .= "\n— WordPress Airdrop Plugin";

		wp_mail( $admin_email, $subject, $body );
	}
}
