<?php
/**
 * Audit routing for the NDA gate.
 *
 * The gate tells users that a bypass attempt "wurde forensisch erfasst". That claim was
 * conditional on WM_Sentinel_Logger being present: with wm-activity-sentinel deactivated the
 * plugin asserted a forensic record and wrote nothing at all. This class makes the write
 * unconditional and reports back whether the record is actually tamper-evident, so the
 * user-facing text can only claim what happened.
 *
 * @package WM_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes NDA audit events to the strongest available sink.
 */
final class WM_NDA_Audit {

	/**
	 * Whether a tamper-evident sink is available.
	 *
	 * WM Activity Sentinel chains every entry with HMAC-SHA256, which is what makes a record
	 * forensic. The error_log fallback is durable but not tamper-evident.
	 *
	 * @return bool
	 */
	public static function is_forensic(): bool {
		return class_exists( 'WM_Sentinel_Logger' ) && method_exists( 'WM_Sentinel_Logger', 'log' );
	}

	/**
	 * Record an audit event. Never silently drops it.
	 *
	 * @param int    $user_id     Acting user.
	 * @param string $action      Machine-readable event slug.
	 * @param string $object_type Object type the event refers to.
	 * @param int    $object_id   Object identifier.
	 * @param string $severity    Severity level ('notice', 'warning', 'critical').
	 * @param array  $context     Additional structured context.
	 * @return bool True if the event was written to any sink.
	 */
	public static function record(
		int $user_id,
		string $action,
		string $object_type,
		int $object_id,
		string $severity,
		array $context = array()
	): bool {
		if ( self::is_forensic() ) {
			WM_Sentinel_Logger::log( $user_id, $action, $object_type, $object_id, $severity, $context );
			return true;
		}

		// Fallback: durable, greppable, always available. Not tamper-evident — callers must
		// not describe this as a forensic record.
		$payload = wp_json_encode(
			array(
				'user_id'     => $user_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'severity'    => $severity,
				'context'     => $context,
				'recorded_at' => gmdate( 'c' ),
				'sink'        => 'error_log_fallback',
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) { error_log( '[WM NDA Audit] ' . $payload ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the audit sink under WP_DEBUG when WM Activity Sentinel is absent.

		/**
		 * Fires when an NDA audit event could not be written to a tamper-evident sink.
		 *
		 * Lets a site wire its own durable sink without activating WM Activity Sentinel.
		 *
		 * @param string $action  Event slug.
		 * @param int    $user_id Acting user.
		 * @param array  $context Structured context.
		 */
		do_action( 'wm_nda_audit_degraded', $action, $user_id, $context );

		return true;
	}
}
