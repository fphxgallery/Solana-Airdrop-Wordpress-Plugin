/* global jQuery, airdropAdmin */
(function ($) {
	'use strict';

	var LS_PREFIX = 'airdrop_entered_';

	// ── Utilities ──────────────────────────────────────────────────────

	function isValidSolanaAddress(addr) {
		return /^[1-9A-HJ-NP-Za-km-z]{32,44}$/.test(addr);
	}

	function pad2(n) {
		return String(n).padStart(2, '0');
	}

	function showMsg($wrap, text, type) {
		$wrap.removeClass('airdrop-msg--success airdrop-msg--error')
			.addClass('airdrop-msg--' + type)
			.text(text)
			.show();
	}

	function lsKey(campaignId) {
		return LS_PREFIX + campaignId;
	}

	function markEntered(campaignId, wallet) {
		try { localStorage.setItem(lsKey(campaignId), wallet); } catch (e) {}
	}

	function getEntered(campaignId) {
		try { return localStorage.getItem(lsKey(campaignId)) || ''; } catch (e) { return ''; }
	}

	// ── Admin: copy shortcode button ───────────────────────────────────

	$(document).on('click', '.airdrop-copy-sc', function () {
		var targetId = $(this).data('target');
		var text     = document.getElementById(targetId) ? document.getElementById(targetId).textContent : '';
		if (!text) return;
		var $btn = $(this);
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				$btn.text('Copied!');
				setTimeout(function () { $btn.text('Copy'); }, 1500);
			});
		} else {
			// Fallback: select text.
			var el = document.getElementById(targetId);
			var range = document.createRange();
			range.selectNode(el);
			window.getSelection().removeAllRanges();
			window.getSelection().addRange(range);
			document.execCommand('copy');
			$btn.text('Copied!');
			setTimeout(function () { $btn.text('Copy'); }, 1500);
		}
	});

	// ── Admin: reset campaign button ───────────────────────────────────

	$(document).on('click', '.airdrop-reset-btn', function () {
		var $btn = $(this);
		var cid  = $btn.data('campaign');
		var $msg = $btn.closest('p').find('.airdrop-trigger-msg');
		$btn.prop('disabled', true).text('Resetting…');
		$msg.text('');
		$.ajax({
			url:    (typeof airdropAdmin !== 'undefined' ? airdropAdmin.ajaxUrl : '/wp-admin/admin-ajax.php'),
			method: 'POST',
			data: {
				action:      'airdrop_reset_campaign',
				nonce:       (typeof airdropAdmin !== 'undefined' ? airdropAdmin.nonce : ''),
				campaign_id: cid,
			},
			success: function (res) {
				$msg.text(res.success ? 'Reset! Reload to see updated entries.' : (res.data && res.data.message ? res.data.message : 'Failed.'));
				$btn.prop('disabled', false).text('↩ Reset Campaign');
			},
			error: function () {
				$msg.text('Network error.');
				$btn.prop('disabled', false).text('↩ Reset Campaign');
			},
		});
	});

	// ── Admin: fetch mint decimals from chain ──────────────────────────

	$(document).on('click', '.airdrop-fetch-decimals', function () {
		var $btn  = $(this);
		var $msg  = $btn.siblings('.airdrop-fetch-decimals-msg');
		var mint  = $('#token_mint').val().trim();
		var rpc   = $('#rpc_endpoint').val().trim();

		if (!mint) {
			$msg.css('color', '#a00').text('Enter a token mint address first.');
			return;
		}

		$btn.prop('disabled', true).text('Fetching…');
		$msg.css('color', '').text('');

		$.ajax({
			url:    (typeof airdropAdmin !== 'undefined' ? airdropAdmin.ajaxUrl : '/wp-admin/admin-ajax.php'),
			method: 'POST',
			data: {
				action: 'airdrop_fetch_decimals',
				nonce:  (typeof airdropAdmin !== 'undefined' ? airdropAdmin.nonce : ''),
				mint:   mint,
				rpc:    rpc,
			},
			success: function (res) {
				if (res.success) {
					$('#token_decimals').val(res.data.decimals);
					$msg.css('color', '#1a7f37').text('Decimals: ' + res.data.decimals + ' (re-enter token amounts below).');
				} else {
					$msg.css('color', '#a00').text(res.data && res.data.message ? res.data.message : 'Fetch failed.');
				}
				$btn.prop('disabled', false).text('Fetch from chain');
			},
			error: function () {
				$msg.css('color', '#a00').text('Network error.');
				$btn.prop('disabled', false).text('Fetch from chain');
			},
		});
	});

	// ── Per-campaign instance ──────────────────────────────────────────

	function AirdropInstance(el) {
		this.$el             = $(el);
		this.campaignId      = parseInt(this.$el.data('campaign'), 10);
		this.dataKey         = 'airdropData_' + this.campaignId;
		this.data            = window[this.dataKey] || {};
		this.status          = this.data.status || 'pending';
		this.countdownTarget = this.data.countdownTarget ? parseInt(this.data.countdownTarget, 10) : null;
		this.pollTimer       = null;
		this.cdTimer         = null;

		this._initEnteredState();
		this._bindForm();
		this._bindAdminTrigger();
		this._startCountdown();
		this._startPolling();
	}

	/**
	 * If user already entered (localStorage), hide form + show confirmation.
	 */
	AirdropInstance.prototype._initEnteredState = function () {
		var wallet = getEntered(this.campaignId);
		if (!wallet || this.status === 'complete') return;

		var cid  = this.campaignId;
		var $msg = this.$el.find('#airdrop-msg-' + cid);
		var $form = this.$el.find('#airdrop-form-' + cid);

		showMsg($msg, '✓ You\'re in the pool! Wallet: ' + wallet.substring(0, 6) + '…' + wallet.slice(-4), 'success');
		$form.find('.airdrop-wallet-input').val(wallet).prop('disabled', true);
		$form.find('.airdrop-submit-btn').hide();
	};

	AirdropInstance.prototype._bindForm = function () {
		var self = this;
		this.$el.on('submit', '.airdrop-form', function (e) {
			e.preventDefault();
			var $form  = $(this);
			var cid    = $form.data('campaign');
			var wallet = $.trim($form.find('.airdrop-wallet-input').val());
			var $msg   = self.$el.find('#airdrop-msg-' + cid);
			var $btn   = $form.find('.airdrop-submit-btn');

			if (!wallet) {
				showMsg($msg, 'Please enter a wallet address.', 'error');
				return;
			}
			if (!isValidSolanaAddress(wallet)) {
				showMsg($msg, 'Invalid Solana wallet address.', 'error');
				return;
			}

			$btn.prop('disabled', true).text('Submitting…');

			$.ajax({
				url:    self.data.ajaxUrl,
				method: 'POST',
				data: {
					action:      'airdrop_submit_wallet',
					nonce:       self.data.nonce,
					campaign_id: cid,
					wallet:      wallet,
				},
				success: function (res) {
					if (res.success) {
						var d = res.data;
						markEntered(cid, wallet);
						showMsg($msg, d.already_entered
							? '✓ Already in the pool — good luck!'
							: '✓ Wallet entered! Good luck.',
							'success'
						);
						$form.find('.airdrop-wallet-input').prop('disabled', true);
						$btn.hide();
						self._updateState(d.status, d.entry_count, d.countdown_target);
					} else {
						showMsg($msg, res.data && res.data.message ? res.data.message : 'Something went wrong.', 'error');
						$btn.prop('disabled', false).text('Enter');
					}
				},
				error: function () {
					showMsg($msg, 'Network error. Please try again.', 'error');
					$btn.prop('disabled', false).text('Enter');
				},
			});
		});
	};

	AirdropInstance.prototype._bindAdminTrigger = function () {
		var self = this;
		this.$el.on('click', '.airdrop-trigger-btn', function () {
			var $btn = $(this);
			var $msg = $btn.siblings('.airdrop-trigger-msg');
			$btn.prop('disabled', true).text('Processing…');
			$msg.text('');
			$.ajax({
				url:    (typeof airdropAdmin !== 'undefined' ? airdropAdmin.ajaxUrl : self.data.ajaxUrl),
				method: 'POST',
				data: {
					action:      'airdrop_trigger_process',
					nonce:       (typeof airdropAdmin !== 'undefined' ? airdropAdmin.nonce : self.data.nonce),
					campaign_id: self.campaignId,
				},
				success: function (res) {
					$msg.text(res.success ? 'Done! Refresh to see results.' : (res.data && res.data.message ? res.data.message : 'Failed.'));
					$btn.prop('disabled', false).text('⚡ Force Process Now');
					if (res.success) self._startPolling();
				},
				error: function () {
					$msg.text('Network error.');
					$btn.prop('disabled', false).text('⚡ Force Process Now');
				},
			});
		});
	};

	// ── State management ───────────────────────────────────────────────

	AirdropInstance.prototype._updateState = function (status, entryCount, countdownTarget) {
		var changed = status !== this.status;
		this.status = status;

		if (countdownTarget) {
			this.countdownTarget = parseInt(countdownTarget, 10);
		}

		if (status === 'countdown' && this.countdownTarget && changed) {
			this._showCountdown();
			this._startCountdown();
		}

		if (entryCount !== undefined) {
			this.$el.find('#airdrop-count-' + this.campaignId).text(entryCount);
			var threshold = this.data.walletThreshold || 1;
			var pct = Math.min(100, Math.round(entryCount / threshold * 100));
			this.$el.find('#airdrop-fill-' + this.campaignId).css('width', pct + '%');
			this.$el.find('.airdrop-progress-hint').text(
				Math.max(0, threshold - entryCount) + ' more entries needed to start the countdown'
			);
		}

		if (status === 'complete' || status === 'distributing') {
			this._stopPolling();
			this._stopCountdown();
			window.location.reload();
		}
	};

	// ── Countdown ──────────────────────────────────────────────────────

	AirdropInstance.prototype._showCountdown = function () {
		var $progressWrap  = this.$el.find('.airdrop-progress-wrap');
		var $countdownWrap = this.$el.find('.airdrop-countdown-wrap');
		if ($progressWrap.length && !$countdownWrap.length) {
			var cid = this.campaignId;
			var cdHtml = '<div class="airdrop-countdown-wrap">' +
				'<p class="airdrop-countdown-label">Airdrop closes in</p>' +
				'<div class="airdrop-countdown" id="airdrop-countdown-' + cid + '">' +
				'<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-h-' + cid + '">00</span><span class="airdrop-cd-label">H</span></div>' +
				'<div class="airdrop-cd-sep">:</div>' +
				'<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-m-' + cid + '">00</span><span class="airdrop-cd-label">M</span></div>' +
				'<div class="airdrop-cd-sep">:</div>' +
				'<div class="airdrop-cd-unit"><span class="airdrop-cd-num" id="airdrop-s-' + cid + '">00</span><span class="airdrop-cd-label">S</span></div>' +
				'</div></div>';
			$progressWrap.replaceWith(cdHtml);
		}
	};

	AirdropInstance.prototype._startCountdown = function () {
		if (!this.countdownTarget || this.cdTimer) return;
		var self = this;
		var cid  = this.campaignId;

		this.cdTimer = setInterval(function () {
			var remaining = Math.max(0, self.countdownTarget - Math.floor(Date.now() / 1000));
			var h = Math.floor(remaining / 3600);
			var m = Math.floor((remaining % 3600) / 60);
			var s = remaining % 60;

			self.$el.find('#airdrop-h-' + cid).text(pad2(h));
			self.$el.find('#airdrop-m-' + cid).text(pad2(m));
			self.$el.find('#airdrop-s-' + cid).text(pad2(s));

			if (remaining === 0) {
				self._stopCountdown();
				// Trigger cron fallback — WP cron may not have fired.
				self._checkOverdue();
			}
		}, 1000);
	};

	AirdropInstance.prototype._stopCountdown = function () {
		if (this.cdTimer) { clearInterval(this.cdTimer); this.cdTimer = null; }
	};

	/**
	 * Cron reliability fallback: called when client-side timer hits zero.
	 * If WP cron hasn't processed the campaign yet, this AJAX call triggers it.
	 */
	AirdropInstance.prototype._checkOverdue = function () {
		var self = this;
		$.ajax({
			url:    self.data.ajaxUrl,
			method: 'POST',
			data: {
				action:      'airdrop_check_overdue',
				nonce:       self.data.nonce,
				campaign_id: self.campaignId,
			},
			success: function (res) {
				if (res.success && res.data && res.data.triggered) {
					// Processing was triggered; poll aggressively for status change.
					self._startPolling(2000);
				} else {
					self._startPolling(3000);
				}
			},
			error: function () {
				self._startPolling(5000);
			},
		});
	};

	// ── Status polling ─────────────────────────────────────────────────

	AirdropInstance.prototype._startPolling = function (interval) {
		var self = this;
		this._stopPolling();
		interval = interval || 10000;

		this.pollTimer = setInterval(function () {
			$.ajax({
				url:    self.data.ajaxUrl,
				method: 'POST',
				data: {
					action:      'airdrop_poll_status',
					nonce:       self.data.nonce,
					campaign_id: self.campaignId,
				},
				success: function (res) {
					if (res.success) {
						var d = res.data;
						self._updateState(d.status, d.entry_count, d.countdown_target);
					}
				},
			});
		}, interval);
	};

	AirdropInstance.prototype._stopPolling = function () {
		if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
	};

	// ── Init ───────────────────────────────────────────────────────────

	$(function () {
		$('.airdrop-wrap').each(function () {
			new AirdropInstance(this);
		});
	});

}(jQuery));
