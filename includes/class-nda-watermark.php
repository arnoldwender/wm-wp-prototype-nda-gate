<?php
/**
 * WM Suite Prototype NDA Gate — forensic screen watermark: encoder, renderer data and extractor
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A faint pattern laid over the screen of a signed user, and the reader that gets it back out of a
 * screenshot.
 *
 * The pattern is a grid of cells whose darkness carries one bit each. Eighty cells make a block: an
 * eight-bit sync word so the reader can find the grid in a cropped screenshot, and seventy-two bits
 * of payload — a version, the account ID, the minute the page was served and a truncated HMAC over
 * those three. The block repeats across the viewport, so a crop that holds one block holds
 * everything, and every further block is another vote on the same bits.
 *
 * What it can do: name the account whose screen a leaked, unscaled screenshot came from, and say
 * when that screen was served. What it cannot do: survive a screenshot someone resized, rotated,
 * re-photographed or heavily re-compressed; prove who pressed the shutter; or work at all on a
 * browser that did not run the script. The extractor says "not found" in all of those cases, because
 * the HMAC does not verify — it never guesses an account.
 *
 * The key is the installation's (`AUTH_KEY`, else `wp_salt( 'auth' )`), so a screenshot only resolves
 * on the site that issued it. It is never sent to the browser: the server hands the page the finished
 * bit block, not the ingredients.
 */
final class WM_NDA_Watermark {

	/** Option that switches the overlay on. Off by default: it paints on every screen of the site. */
	public const OPTION = 'wm_nda_watermark';

	/** Cell edge in CSS pixels. One cell carries one bit. */
	public const CELL_PX = 16;

	/** A block is COLS × ROWS cells, read row by row. */
	public const BLOCK_COLS = 10;
	public const BLOCK_ROWS = 8;

	/** Bits of the sync word that opens every block, and the word itself (0xB2). */
	public const SYNC_BITS = 8;
	public const SYNC_WORD = 0xB2;

	/** Payload: 4 bits version, 24 bits account ID, 24 bits minute, 20 bits MAC. */
	public const VERSION      = 1;
	public const VERSION_BITS = 4;
	public const USER_BITS    = 24;
	public const MINUTE_BITS  = 24;
	public const MAC_BITS     = 20;

	/** Minute 0 of the timestamp field. Kept out of the past so 24 bits reach into 2057. */
	public const EPOCH = '2026-01-01T00:00:00+00:00';

	/**
	 * A cell is covered at this alpha — with black for a one bit and with white for a zero.
	 *
	 * Opposite inks at one alpha keep the distance between a one and a zero roughly the same wherever
	 * they land: about seven steps of an eight-bit channel on a light page and seven on a dark one.
	 * Black alone, at two alphas, moves a dark page by less than one step, which is where the first
	 * attempt of 2026-09-16 was measured failing — though that attempt also read the pattern with a
	 * neighbourhood filter, and once the reader averaged whole blocks instead, a single ink came back
	 * within reach on every synthetic fixture here and failed only intermittently through JPEG. So:
	 * two inks are the better-reasoned choice and the one in the code, but nothing in tests/ falsifies
	 * a single ink, and tests/mutations.php carries no entry pretending otherwise.
	 *
	 * Three per cent is faint, not invisible: on a large flat area a sharp eye can make the grid out.
	 * Below it the reader starts losing bits from a screenshot that went through JPEG.
	 */
	public const ALPHA_PERMILLE = 30;

	/** Screenshot scales the extractor tries: one device pixel per CSS pixel, and the usual 2× and 3×. */
	public const SCALES = [ 1, 2, 3 ];

	/** Pixels above which a screenshot is scaled down before reading, to stay inside PHP's memory limit. */
	public const MAX_PIXELS = 2000000;

	/** Alignments kept for decoding, strongest first. The MAC decides between them. */
	public const CANDIDATES = 24;

	/**
	 * Every second block is painted inverted (checkerboard by the parity of its column plus its row).
	 *
	 * Without it the reader failed over ordinary text, measured 2026-09-16. The block averaging only
	 * removes the page when the page differs from block to block, and a line of text does not: it runs
	 * straight through every block of its row, so the same ink sat under the same cell of all nine
	 * blocks and never cancelled. Inverting every second block makes the reader subtract those blocks
	 * from each other — content that repeats cancels, the pattern doubles. A crop can start on either
	 * parity, so the reader tries the sum both ways round and lets the MAC say which was right.
	 */
	public const CARRIER_INVERTS_ODD_BLOCKS = true;

	/**
	 * Whether this request should carry the overlay: the option is on, somebody is logged in, and that
	 * account has signed the active agreement.
	 *
	 * An account that has not signed sees the gate, not the content, so there is nothing to mark. WP-CLI
	 * and cron are not screens.
	 */
	public static function should_render(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}

		if ( ! (bool) get_option( self::OPTION, 0 ) || ! is_user_logged_in() ) {
			return false;
		}

		$term = WM_NDA_Database::get_active_term();

		return $term && WM_NDA_Database::has_user_accepted( get_current_user_id(), (int) $term->term_id );
	}

	/**
	 * Stylesheet and script of the overlay.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::should_render() ) {
			return;
		}

		wp_enqueue_style(
			'wm-nda-watermark',
			WM_NDA_GATE_URL . 'assets/css/nda-watermark.css',
			[],
			(string) ( @filemtime( WM_NDA_GATE_PATH . 'assets/css/nda-watermark.css' ) ?: WM_NDA_GATE_VERSION )
		);

		wp_enqueue_script(
			'wm-nda-watermark',
			WM_NDA_GATE_URL . 'assets/js/nda-watermark.js',
			[],
			(string) ( @filemtime( WM_NDA_GATE_PATH . 'assets/js/nda-watermark.js' ) ?: WM_NDA_GATE_VERSION ),
			true
		);
	}

	/**
	 * The element the script paints on. It carries the finished bit block and the geometry — never the
	 * account, the time or the key that produced them.
	 */
	public static function render_overlay(): void {
		if ( ! self::should_render() ) {
			return;
		}

		printf(
			'<div id="wm-nda-watermark" class="wm-nda-watermark-host" aria-hidden="true" data-block="%s" data-cell="%d" data-cols="%d" data-rows="%d" data-alpha-permille="%d" data-inverts-odd-blocks="%s"></div>',
			esc_attr( self::block_hex( get_current_user_id() ) ),
			(int) self::CELL_PX,
			(int) self::BLOCK_COLS,
			(int) self::BLOCK_ROWS,
			(int) self::ALPHA_PERMILLE,
			self::CARRIER_INVERTS_ODD_BLOCKS ? '1' : '0'
		);
	}

	/**
	 * Total cells of one block.
	 */
	public static function block_cells(): int {
		return self::BLOCK_COLS * self::BLOCK_ROWS;
	}

	/**
	 * Minutes between the epoch above and a moment, or 0 before it.
	 */
	public static function minutes_since_epoch( ?int $timestamp = null ): int {
		$epoch   = (int) strtotime( self::EPOCH );
		$moment  = $timestamp ?? time();
		$minutes = (int) floor( ( $moment - $epoch ) / 60 );

		return max( 0, min( $minutes, ( 1 << self::MINUTE_BITS ) - 1 ) );
	}

	/**
	 * The MAC that tells a decoded block from a pattern in the wallpaper: MAC_BITS bits of an
	 * HMAC-SHA256 over the other fields under this installation's key.
	 */
	public static function mac( int $version, int $user_id, int $minutes ): int {
		$digest = hash_hmac( 'sha256', "wm-nda-watermark|{$version}|{$user_id}|{$minutes}", self::key(), true );

		// The top MAC_BITS bits of the digest.
		$value = ( ord( $digest[0] ) << 16 ) | ( ord( $digest[1] ) << 8 ) | ord( $digest[2] );

		return $value >> ( 24 - self::MAC_BITS );
	}

	/**
	 * The signing key of this installation. Same source as the register chain, and for the same
	 * reason: a literal shipped in the plugin would let anyone forge a block that resolves.
	 */
	private static function key(): string {
		return defined( 'AUTH_KEY' ) && WM_NDA_Database::is_usable_key( (string) AUTH_KEY )
			? (string) AUTH_KEY
			: wp_salt( 'auth' );
	}

	/**
	 * The bits of one block, sync word first, for an account and a minute.
	 *
	 * @return array<int, int> BLOCK_COLS * BLOCK_ROWS values, 0 or 1.
	 */
	public static function block_bits( int $user_id, ?int $minutes = null ): array {
		$user_id = max( 0, min( $user_id, ( 1 << self::USER_BITS ) - 1 ) );
		$minutes = $minutes ?? self::minutes_since_epoch();
		$minutes = max( 0, min( $minutes, ( 1 << self::MINUTE_BITS ) - 1 ) );

		$bits = self::int_to_bits( self::SYNC_WORD, self::SYNC_BITS );
		$bits = array_merge( $bits, self::int_to_bits( self::VERSION, self::VERSION_BITS ) );
		$bits = array_merge( $bits, self::int_to_bits( $user_id, self::USER_BITS ) );
		$bits = array_merge( $bits, self::int_to_bits( $minutes, self::MINUTE_BITS ) );
		$bits = array_merge( $bits, self::int_to_bits( self::mac( self::VERSION, $user_id, $minutes ), self::MAC_BITS ) );

		return $bits;
	}

	/**
	 * The block as hexadecimal, which is what the page carries: the browser paints the finished bits
	 * and never sees the key or the fields.
	 */
	public static function block_hex( int $user_id, ?int $minutes = null ): string {
		$bits = self::block_bits( $user_id, $minutes );
		$hex  = '';

		foreach ( array_chunk( $bits, 4 ) as $nibble ) {
			$value = 0;
			foreach ( $nibble as $bit ) {
				$value = ( $value << 1 ) | $bit;
			}
			$hex .= dechex( $value );
		}

		return $hex;
	}

	/**
	 * Read a block's bits back into its fields, or null when the MAC does not verify.
	 *
	 * @param array<int, int> $bits Block bits, sync word included.
	 * @return array{version:int, user_id:int, minutes:int, issued_at:string}|null
	 */
	public static function decode_bits( array $bits ): ?array {
		if ( count( $bits ) !== self::block_cells() ) {
			return null;
		}

		$offset  = self::SYNC_BITS;
		$version = self::bits_to_int( array_slice( $bits, $offset, self::VERSION_BITS ) );
		$offset += self::VERSION_BITS;
		$user_id = self::bits_to_int( array_slice( $bits, $offset, self::USER_BITS ) );
		$offset += self::USER_BITS;
		$minutes = self::bits_to_int( array_slice( $bits, $offset, self::MINUTE_BITS ) );
		$offset += self::MINUTE_BITS;
		$mac     = self::bits_to_int( array_slice( $bits, $offset, self::MAC_BITS ) );

		// Everything above can be produced by noise; this is the line that keeps the extractor from
		// naming an account it read out of a gradient.
		if ( $mac !== self::mac( $version, $user_id, $minutes ) ) {
			return null;
		}

		return [
			'version'   => $version,
			'user_id'   => $user_id,
			'minutes'   => $minutes,
			'issued_at' => gmdate( 'Y-m-d H:i', (int) strtotime( self::EPOCH ) + $minutes * 60 ),
		];
	}

	/**
	 * Paint the pattern onto an open image the way the browser paints it onto the page.
	 *
	 * This is the same code path the tests and `wp nda watermark-sample` use to produce a screenshot
	 * to read back, so the reader is never checked against a fixture the reader itself produced.
	 *
	 * @param \GdImage $image   Image to paint on, changed in place.
	 * @param int      $user_id Account the block names.
	 * @param int|null $minutes Minute of the block; the current one when null.
	 * @param int      $scale   Device pixels per CSS pixel, as a screenshot of that display would have.
	 */
	public static function render_onto( $image, int $user_id, ?int $minutes = null, int $scale = 1 ): void {
		$bits   = self::block_bits( $user_id, $minutes );
		$cell   = self::CELL_PX * max( 1, $scale );
		$width  = imagesx( $image );
		$height = imagesy( $image );

		imagealphablending( $image, true );

		// Two colours: black for a one bit, white for a zero, both at the same alpha. GD counts alpha
		// the other way round and in 128 steps, so it is rounded once here instead of per cell.
		$gd_alpha = max( 0, min( 127, (int) round( 127 * ( 1 - self::ALPHA_PERMILLE / 1000 ) ) ) );
		$colours  = [
			0 => (int) imagecolorallocatealpha( $image, 255, 255, 255, $gd_alpha ),
			1 => (int) imagecolorallocatealpha( $image, 0, 0, 0, $gd_alpha ),
		];

		$block_w = self::BLOCK_COLS * $cell;
		$block_h = self::BLOCK_ROWS * $cell;

		$columns = 0;
		for ( $by = 0; $by < $height; $by += $block_h ) {
			$rows = intdiv( $by, $block_h );
			$columns = 0;
			for ( $bx = 0; $bx < $width; $bx += $block_w ) {
				$inverted = self::CARRIER_INVERTS_ODD_BLOCKS && 1 === ( ( $columns + $rows ) % 2 );
				foreach ( $bits as $index => $bit ) {
					$x = $bx + ( $index % self::BLOCK_COLS ) * $cell;
					$y = $by + intdiv( $index, self::BLOCK_COLS ) * $cell;
					imagefilledrectangle( $image, $x, $y, $x + $cell - 1, $y + $cell - 1, $colours[ $inverted ? 1 - $bit : $bit ] );
				}
				++$columns;
			}
		}
	}

	/**
	 * Read the watermark out of a screenshot.
	 *
	 * @param string $path Image file (PNG, JPEG or WebP).
	 * @return array{found:bool, error:?string, user_id:?int, issued_at:?string, scale:?int, blocks:?int, sync_score:?float}
	 */
	public static function extract_from_file( string $path ): array {
		$empty = [
			'found'      => false,
			'error'      => null,
			'user_id'    => null,
			'issued_at'  => null,
			'scale'      => null,
			'blocks'     => null,
			'sync_score' => null,
		];

		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return [ 'error' => __( 'Die PHP-Erweiterung GD fehlt; ohne sie lässt sich kein Screenshot lesen.', 'wm-prototype-nda-gate' ) ] + $empty;
		}

		if ( ! is_readable( $path ) ) {
			return [ 'error' => __( 'Datei nicht lesbar.', 'wm-prototype-nda-gate' ) ] + $empty;
		}

		$image = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a forensic tool reads the file it was handed; a corrupt image is answered, not thrown.
		if ( false === $image ) {
			return [ 'error' => __( 'Die Datei ist kein lesbares Bild (PNG, JPEG oder WebP).', 'wm-prototype-nda-gate' ) ] + $empty;
		}

		// No imagedestroy(): it has done nothing since PHP 8.0 and PHP 8.5 deprecates the call.
		return self::extract_from_image( $image );
	}

	/**
	 * The reading itself, on an open image.
	 *
	 * Finding the pattern means finding four numbers: where the cell grid starts (two), and which cell
	 * of the block that first cell is (two more). Searching all four at once costs a hundred passes
	 * over the image, so it runs in two stages. First the cell grid, from the edge energy of the
	 * screenshot: the pattern draws a step at every cell boundary, so summing the horizontal gradient
	 * of all columns that share an offset picks the grid out in a few thousand additions, and the
	 * horizontal and vertical offsets fall out independently. Then, with the grid fixed, all eighty
	 * positions the block can start at are tried in full.
	 *
	 * The scores only rank candidates. What decides is the MAC of the decoded block, so a grid found in
	 * a brick wall produces "not found" and never an account number.
	 *
	 * @param \GdImage $image Screenshot.
	 * @return array{found:bool, error:?string, user_id:?int, issued_at:?string, scale:?int, blocks:?int, sync_score:?float}
	 */
	public static function extract_from_image( $image ): array {
		$width  = imagesx( $image );
		$height = imagesy( $image );

		// A larger screenshot would put a prefix-sum table of millions of entries into PHP's memory.
		// Scaling it down by a whole factor keeps the cell grid intact — and a 2× or 3× screen lands on
		// the 1× grid this way, which is why the scales below are tried on the reduced image too.
		if ( $width * $height > self::MAX_PIXELS ) {
			$factor  = (int) ceil( sqrt( ( $width * $height ) / self::MAX_PIXELS ) );
			$resized = imagescale( $image, intdiv( $width, $factor ), intdiv( $height, $factor ), IMG_NEAREST_NEIGHBOUR );
			if ( false !== $resized ) {
				$image  = $resized;
				$width  = imagesx( $image );
				$height = imagesy( $image );
			}
		}

		$pass       = self::luminance_pass( $image, $width, $height );
		$candidates = [];

		foreach ( self::SCALES as $scale ) {
			$cell = self::CELL_PX * $scale;
			if ( $width < $cell * self::BLOCK_COLS || $height < $cell * self::BLOCK_ROWS ) {
				continue;
			}

			foreach ( self::grid_offsets( $pass['col_edges'], $cell ) as $dx ) {
				foreach ( self::grid_offsets( $pass['row_edges'], $cell ) as $dy ) {
					for ( $py = 0; $py < self::BLOCK_ROWS; $py++ ) {
						for ( $px = 0; $px < self::BLOCK_COLS; $px++ ) {
							$accumulated = self::accumulate( $pass['sums'], $width, $height, $cell, $dx + $px * $cell, $dy + $py * $cell );
							if ( $accumulated['blocks'] < 1 ) {
								continue;
							}

							// The reader cannot know whether the crop begins on an added or a subtracted
							// block, so the whole sum may come out inverted. The sync word says which way.
							$votes       = $accumulated['votes'];
							$correlation = self::sync_correlation( $votes );
							if ( $correlation < 0 ) {
								$votes       = array_map( static fn( float $vote ): float => -$vote, $votes );
								$correlation = -$correlation;
							}

							// Ranked by agreement times strength, not agreement alone. Eight sync bits
							// reach a correlation of 1.0 by chance often enough that ranking on it left
							// dozens of ties and pushed the true alignment out of the shortlist
							// (measured 2026-09-16: a photographic background scored 0.987 and was never
							// decoded). The right alignment is the one where the votes are also large.

							$magnitude = 0.0;
							foreach ( $votes as $vote ) {
								$magnitude += abs( $vote );
							}

							$candidates[] = [
								'correlation' => $correlation,
								'strength'    => $correlation * $magnitude,
								'votes'       => $votes,
								'blocks'      => $accumulated['blocks'],
								'scale'       => $scale,
							];
						}
					}
				}
			}
		}

		if ( [] === $candidates ) {
			return self::not_found( __( 'Das Bild ist kleiner als ein Block des Musters.', 'wm-prototype-nda-gate' ), null, null, null );
		}

		usort( $candidates, static fn( array $a, array $b ): int => $b['strength'] <=> $a['strength'] );

		foreach ( array_slice( $candidates, 0, self::CANDIDATES ) as $candidate ) {
			$bits    = array_map( static fn( float $vote ): int => $vote > 0 ? 1 : 0, $candidate['votes'] );
			$decoded = self::decode_bits( $bits );
			if ( null === $decoded ) {
				continue;
			}

			return [
				'found'      => true,
				'error'      => null,
				'user_id'    => $decoded['user_id'],
				'issued_at'  => $decoded['issued_at'],
				'scale'      => $candidate['scale'],
				'blocks'     => $candidate['blocks'],
				'sync_score' => round( $candidate['correlation'], 3 ),
			];
		}

		$best = $candidates[0];

		return self::not_found(
			__( 'Kein Wasserzeichen gefunden: die Prüfsumme keines gefundenen Rasters passt. Entweder trägt das Bild keines, es stammt aus einer anderen Installation, oder es wurde skaliert, gedreht oder zu stark komprimiert.', 'wm-prototype-nda-gate' ),
			$best['scale'],
			$best['blocks'],
			round( $best['correlation'], 3 )
		);
	}

	/**
	 * The answer when nothing verified. In one place, so a caller never has to tell two shapes apart.
	 *
	 * @return array{found:bool, error:?string, user_id:?int, issued_at:?string, scale:?int, blocks:?int, sync_score:?float}
	 */
	private static function not_found( string $error, ?int $scale, ?int $blocks, ?float $score ): array {
		return [
			'found'      => false,
			'error'      => $error,
			'user_id'    => null,
			'issued_at'  => null,
			'scale'      => $scale,
			'blocks'     => $blocks,
			'sync_score' => $score,
		];
	}

	/**
	 * The most likely starting offsets of the cell grid along one axis.
	 *
	 * Every cell boundary is a step in brightness, so the offsets where the pattern really starts
	 * collect more gradient than the others. Two are returned, not one: a table border or a sidebar
	 * edge can outscore the pattern, and testing a second offset costs a fraction of a second.
	 *
	 * @param array<int, float> $edges Gradient energy per column (or per row).
	 * @return array<int, int> Offsets to try, best first.
	 */
	private static function grid_offsets( array $edges, int $cell ): array {
		$scores = array_fill( 0, $cell, 0.0 );

		foreach ( $edges as $position => $energy ) {
			$scores[ $position % $cell ] += $energy;
		}

		arsort( $scores );

		return array_slice( array_keys( $scores ), 0, 2 );
	}

	/**
	 * Add up what every whole block says about every bit.
	 *
	 * Each block contributes the distance of each of its cells from that block's own mean. Subtracting
	 * the block mean is what removes the page: a headline or a photograph raises every cell under it
	 * together. What is left is the pattern plus whatever the content does inside one block — and the
	 * content does something different in every block while the pattern does the same in all of them,
	 * so the pattern adds up and the page cancels out. Sixty-three blocks of a 1440 × 900 screen are
	 * sixty-three votes on the same eighty bits.
	 *
	 * The first attempt compared each cell with its neighbouring cells instead. That works on an empty
	 * background and was measured failing over ordinary text on 2026-09-16: the ink between two lines
	 * of a paragraph moves a cell mean by tens of steps, the pattern moves it by seven.
	 *
	 * @return array{votes:array<int, float>, blocks:int}
	 */
	private static function accumulate( SplFixedArray $sums, int $width, int $height, int $cell, int $dx, int $dy ): array {
		$stride   = $width + 1;
		$block_w  = self::BLOCK_COLS * $cell;
		$block_h  = self::BLOCK_ROWS * $cell;
		$blocks_x = intdiv( $width - $dx, $block_w );
		$blocks_y = intdiv( $height - $dy, $block_h );
		$cells    = self::block_cells();

		if ( $blocks_x < 1 || $blocks_y < 1 ) {
			return [ 'votes' => array_fill( 0, $cells, 0.0 ), 'blocks' => 0 ];
		}

		$votes = array_fill( 0, $cells, 0.0 );

		for ( $by = 0; $by < $blocks_y; $by++ ) {
			for ( $bx = 0; $bx < $blocks_x; $bx++ ) {
				$means = [];
				$total = 0.0;

				for ( $index = 0; $index < $cells; $index++ ) {
					$x0 = $dx + $bx * $block_w + ( $index % self::BLOCK_COLS ) * $cell;
					$y0 = $dy + $by * $block_h + intdiv( $index, self::BLOCK_COLS ) * $cell;

					$mean            = self::cell_mean( $sums, $stride, $x0, $y0, $cell );
					$means[ $index ] = $mean;
					$total          += $mean;
				}

				// The carrier: blocks of one parity are added, blocks of the other subtracted. Whatever
				// the page does identically in both cancels; the pattern, painted inverted in the
				// subtracted blocks, adds up twice over.
				$sign       = ( self::CARRIER_INVERTS_ODD_BLOCKS && 1 === ( ( $bx + $by ) % 2 ) ) ? -1 : 1;
				$block_mean = $total / $cells;

				// Each block is weighted by how quiet it is. A block lying over a hero image swings by
				// a hundred steps of brightness and the pattern by seven; counted equally with the
				// blocks over plain text, it drowns them. Measured 2026-09-16 on a real browser
				// screenshot of the local demo home page: unweighted the reader found the grid and
				// failed the MAC, weighted it named the account.
				$variance = 0.0;
				for ( $index = 0; $index < $cells; $index++ ) {
					$variance += ( $means[ $index ] - $block_mean ) ** 2;
				}
				$weight = 1.0 / max( 1.0, $variance / $cells );

				for ( $index = 0; $index < $cells; $index++ ) {
					// Darker than the block it sits in is how a one bit was painted.
					$votes[ $index ] += $weight * $sign * ( $block_mean - $means[ $index ] );
				}
			}
		}

		return [ 'votes' => $votes, 'blocks' => $blocks_x * $blocks_y ];
	}

	/**
	 * How well the accumulated votes of the sync cells point the way the sync word does: +1 when every
	 * one of them agrees at full strength, −1 when every one disagrees.
	 *
	 * @param array<int, float> $votes Accumulated votes.
	 */
	private static function sync_correlation( array $votes ): float {
		$dot       = 0.0;
		$magnitude = 0.0;

		foreach ( self::int_to_bits( self::SYNC_WORD, self::SYNC_BITS ) as $index => $bit ) {
			$vote       = $votes[ $index ] ?? 0.0;
			$dot       += ( 1 === $bit ? 1 : -1 ) * $vote;
			$magnitude += abs( $vote );
		}

		return $magnitude > 0.0 ? $dot / $magnitude : 0.0;
	}

	/**
	 * One pass over the pixels for everything the reader needs: the prefix sums that make a cell mean
	 * four lookups instead of a loop, and the gradient energy per column and per row that locates the
	 * cell grid. Doing it in one pass keeps a second luminance matrix out of memory.
	 *
	 * @return array{sums:SplFixedArray, col_edges:array<int, float>, row_edges:array<int, float>}
	 */
	private static function luminance_pass( $image, int $width, int $height ): array {
		$stride    = $width + 1;
		$sums      = new SplFixedArray( $stride * ( $height + 1 ) );
		$col_edges = array_fill( 0, $width, 0.0 );
		$row_edges = array_fill( 0, $height, 0.0 );
		$previous  = array_fill( 0, $width, 0.0 );

		for ( $i = 0; $i < $stride; $i++ ) {
			$sums[ $i ] = 0;
		}

		for ( $y = 0; $y < $height; $y++ ) {
			$row          = ( $y + 1 ) * $stride;
			$prev         = $y * $stride;
			$running      = 0.0;
			$left         = null;
			$sums[ $row ] = 0;

			for ( $x = 0; $x < $width; $x++ ) {
				$rgb = imagecolorat( $image, $x, $y );
				// Rec. 601 luma: the pattern is a change in brightness, not in hue.
				$luma = ( 299 * ( ( $rgb >> 16 ) & 0xFF ) + 587 * ( ( $rgb >> 8 ) & 0xFF ) + 114 * ( $rgb & 0xFF ) ) / 1000;

				$running              += $luma;
				$sums[ $row + $x + 1 ] = $sums[ $prev + $x + 1 ] + $running;

				// The gradient at this pixel belongs to the column and the row it steps into.
				if ( null !== $left ) {
					$col_edges[ $x ] += abs( $luma - $left );
				}
				if ( $y > 0 ) {
					$row_edges[ $y ] += abs( $luma - $previous[ $x ] );
				}

				$left           = $luma;
				$previous[ $x ] = $luma;
			}
		}

		return [
			'sums'      => $sums,
			'col_edges' => $col_edges,
			'row_edges' => $row_edges,
		];
	}

	/**
	 * Mean luminance of one cell of the grid.
	 */
	private static function cell_mean( SplFixedArray $sums, int $stride, int $x0, int $y0, int $cell ): float {
		$x1 = $x0 + $cell;
		$y1 = $y0 + $cell;

		$total = $sums[ $y1 * $stride + $x1 ]
			- $sums[ $y0 * $stride + $x1 ]
			- $sums[ $y1 * $stride + $x0 ]
			+ $sums[ $y0 * $stride + $x0 ];

		return $total / ( $cell * $cell );
	}

	/**
	 * An integer as bits, most significant first.
	 *
	 * @return array<int, int>
	 */
	public static function int_to_bits( int $value, int $length ): array {
		$bits = [];
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}

		return $bits;
	}

	/**
	 * Bits back into an integer, most significant first.
	 *
	 * @param array<int, int> $bits Bits.
	 */
	public static function bits_to_int( array $bits ): int {
		$value = 0;
		foreach ( $bits as $bit ) {
			$value = ( $value << 1 ) | ( $bit ? 1 : 0 );
		}

		return $value;
	}
}
