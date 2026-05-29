<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Solana RPC + SPL token transfer via raw transaction building.
 *
 * Requires: PHP sodium extension (php7.2+), GMP extension.
 */
class Airdrop_Solana {

	const TOKEN_PROGRAM    = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
	const ATA_PROGRAM      = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJe8bDp';
	const SYSTEM_PROGRAM   = '11111111111111111111111111111111';
	const SYSVAR_RENT      = 'SysvarRent111111111111111111111111111111111';

	private string $rpc_endpoint;

	public function __construct( string $rpc_endpoint ) {
		$this->rpc_endpoint = rtrim( $rpc_endpoint, '/' );
	}

	// ── Public API ─────────────────────────────────────────────────────

	/**
	 * Returns raw token balance (0 if no account found).
	 */
	public function check_token_balance( string $wallet, string $mint ): int {
		$result = $this->rpc( 'getTokenAccountsByOwner', [
			$wallet,
			[ 'mint' => $mint ],
			[ 'encoding' => 'jsonParsed' ],
		] );
		if ( is_wp_error( $result ) || empty( $result['value'] ) ) {
			return 0;
		}
		$amount = $result['value'][0]['account']['data']['parsed']['info']['tokenAmount']['amount'] ?? '0';
		return (int) $amount;
	}

	/**
	 * Returns the SOL balance of a wallet in lamports (0 on error).
	 */
	public function get_sol_balance( string $pubkey ): int {
		$result = $this->rpc( 'getBalance', [ $pubkey, [ 'commitment' => 'confirmed' ] ] );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		return (int) ( $result['value'] ?? 0 );
	}

	/**
	 * Sends SPL tokens from the sender keypair to $to_wallet.
	 *
	 * @param string $privkey_b58  Base58-encoded private key (32 or 64 bytes).
	 * @param string $to_wallet    Recipient's wallet (owner) address.
	 * @param string $mint         Token mint address.
	 * @param int    $amount       Raw token amount (no decimal adjustment).
	 * @return string|\WP_Error    Transaction signature on success.
	 */
	public function send_spl_transfer( string $privkey_b58, string $to_wallet, string $mint, int $amount ): string|\WP_Error {
		if ( ! extension_loaded( 'sodium' ) ) {
			return new \WP_Error( 'no_sodium', 'PHP sodium extension is required.' );
		}
		if ( ! extension_loaded( 'gmp' ) ) {
			return new \WP_Error( 'no_gmp', 'PHP GMP extension is required.' );
		}

		// Decode keypair.
		$keypair_bytes = $this->base58_decode( $privkey_b58 );
		if ( ! $keypair_bytes ) {
			return new \WP_Error( 'invalid_key', 'Could not decode private key.' );
		}

		if ( strlen( $keypair_bytes ) === 32 ) {
			// Seed only — derive full keypair.
			$keypair = sodium_crypto_sign_seed_keypair( $keypair_bytes );
		} elseif ( strlen( $keypair_bytes ) === 64 ) {
			$keypair = $keypair_bytes;
		} else {
			return new \WP_Error( 'invalid_key', 'Private key must be 32 or 64 bytes.' );
		}

		$secret_key = substr( $keypair, 0, 64 );
		$from_pubkey_bytes = sodium_crypto_sign_publickey_from_secretkey( $secret_key );
		$from_pubkey = $this->base58_encode( $from_pubkey_bytes );

		// Locate sender's ATA.
		$sender_ata = $this->get_token_account( $from_pubkey, $mint );
		if ( ! $sender_ata ) {
			return new \WP_Error( 'no_sender_ata', "Sender has no token account for mint $mint." );
		}

		// Derive recipient's ATA.
		$recipient_ata_bytes = $this->derive_ata( $to_wallet, $mint );
		$recipient_ata = $this->base58_encode( $recipient_ata_bytes );

		// Check if recipient ATA exists.
		$ata_exists = $this->account_exists( $recipient_ata );

		// Get latest blockhash.
		$blockhash_result = $this->rpc( 'getLatestBlockhash', [ [ 'commitment' => 'confirmed' ] ] );
		if ( is_wp_error( $blockhash_result ) ) {
			return $blockhash_result;
		}
		$blockhash = $blockhash_result['value']['blockhash'] ?? '';
		if ( ! $blockhash ) {
			return new \WP_Error( 'no_blockhash', 'Could not fetch recent blockhash.' );
		}

		// Build transaction.
		$tx_bytes = $this->build_transfer_tx(
			$secret_key,
			$from_pubkey_bytes,
			$this->base58_decode( $sender_ata ),
			$this->base58_decode( $to_wallet ),
			$recipient_ata_bytes,
			$this->base58_decode( $mint ),
			$this->base58_decode( $blockhash ),
			$amount,
			! $ata_exists
		);

		// Send transaction.
		$send_result = $this->rpc( 'sendTransaction', [
			base64_encode( $tx_bytes ),
			[ 'encoding' => 'base64', 'preflightCommitment' => 'confirmed' ],
		] );

		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}
		// sendTransaction returns the signature string directly.
		if ( is_string( $send_result ) ) {
			// Confirm on-chain — a returned signature only means the tx was
			// accepted into the mempool, not that it succeeded.
			$confirmed = $this->confirm_signature( $send_result );
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}
			return $send_result;
		}
		return new \WP_Error( 'send_failed', 'Unexpected sendTransaction response: ' . wp_json_encode( $send_result ) );
	}

	/**
	 * Polls getSignatureStatuses until the tx is confirmed/finalized or times out.
	 * Returns true on success, or WP_Error (with the signature in error data) on
	 * on-chain failure or timeout so the caller can still record the signature.
	 */
	private function confirm_signature( string $sig, int $timeout = 60 ): true|\WP_Error {
		$deadline = time() + $timeout;
		do {
			$res = $this->rpc( 'getSignatureStatuses', [ [ $sig ], [ 'searchTransactionHistory' => true ] ] );
			if ( ! is_wp_error( $res ) && ! empty( $res['value'][0] ) ) {
				$st = $res['value'][0];
				if ( ! empty( $st['err'] ) ) {
					return new \WP_Error(
						'tx_failed',
						'Transaction failed on-chain: ' . wp_json_encode( $st['err'] ),
						[ 'signature' => $sig ]
					);
				}
				$status = $st['confirmationStatus'] ?? '';
				// confirmations === null means the tx is finalized/rooted.
				if ( $st['confirmations'] === null || in_array( $status, [ 'confirmed', 'finalized' ], true ) ) {
					return true;
				}
			}
			if ( time() >= $deadline ) {
				return new \WP_Error(
					'tx_unconfirmed',
					'Transaction not confirmed within timeout.',
					[ 'signature' => $sig ]
				);
			}
			sleep( 2 );
		} while ( true );
	}

	// ── Transaction Building ───────────────────────────────────────────

	/**
	 * Builds a signed legacy transaction bytes string.
	 *
	 * Optionally prepends a createAssociatedTokenAccount instruction when
	 * $create_ata is true (idempotent create, instruction tag 1).
	 */
	private function build_transfer_tx(
		string $secret_key,        // 64 bytes
		string $from_pubkey,       // 32 bytes
		string $sender_ata,        // 32 bytes
		string $to_wallet,         // 32 bytes (recipient owner, for ATA create)
		string $recipient_ata,     // 32 bytes
		string $mint,              // 32 bytes
		string $blockhash,         // 32 bytes
		int    $amount,
		bool   $create_ata
	): string {
		// Program ID bytes.
		$token_prog   = $this->base58_decode( self::TOKEN_PROGRAM );
		$ata_prog     = $this->base58_decode( self::ATA_PROGRAM );
		$system_prog  = $this->base58_decode( self::SYSTEM_PROGRAM );

		if ( $create_ata ) {
			// Accounts: signer(w), sender_ata(w), recipient_ata(w),
			//           token_prog(r), to_wallet(r), mint(r), system_prog(r), ata_prog(r)
			$account_keys = [
				$from_pubkey,     // 0 signer+writable
				$sender_ata,      // 1 writable
				$recipient_ata,   // 2 writable (new ATA)
				$token_prog,      // 3 readonly
				$to_wallet,       // 4 readonly (recipient owner)
				$mint,            // 5 readonly
				$system_prog,     // 6 readonly
				$ata_prog,        // 7 readonly (program for create_ata instruction)
			];
			// Header: [num_required_sigs, num_readonly_signed, num_readonly_unsigned]
			$header = chr(1) . chr(0) . chr(5); // 1 sig, 0 readonly signed, 5 readonly unsigned (3..7)

			// createAssociatedTokenAccount (idempotent, tag=1):
			// accounts: [payer=0, ata=2, owner=4, mint=5, system=6, token=3]
			$ata_ix = $this->build_instruction( 7, [0, 2, 4, 5, 6, 3], chr(1) );

			// SPL Transfer: accounts: [source=1, dest=2, authority=0]
			$transfer_ix = $this->build_instruction( 3, [1, 2, 0], chr(3) . pack( 'P', $amount ) );

			$instructions = $this->compact_u16( 2 ) . $ata_ix . $transfer_ix;
		} else {
			// Accounts: signer(w), sender_ata(w), recipient_ata(w), token_prog(r)
			$account_keys = [
				$from_pubkey,   // 0 signer+writable
				$sender_ata,    // 1 writable
				$recipient_ata, // 2 writable
				$token_prog,    // 3 readonly
			];
			$header = chr(1) . chr(0) . chr(1); // 1 sig, 0 readonly signed, 1 readonly unsigned

			// SPL Transfer: accounts: [source=1, dest=2, authority=0]
			$transfer_ix = $this->build_instruction( 3, [1, 2, 0], chr(3) . pack( 'P', $amount ) );
			$instructions = $this->compact_u16( 1 ) . $transfer_ix;
		}

		// Build message.
		$accounts_data = $this->compact_u16( count( $account_keys ) );
		foreach ( $account_keys as $key ) {
			$accounts_data .= $key;
		}
		$message = $header . $accounts_data . $blockhash . $instructions;

		// Sign message.
		$signature = sodium_crypto_sign_detached( $message, $secret_key );

		// Transaction = compact_u16(1 sig) + signature + message.
		return $this->compact_u16( 1 ) . $signature . $message;
	}

	private function build_instruction( int $program_idx, array $account_indices, string $data ): string {
		$accounts = $this->compact_u16( count( $account_indices ) );
		foreach ( $account_indices as $idx ) {
			$accounts .= chr( $idx );
		}
		return chr( $program_idx ) . $accounts . $this->compact_u16( strlen( $data ) ) . $data;
	}

	// ── PDA / ATA Derivation ───────────────────────────────────────────

	/**
	 * Derives the Associated Token Account address for a given owner and mint.
	 * Returns 32 bytes.
	 */
	private function derive_ata( string $owner_b58, string $mint_b58 ): string {
		$owner = $this->base58_decode( $owner_b58 );
		$mint  = $this->base58_decode( $mint_b58 );
		$token_prog = $this->base58_decode( self::TOKEN_PROGRAM );
		$ata_prog   = $this->base58_decode( self::ATA_PROGRAM );

		[ $pda ] = $this->find_program_address( [ $owner, $token_prog, $mint ], $ata_prog );
		return $pda;
	}

	/**
	 * Finds a PDA by iterating bumps 255→0 until the hash is off the Ed25519 curve.
	 * Returns [address_bytes_32, bump].
	 */
	private function find_program_address( array $seeds, string $program_id ): array {
		for ( $bump = 255; $bump >= 0; $bump-- ) {
			$input = implode( '', $seeds ) . chr( $bump ) . $program_id . 'ProgramDerivedAddress';
			$hash  = hash( 'sha256', $input, true );
			if ( ! $this->is_on_ed25519_curve( $hash ) ) {
				return [ $hash, $bump ];
			}
		}
		throw new \RuntimeException( 'Could not find valid program address.' );
	}

	/**
	 * Returns true if the 32-byte compressed point lies on the Ed25519 curve.
	 *
	 * Ed25519 curve: -x^2 + y^2 = 1 + d*x^2*y^2 (mod p)
	 * A point is valid iff (y^2 - 1) / (d*y^2 + 1) is a quadratic residue mod p.
	 */
	private function is_on_ed25519_curve( string $bytes32 ): bool {
		static $p = null, $d = null, $exp = null;
		if ( $p === null ) {
			$p   = gmp_sub( gmp_pow( gmp_init( 2 ), 255 ), gmp_init( 19 ) );
			$d   = gmp_mod(
				gmp_mul(
					gmp_mod( gmp_sub( $p, gmp_init( 121665 ) ), $p ),
					gmp_invert( gmp_init( 121666 ), $p )
				),
				$p
			);
			$exp = gmp_div( gmp_sub( $p, 1 ), 2 );
		}

		// Parse y (little-endian, clear sign bit from last byte).
		$y_bytes    = $bytes32;
		$y_bytes[31] = chr( ord( $y_bytes[31] ) & 0x7F );
		$y = gmp_import( $y_bytes, 1, GMP_LSW_FIRST );

		if ( gmp_cmp( $y, $p ) >= 0 ) {
			return false;
		}

		$y2  = gmp_mod( gmp_mul( $y, $y ), $p );
		$num = gmp_mod( gmp_sub( $y2, 1 ), $p );
		$den = gmp_mod( gmp_add( gmp_mul( $d, $y2 ), 1 ), $p );

		if ( gmp_cmp( $den, 0 ) === 0 ) {
			return false;
		}

		$x2 = gmp_mod( gmp_mul( $num, gmp_invert( $den, $p ) ), $p );

		if ( gmp_cmp( $x2, 0 ) === 0 ) {
			return true; // x = 0 is a valid point (it's the "neutral" for x)
		}

		// Euler's criterion: quadratic residue iff x2^((p-1)/2) == 1 mod p.
		return gmp_cmp( gmp_powm( $x2, $exp, $p ), 1 ) === 0;
	}

	// ── RPC Helpers ────────────────────────────────────────────────────

	private function get_token_account( string $wallet, string $mint ): ?string {
		$result = $this->rpc( 'getTokenAccountsByOwner', [
			$wallet,
			[ 'mint' => $mint ],
			[ 'encoding' => 'jsonParsed' ],
		] );
		if ( is_wp_error( $result ) || empty( $result['value'] ) ) {
			return null;
		}
		return $result['value'][0]['pubkey'] ?? null;
	}

	private function account_exists( string $pubkey ): bool {
		$result = $this->rpc( 'getAccountInfo', [ $pubkey, [ 'encoding' => 'base64' ] ] );
		return ! is_wp_error( $result ) && ! empty( $result['value'] );
	}

	/**
	 * Makes a Solana JSON-RPC call. Returns the 'result' field or WP_Error.
	 */
	private function rpc( string $method, array $params = [] ): mixed {
		$body = wp_json_encode( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
			'params'  => $params,
		] );

		$response = wp_remote_post( $this->rpc_endpoint, [
			'headers'     => [ 'Content-Type' => 'application/json' ],
			'body'        => $body,
			'timeout'     => 30,
			'data_format' => 'body',
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['error'] ) ) {
			return new \WP_Error(
				'rpc_error',
				$data['error']['message'] ?? 'RPC error',
				$data['error']
			);
		}

		return $data['result'] ?? null;
	}

	// ── Encoding Helpers ───────────────────────────────────────────────

	private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	public function base58_decode( string $input ): string {
		$alphabet = self::BASE58_ALPHABET;
		$n        = gmp_init( 0 );

		for ( $i = 0; $i < strlen( $input ); $i++ ) {
			$char = $input[ $i ];
			$pos  = strpos( $alphabet, $char );
			if ( $pos === false ) {
				return '';
			}
			$n = gmp_add( gmp_mul( $n, 58 ), $pos );
		}

		// Convert GMP to bytes (big-endian).
		$hex = gmp_strval( $n, 16 );
		if ( strlen( $hex ) % 2 !== 0 ) {
			$hex = '0' . $hex;
		}
		$bytes = hex2bin( $hex );

		// Prepend leading zero bytes for each leading '1'.
		$leading = 0;
		for ( $i = 0; $i < strlen( $input ) && $input[ $i ] === '1'; $i++ ) {
			$leading++;
		}

		return str_repeat( "\x00", $leading ) . $bytes;
	}

	public function base58_encode( string $data ): string {
		$alphabet = self::BASE58_ALPHABET;
		$n        = gmp_init( bin2hex( $data ), 16 );
		$result   = '';

		while ( gmp_cmp( $n, 0 ) > 0 ) {
			[ $n, $rem ] = gmp_div_qr( $n, 58 );
			$result = $alphabet[ gmp_intval( $rem ) ] . $result;
		}

		// Leading zeros.
		for ( $i = 0; $i < strlen( $data ) && $data[ $i ] === "\x00"; $i++ ) {
			$result = '1' . $result;
		}

		return $result;
	}

	/**
	 * Compact-u16 / short-vec encoding (Solana's variable-length integer).
	 */
	private function compact_u16( int $n ): string {
		if ( $n === 0 ) {
			return chr( 0 );
		}
		$buf = '';
		do {
			$cur = $n & 0x7F;
			$n >>= 7;
			if ( $n > 0 ) {
				$cur |= 0x80;
			}
			$buf .= chr( $cur );
		} while ( $n > 0 );
		return $buf;
	}

	// ── Admin Utilities ────────────────────────────────────────────────

	public static function encrypt_privkey( string $privkey ): string {
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $privkey, 'AES-256-CBC', wp_salt( 'auth' ), 0, $iv );
		return base64_encode( $iv ) . '::' . $enc;
	}

	public static function decrypt_privkey( string $stored ): string {
		if ( strpos( $stored, '::' ) === false ) {
			return '';
		}
		[ $iv_b64, $enc ] = explode( '::', $stored, 2 );
		return openssl_decrypt( $enc, 'AES-256-CBC', wp_salt( 'auth' ), 0, base64_decode( $iv_b64 ) ) ?: '';
	}
}
