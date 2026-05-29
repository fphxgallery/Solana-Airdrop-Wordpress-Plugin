<?php
/**
 * Plugin Name: Airdrop
 * Description: Solana SPL token giveaway — users enter wallet addresses, a countdown triggers on threshold, winners are drawn and tokens sent automatically.
 * Version:     1.0.2
 * Author:      fPHX
 * License:     GPL-2.0-or-later
 * Text Domain: airdrop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIRDROP_VERSION',    '1.0.2' );
define( 'AIRDROP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIRDROP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-db.php';
require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-solana.php';
require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-cron.php';
require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-ajax.php';
require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-admin.php';
require_once AIRDROP_PLUGIN_DIR . 'includes/class-airdrop-frontend.php';

final class Airdrop_Plugin {

	private static ?Airdrop_Plugin $instance = null;

	public static function instance(): Airdrop_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		new Airdrop_Admin();
		new Airdrop_Frontend();
		new Airdrop_Ajax();
		new Airdrop_Cron();
	}
}

register_activation_hook( __FILE__, function (): void {
	Airdrop_DB::create_tables();
} );

register_deactivation_hook( __FILE__, function (): void {
	// Cron hooks won't fire while plugin is inactive; no cleanup needed.
} );

add_action( 'plugins_loaded', function (): void {
	Airdrop_Plugin::instance();
} );
