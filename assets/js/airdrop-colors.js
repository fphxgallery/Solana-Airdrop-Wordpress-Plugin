/* global jQuery, wp */
/**
 * Airdrop Admin — Color Pickers with Alpha Support
 *
 * Each `.airdrop-cpicker` wrapper contains:
 *   .airdrop-cp-hex   — text input, initialized as wp-color-picker
 *   .airdrop-cp-alpha — range input (0–1), only present on alpha-enabled fields
 *   .airdrop-cp-value — hidden input storing the final value (rgba or hex)
 *   .airdrop-cp-swatch — preview swatch div
 */
(function ($) {
	'use strict';

	// ── Helpers ────────────────────────────────────────────────────────

	/** Convert 3- or 6-char hex string + alpha → "rgba(r,g,b,a)" */
	function hexAlphaToRgba(hex, alpha) {
		hex = hex.replace(/^#/, '');
		if (hex.length === 3) {
			hex = hex.split('').map(function (c) { return c + c; }).join('');
		}
		var r = parseInt(hex.substr(0, 2), 16);
		var g = parseInt(hex.substr(2, 2), 16);
		var b = parseInt(hex.substr(4, 2), 16);
		if (isNaN(r) || isNaN(g) || isNaN(b)) return null;
		return alpha < 1
			? 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')'
			: '#' + hex;
	}

	/** Parse stored value (rgba(…) or #hex) → { hex: '#rrggbb', alpha: 0–1 } */
	function parseStoredValue(val) {
		if (!val) return null;
		// rgba(r,g,b,a) or rgb(r,g,b)
		var m = val.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)/);
		if (m) {
			var hex = '#' + [m[1], m[2], m[3]]
				.map(function (v) { return parseInt(v, 10).toString(16).padStart(2, '0'); })
				.join('');
			return { hex: hex, alpha: m[4] !== undefined ? parseFloat(m[4]) : 1 };
		}
		// #rrggbb or #rgb
		if (/^#[0-9a-fA-F]{3,8}$/.test(val)) {
			return { hex: val.substring(0, 7), alpha: 1 };
		}
		return null;
	}

	/** Update the alpha slider track gradient so it shows the color fading to transparent */
	function updateAlphaTrack($alpha, hex) {
		hex = hex.replace(/^#/, '');
		if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
		var r = parseInt(hex.substr(0, 2), 16);
		var g = parseInt(hex.substr(2, 2), 16);
		var b = parseInt(hex.substr(4, 2), 16);
		if (isNaN(r)) return;
		$alpha.css(
			'background',
			'linear-gradient(to right, rgba(' + r + ',' + g + ',' + b + ',0), rgba(' + r + ',' + g + ',' + b + ',1))'
		);
	}

	// ── Per-picker init ─────────────────────────────────────────────────

	function initPicker($wrap) {
		var $hex    = $wrap.find('.airdrop-cp-hex');
		var $alpha  = $wrap.find('.airdrop-cp-alpha');
		var $alphaVal = $wrap.find('.airdrop-cp-alpha-val');
		var $value  = $wrap.find('.airdrop-cp-value');
		var $swatch = $wrap.find('.airdrop-cp-swatch');
		var hasAlpha = $alpha.length > 0;

		function sync() {
			var hex   = $hex.val() || '#000000';
			var alpha = hasAlpha ? parseFloat($alpha.val()) : 1;
			if (isNaN(alpha)) alpha = 1;

			var computed = hexAlphaToRgba(hex, alpha);
			if (!computed) return;

			$value.val(computed);
			$swatch.css({
				background: computed,
				boxShadow: '0 0 0 1px rgba(0,0,0,0.2), inset 0 0 0 1px rgba(255,255,255,0.15)',
			});
			if (hasAlpha) {
				$alphaVal.text(alpha.toFixed(2));
				updateAlphaTrack($alpha, hex);
			}
		}

		// Init WP color picker.
		$hex.wpColorPicker({
			defaultColor: $wrap.data('default') || '#7c3aed',
			change: function (event, ui) {
				// ui.color is an Iris color object; get hex from it.
				setTimeout(function () {
					$hex.val(ui.color.toString());
					sync();
				}, 0);
			},
			clear: function () {
				$hex.val($wrap.data('default') || '#000000');
				sync();
			},
		});

		$alpha.on('input change', function () {
			sync();
		});

		// Populate from stored value.
		var stored = $value.val();
		if (stored) {
			var parsed = parseStoredValue(stored);
			if (parsed) {
				// Iris needs to be set via the wpColorPicker API after init.
				$hex.wpColorPicker('color', parsed.hex);
				$hex.val(parsed.hex);
				if (hasAlpha) {
					$alpha.val(parsed.alpha);
					$alphaVal.text(parsed.alpha.toFixed(2));
				}
			}
		}
		sync();
	}

	// ── Init all pickers ────────────────────────────────────────────────

	$(function () {
		$('.airdrop-cpicker').each(function () {
			initPicker($(this));
		});
	});

}(jQuery));
