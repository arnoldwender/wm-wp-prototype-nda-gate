/**
 * WM Suite Prototype NDA Gate — forensic screen watermark, browser side.
 *
 * Paints the bit block the server handed over as a faint pattern across the viewport. The browser
 * gets the finished bits, never the account, the time or the key that produced them: everything this
 * file knows is already public in the pixels it draws.
 *
 * The tile is two blocks wide and two blocks tall, because every second block is painted inverted
 * (checkerboard by the parity of its column plus its row). That is what lets the reader on the server
 * subtract neighbouring blocks from each other and cancel the page underneath.
 */

(function () {
	'use strict';

	function boot() {
		var host = document.getElementById('wm-nda-watermark');
		if (!host || !host.dataset.block) {
			return;
		}

		var bits = [];
		var hex = host.dataset.block;
		for (var i = 0; i < hex.length; i++) {
			var nibble = parseInt(hex.charAt(i), 16);
			if (isNaN(nibble)) {
				return;
			}
			bits.push((nibble >> 3) & 1, (nibble >> 2) & 1, (nibble >> 1) & 1, nibble & 1);
		}

		var cell = parseInt(host.dataset.cell, 10) || 16;
		var cols = parseInt(host.dataset.cols, 10) || 10;
		var rows = parseInt(host.dataset.rows, 10) || 8;
		var alpha = (parseInt(host.dataset.alphaPermille, 10) || 30) / 1000;
		var inverts = '1' === host.dataset.invertsOddBlocks;

		if (bits.length < cols * rows) {
			return;
		}

		var canvas = document.createElement('canvas');
		canvas.setAttribute('aria-hidden', 'true');
		canvas.className = 'wm-nda-watermark-canvas';
		host.appendChild(canvas);

		var context = canvas.getContext('2d');
		if (!context) {
			return;
		}

		// One tile: the block and, when the carrier is on, its inverted neighbours.
		function buildTile(ratio) {
			var blocksAcross = inverts ? 2 : 1;
			var tile = document.createElement('canvas');
			tile.width = cols * cell * blocksAcross * ratio;
			tile.height = rows * cell * blocksAcross * ratio;

			var tileContext = tile.getContext('2d');
			if (!tileContext) {
				return null;
			}

			for (var by = 0; by < blocksAcross; by++) {
				for (var bx = 0; bx < blocksAcross; bx++) {
					var inverted = inverts && 1 === ((bx + by) % 2);
					for (var index = 0; index < cols * rows; index++) {
						var bit = inverted ? 1 - bits[index] : bits[index];
						var x = (bx * cols + (index % cols)) * cell * ratio;
						var y = (by * rows + Math.floor(index / cols)) * cell * ratio;
						tileContext.fillStyle = (bit ? 'rgba(0,0,0,' : 'rgba(255,255,255,') + alpha + ')';
						tileContext.fillRect(x, y, cell * ratio, cell * ratio);
					}
				}
			}

			return tile;
		}

		function paint() {
			// Whole device pixels per cell: a fractional ratio would blur the cell edges the reader
			// looks for, so it is rounded up to the next whole number.
			var ratio = Math.max(1, Math.round(window.devicePixelRatio || 1));
			var width = window.innerWidth;
			var height = window.innerHeight;

			canvas.width = width * ratio;
			canvas.height = height * ratio;
			canvas.style.width = width + 'px';
			canvas.style.height = height + 'px';

			var tile = buildTile(ratio);
			if (!tile) {
				return;
			}

			var pattern = context.createPattern(tile, 'repeat');
			if (!pattern) {
				return;
			}

			context.clearRect(0, 0, canvas.width, canvas.height);
			context.fillStyle = pattern;
			context.fillRect(0, 0, canvas.width, canvas.height);
		}

		paint();

		var pending = null;
		window.addEventListener('resize', function () {
			if (pending) {
				window.clearTimeout(pending);
			}
			pending = window.setTimeout(paint, 150);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
