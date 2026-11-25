<?php
/**
 * WM Suite Prototype NDA Gate — Retention
 *
 * Applies `wm_nda_retention_days` to the acceptance register. Until 2026-09-17 that setting was
 * written by activation, by the seeder and by the settings form, and read in exactly one place —
 * to render itself back into that form. Nothing removed anything.
 *
 * ## Why this pseudonymises instead of deleting
 *
 * The register is a hash chain whose whole value is that a recorded acceptance cannot be removed.
 * Deleting a row breaks verification for every row after it, so a retention policy built on
 * deletion would make the register report itself as tampered — and `WM_NDA_Database` deliberately
 * offers no re-signing.
 *
 * The retention window limits how long the directly identifying fields are kept: `user_name`,
 * `user_email`, `user_agent` go, the row and its signed fields stay. Whether that is enough for a
 * given site is the operator's assessment; the plugin makes no legal claim about it (until 1.3.3
 * this comment cited a statute as permission; Arnold, 21.11.2026).
 *
 * ## Why no residual fingerprints are needed here
 *
 * Unlike the Activity Sentinel's log, this register's signature covers
 * `prev_hash|user_id|term_id|term_hash|timestamp|ip_hmac[|evidence_hash]`
 * (`WM_NDA_Database::generate_signature_hash()`) — **not** the name, the address or the user
 * agent. Rewriting those three leaves every signature recomputing exactly as before, which is the
 * same reason the privacy eraser can rewrite them.
 *
 * `ip_hmac` is signed and is therefore never touched, by this pass or by the eraser. It is already
 * a day-salted pseudonym.
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Retention {

	/** The daily cron hook. */
	public const CRON_HOOK = 'wm_nda_apply_retention';

	/** Values written over the identifying fields. */
	public const NEUTRAL_NAME  = '[Aufbewahrungsfrist abgelaufen]';
	public const NEUTRAL_AGENT = '[Anonymisiert]';

	/** Most rows one pass will touch, so a first run on an old register stays repeatable. */
	public const BATCH_LIMIT = 500;

	/** Registers the daily event; the schedule follows the setting. */
	public static function init(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run_scheduled' ] );
		self::sync_schedule();
	}

	/** A retention of 0 means "keep everything", so the event is removed rather than left running. */
	public static function sync_schedule(): void {
		$days      = self::configured_days();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $days > 0 && false === $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			return;
		}

		if ( 0 === $days && false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::CRON_HOOK );
		}
	}

	/** Clears the event; called from deactivation. */
	public static function unschedule(): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * The configured window in days.
	 *
	 * The settings form offers 30 to 3650. Anything below that — including a 0 written with
	 * `wp option update` — is read as "keep everything", because when the stored value is missing
	 * or out of range the default is never the destructive one.
	 */
	public static function configured_days(): int {
		$days = (int) get_option( 'wm_nda_retention_days', 90 );
		if ( $days < 30 ) {
			return 0;
		}
		return min( 3650, $days );
	}

	/** The cron callback. */
	public static function run_scheduled(): void {
		if ( 0 === self::configured_days() ) {
			return;
		}
		self::apply();
	}

	/**
	 * Neutralises the identifying fields of acceptances past the window.
	 *
	 * @param bool $dry_run When true, measures and reports without writing anything.
	 * @return array{applied:int,candidates:int,remaining:int,cutoff:string,reason:string|null}
	 */
	public static function apply( bool $dry_run = false ): array {
		global $wpdb;

		$days = self::configured_days();
		if ( 0 === $days ) {
			return [
				'applied'    => 0,
				'candidates' => 0,
				'remaining'  => 0,
				'cutoff'     => '',
				'reason'     => 'retention_off',
			];
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = WM_NDA_Database::acceptances_table();

		// Candidates: accepted before the cutoff and still carrying something to neutralise. The
		// second half is what makes the pass idempotent — a second run finds nothing.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT acceptance_id FROM %i
				  WHERE accepted_at < %s
				    AND ( user_name <> %s OR user_agent <> %s )
				  ORDER BY acceptance_id ASC
				  LIMIT %d',
				$table,
				$cutoff,
				self::NEUTRAL_NAME,
				self::NEUTRAL_AGENT,
				self::BATCH_LIMIT + 1
			)
		);
		// phpcs:enable

		$rows      = is_array( $rows ) ? $rows : [];
		$remaining = count( $rows ) > self::BATCH_LIMIT ? 1 : 0;
		$rows      = array_slice( $rows, 0, self::BATCH_LIMIT );

		if ( [] === $rows || $dry_run ) {
			return [
				'applied'    => 0,
				'candidates' => count( $rows ),
				'remaining'  => $remaining,
				'cutoff'     => $cutoff,
				'reason'     => $dry_run ? 'dry_run' : null,
			];
		}

		$applied = 0;
		foreach ( $rows as $row ) {
			$acceptance_id = (int) $row->acceptance_id;
			// ip_hmac is NOT in this list: it is part of the signed payload, and rewriting it is
			// what made the register report every erased row as tampered until 2026-09-14.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's own table.
			$updated = $wpdb->update(
				$table,
				[
					'user_name'  => self::NEUTRAL_NAME,
					'user_email' => sprintf( 'retained-%d@privacy.local', $acceptance_id ),
					'user_agent' => self::NEUTRAL_AGENT,
				],
				[ 'acceptance_id' => $acceptance_id ]
			);
			if ( false !== $updated ) {
				$applied++;
			}
		}

		return [
			'applied'    => $applied,
			'candidates' => count( $rows ),
			'remaining'  => $remaining,
			'cutoff'     => $cutoff,
			'reason'     => null,
		];
	}
}
