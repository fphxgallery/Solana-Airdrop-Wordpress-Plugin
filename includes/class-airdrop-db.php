<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Airdrop_DB {

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$campaigns_sql = "CREATE TABLE {$wpdb->prefix}airdrop_campaigns (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			token_mint varchar(64) NOT NULL DEFAULT '',
			token_decimals tinyint(2) UNSIGNED NOT NULL DEFAULT 9,
			required_holding bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			prize_amount bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			num_winners int(11) UNSIGNED NOT NULL DEFAULT 1,
			wallet_threshold int(11) UNSIGNED NOT NULL DEFAULT 10,
			countdown_seconds int(11) UNSIGNED NOT NULL DEFAULT 3600,
			rpc_endpoint varchar(255) NOT NULL DEFAULT 'https://api.mainnet-beta.solana.com',
			sender_pubkey varchar(64) NOT NULL DEFAULT '',
			sender_privkey_enc text NOT NULL,
			custom_colors text NOT NULL,
			max_entries int(11) UNSIGNED NOT NULL DEFAULT 0,
			status enum('pending','countdown','distributing','complete') NOT NULL DEFAULT 'pending',
			countdown_start datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;";

		$entries_sql = "CREATE TABLE {$wpdb->prefix}airdrop_entries (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) UNSIGNED NOT NULL,
			wallet_address varchar(64) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			token_balance bigint(20) UNSIGNED DEFAULT NULL,
			tx_signature varchar(128) DEFAULT NULL,
			status enum('pending','qualified','disqualified','winner','sent','failed') NOT NULL DEFAULT 'pending',
			PRIMARY KEY (id),
			UNIQUE KEY campaign_wallet (campaign_id, wallet_address),
			KEY campaign_status (campaign_id, status)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $campaigns_sql );
		dbDelta( $entries_sql );
	}

	// ── Campaigns ──────────────────────────────────────────────────────

	public static function get_campaign( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}airdrop_campaigns WHERE id = %d", $id )
		) ?: null;
	}

	public static function get_campaigns(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT c.*, (SELECT COUNT(*) FROM {$wpdb->prefix}airdrop_entries e WHERE e.campaign_id = c.id) AS entry_count
			 FROM {$wpdb->prefix}airdrop_campaigns c ORDER BY c.created_at DESC"
		) ?: [];
	}

	public static function get_campaigns_by_status( string $status ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}airdrop_campaigns WHERE status = %s",
				$status
			)
		) ?: [];
	}

	public static function create_campaign( array $data ): int|false {
		global $wpdb;
		$result = $wpdb->insert(
			"{$wpdb->prefix}airdrop_campaigns",
			[
				'name'               => $data['name'] ?? '',
				'token_mint'         => $data['token_mint'] ?? '',
				'token_decimals'     => (int) ( $data['token_decimals'] ?? 9 ),
				'required_holding'   => (int) ( $data['required_holding'] ?? 0 ),
				'prize_amount'       => (int) ( $data['prize_amount'] ?? 0 ),
				'num_winners'        => (int) ( $data['num_winners'] ?? 1 ),
				'wallet_threshold'   => (int) ( $data['wallet_threshold'] ?? 10 ),
				'countdown_seconds'  => (int) ( $data['countdown_seconds'] ?? 3600 ),
				'rpc_endpoint'       => $data['rpc_endpoint'] ?? 'https://api.mainnet-beta.solana.com',
				'sender_pubkey'      => $data['sender_pubkey'] ?? '',
				'sender_privkey_enc' => $data['sender_privkey_enc'] ?? '',
				'custom_colors'      => $data['custom_colors'] ?? '{}',
				'max_entries'        => (int) ( $data['max_entries'] ?? 0 ),
				'status'             => 'pending',
			],
			[ '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_campaign( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			"{$wpdb->prefix}airdrop_campaigns",
			$data,
			[ 'id' => $id ]
		);
	}

	/**
	 * Atomically flips status pending→countdown. Returns true only for the
	 * single caller that wins the flip, so cron is scheduled exactly once.
	 */
	public static function start_countdown_if_pending( int $id ): bool {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}airdrop_campaigns
				 SET status = 'countdown', countdown_start = %s
				 WHERE id = %d AND status = 'pending'",
				current_time( 'mysql' ), $id
			)
		);
		return (int) $rows === 1;
	}

	public static function delete_campaign( int $id ): bool {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}airdrop_entries", [ 'campaign_id' => $id ], [ '%d' ] );
		return (bool) $wpdb->delete( "{$wpdb->prefix}airdrop_campaigns", [ 'id' => $id ], [ '%d' ] );
	}

	// ── Entries ────────────────────────────────────────────────────────

	public static function get_entries( int $campaign_id, ?string $status = null ): array {
		global $wpdb;
		if ( $status !== null ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d AND status = %s ORDER BY submitted_at ASC",
					$campaign_id, $status
				)
			) ?: [];
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d ORDER BY submitted_at ASC",
				$campaign_id
			)
		) ?: [];
	}

	public static function get_entry_count( int $campaign_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d",
				$campaign_id
			)
		);
	}

	public static function get_winners( int $campaign_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT wallet_address, status FROM {$wpdb->prefix}airdrop_entries
				 WHERE campaign_id = %d AND status IN ('winner','sent','failed')
				 ORDER BY id ASC",
				$campaign_id
			)
		) ?: [];
	}

	public static function insert_entry( int $campaign_id, string $wallet, string $ip ): int|false {
		global $wpdb;
		// Use suppress_errors to silently ignore unique constraint violations
		// (duplicate wallet per campaign). Caller checks wallet_already_entered() first.
		$wpdb->suppress_errors( true );
		$result = $wpdb->insert(
			"{$wpdb->prefix}airdrop_entries",
			[
				'campaign_id'    => $campaign_id,
				'wallet_address' => $wallet,
				'ip_address'     => $ip,
			],
			[ '%d', '%s', '%s' ]
		);
		$wpdb->suppress_errors( false );
		return $result ? $wpdb->insert_id : false;
	}

	public static function update_entry( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			"{$wpdb->prefix}airdrop_entries",
			$data,
			[ 'id' => $id ]
		);
	}

	public static function delete_entry( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}airdrop_entries", [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Ordinal position of an entry within its campaign (count of rows with
	 * id <= $entry_id). Monotonic ids make this stable under concurrency, so
	 * it gives an authoritative cap check without a transaction.
	 */
	public static function get_entry_ordinal( int $campaign_id, int $entry_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d AND id <= %d",
				$campaign_id, $entry_id
			)
		);
	}

	public static function get_ip_entry_count( int $campaign_id, string $ip ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d AND ip_address = %s",
				$campaign_id, $ip
			)
		);
	}

	public static function wallet_already_entered( int $campaign_id, string $wallet ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}airdrop_entries WHERE campaign_id = %d AND wallet_address = %s",
				$campaign_id, $wallet
			)
		);
	}
}
