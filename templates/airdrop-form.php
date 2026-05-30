<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Variables available: $campaign, $countdown_target, $entry_count, $winners, $campaign_id
$cid = (int) $campaign->id;
$status = $campaign->status;
$threshold = (int) $campaign->wallet_threshold;
$max_entries = (int) $campaign->max_entries;
$num_winners = (int) $campaign->num_winners;
$dec = (int) $campaign->token_decimals;
$prize_human = $dec > 0 ? rtrim( rtrim( number_format( (int) $campaign->prize_amount / pow( 10, $dec ), $dec, '.', ',' ), '0' ), '.' ) : number_format( (int) $campaign->prize_amount );
$accepting = ! in_array( $status, [ 'distributing', 'complete' ], true );
?>
<div class="airdrop-wrap" id="airdrop-<?php echo $cid; ?>" data-campaign="<?php echo $cid; ?>">

	<div class="airdrop-card">

		<div class="airdrop-header">
			<h2 class="airdrop-title"><?php echo esc_html( $campaign->name ); ?></h2>
			<div class="airdrop-meta">
				<span class="airdrop-badge airdrop-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
				<span class="airdrop-prize"><?php echo esc_html( $prize_human ); ?> tokens × <?php echo $num_winners; ?> winner<?php echo $num_winners > 1 ? 's' : ''; ?></span>
			</div>
		</div>

		<?php if ( $status === 'complete' ) : ?>
		<!-- COMPLETE -->
		<div class="airdrop-complete">
			<div class="airdrop-complete-icon">🎉</div>
			<h3>Airdrop Complete</h3>
			<?php if ( ! empty( $winners ) ) : ?>
				<p class="airdrop-winners-label">Winners:</p>
				<ul class="airdrop-winners-list">
					<?php foreach ( $winners as $w ) : ?>
					<li class="airdrop-winner-item">
						<code class="airdrop-wallet"><?php echo esc_html( substr( $w->wallet_address, 0, 6 ) . '…' . substr( $w->wallet_address, -4 ) ); ?></code>
						<span class="airdrop-badge airdrop-badge--<?php echo esc_attr( $w->status ); ?>"><?php echo esc_html( $w->status ); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>No qualified winners were found.</p>
			<?php endif; ?>
		</div>

		<?php elseif ( $status === 'distributing' ) : ?>
		<!-- DISTRIBUTING -->
		<div class="airdrop-distributing">
			<div class="airdrop-spinner"></div>
			<p>Drawing winners and sending tokens…</p>
		</div>

		<?php else : ?>
		<!-- PENDING / COUNTDOWN -->

		<?php if ( $status === 'countdown' && $countdown_target ) : ?>
		<div class="airdrop-countdown-wrap">
			<p class="airdrop-countdown-label">Airdrop closes in</p>
			<div class="airdrop-countdown" id="airdrop-countdown-<?php echo $cid; ?>" data-target="<?php echo (int) $countdown_target; ?>">
				<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-h-<?php echo $cid; ?>">00</span><span class="airdrop-cd-label">H</span></div>
				<div class="airdrop-cd-sep">:</div>
				<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-m-<?php echo $cid; ?>">00</span><span class="airdrop-cd-label">M</span></div>
				<div class="airdrop-cd-sep">:</div>
				<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-s-<?php echo $cid; ?>">00</span><span class="airdrop-cd-label">S</span></div>
			</div>
		</div>
		<?php else : ?>
		<div class="airdrop-progress-wrap">
			<p class="airdrop-progress-label">
				<span id="airdrop-count-<?php echo $cid; ?>"><?php echo $entry_count; ?></span>
				of <strong><?php echo $threshold; ?></strong> wallets entered<?php if ( $max_entries > 0 ) : ?>
				<span class="airdrop-spots" id="airdrop-spots-<?php echo $cid; ?>">(<?php echo max( 0, $max_entries - $entry_count ); ?> spots left)</span>
				<?php endif; ?>
			</p>
			<div class="airdrop-progress-bar">
				<div class="airdrop-progress-fill" id="airdrop-fill-<?php echo $cid; ?>" style="width:<?php echo min( 100, round( $entry_count / max( 1, $threshold ) * 100 ) ); ?>%"></div>
			</div>
			<p class="airdrop-progress-hint"><?php echo max( 0, $threshold - $entry_count ); ?> more entries needed to start the countdown</p>
		</div>
		<?php endif; ?>

		<div class="airdrop-form-wrap">
			<div id="airdrop-msg-<?php echo $cid; ?>" class="airdrop-msg" style="display:none;"></div>
			<form class="airdrop-form" id="airdrop-form-<?php echo $cid; ?>" data-campaign="<?php echo $cid; ?>">
				<div class="airdrop-input-row">
					<input
						type="text"
						id="airdrop-wallet-<?php echo $cid; ?>"
						name="wallet"
						class="airdrop-wallet-input"
						placeholder="Enter your Solana wallet address"
						autocomplete="off"
						spellcheck="false"
					>
					<button type="submit" class="airdrop-submit-btn">Enter</button>
				</div>
				<p class="airdrop-hint">Paste your Solana wallet address to enter.</p>
			</form>
		</div>

		<?php endif; ?>

	</div><!-- .airdrop-card -->
</div><!-- .airdrop-wrap -->
