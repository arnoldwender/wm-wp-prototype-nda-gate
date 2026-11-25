<?php
/**
 * WM Suite Prototype NDA Gate — click-wrap interaction record
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the browser reported while the agreement was on screen, reduced to counted facts.
 *
 * A click-wrap record answers one question: did a person operate this dialog, or did something
 * post the acceptance? It cannot answer who that person was — the account did that — and it is
 * asserted by the browser, so a client that lies produces a record that lies. What the plugin adds
 * is that nobody can change it afterwards: the canonical JSON of this record is part of the signed
 * payload of its acceptance (WM_NDA_Database::generate_signature_hash()), so editing the column
 * breaks the chain from that row on.
 *
 * Only whole numbers and two closed enumerations are stored. A float would encode differently
 * depending on serialize_precision and the signature would stop matching its own row on another
 * server; pressure therefore travels as per mille.
 *
 * No coordinates and no timing series are kept. A pointer path is behavioural biometry and this
 * plugin has no purpose that needs one; the counted facts carry the click-wrap question without
 * collecting a movement profile.
 */
final class WM_NDA_Evidence {

	/** Stored with every record; a later format can be told apart without guessing. */
	public const SCHEMA = 1;

	/** Longest JSON accepted from a browser. Anything larger is not a click-wrap record. */
	public const MAX_INPUT_BYTES = 2048;

	/** What generated the accept event. "unknown" is what a browser without Pointer Events reports. */
	public const POINTER_TYPES = [ 'mouse', 'pen', 'touch', 'keyboard', 'unknown' ];

	/** Whether the button was clicked or operated from the keyboard. */
	public const ACCEPT_INPUTS = [ 'pointer', 'keyboard' ];

	/**
	 * Every stored number, with the closed range it has to fall into. A value outside its range is
	 * dropped rather than clamped: a clamped number looks like a measurement and is not one.
	 *
	 * @var array<string, array{0:int, 1:int}>
	 */
	public const NUMERIC_FIELDS = [
		'move_samples'          => [ 0, 1000000 ],
		'path_px'               => [ 0, 100000000 ],
		'pressure_max_permille' => [ 0, 1000 ],
		'pressure_samples'      => [ 0, 1000000 ],
		'dwell_ms'              => [ 0, 86400000 ],
		'check_to_accept_ms'    => [ 0, 86400000 ],
		'scroll_pct'            => [ 0, 100 ],
		'viewport_w'            => [ 0, 20000 ],
		'viewport_h'            => [ 0, 20000 ],
		'tz_offset_min'         => [ -1440, 1440 ],
	];

	/**
	 * A mouse has no pressure sensor: the Pointer Events specification has it report exactly 0.5
	 * while a button is down and 0 otherwise. Reading that as "half pressure" would turn a constant
	 * into a measurement, so it is named for what it is wherever the record is shown.
	 */
	public const MOUSE_PRESSURE_PERMILLE = 500;

	/**
	 * Reduce what a browser submitted to the fields above.
	 *
	 * @param mixed $raw JSON string or array from the request.
	 * @return array Canonical record, or [] when nothing usable was submitted.
	 */
	public static function sanitize( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			if ( '' === trim( $raw ) || strlen( $raw ) > self::MAX_INPUT_BYTES ) {
				return [];
			}
			$raw = json_decode( $raw, true );
		}

		// Readability, not a gate: the loop below reads nothing out of a non-array either, and the
		// empty-record check at the end catches the rest. Removing it changes no result, which is why
		// tests/mutations.php carries no entry for it.
		if ( ! is_array( $raw ) || [] === $raw ) {
			return [];
		}

		$record = [];

		foreach ( self::NUMERIC_FIELDS as $field => [ $min, $max ] ) {
			if ( ! isset( $raw[ $field ] ) || ! is_numeric( $raw[ $field ] ) ) {
				continue;
			}
			// A float from JSON is truncated, not rounded: the browser sends whole numbers, and a
			// fractional value is a client that did not follow the contract.
			$value = (int) $raw[ $field ];
			if ( $value >= $min && $value <= $max ) {
				$record[ $field ] = $value;
			}
		}

		if ( isset( $raw['pointer_type'] ) && in_array( $raw['pointer_type'], self::POINTER_TYPES, true ) ) {
			$record['pointer_type'] = (string) $raw['pointer_type'];
		}

		if ( isset( $raw['accept_input'] ) && in_array( $raw['accept_input'], self::ACCEPT_INPUTS, true ) ) {
			$record['accept_input'] = (string) $raw['accept_input'];
		}

		if ( [] === $record ) {
			return [];
		}

		// Set here, never taken from the request: the schema says how this server read the record.
		$record['schema'] = self::SCHEMA;

		return $record;
	}

	/**
	 * The stored form of a record: keys in one order, so the same record always hashes the same.
	 */
	public static function canonical_json( array $record ): string {
		if ( [] === $record ) {
			return '';
		}

		ksort( $record );

		return (string) wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * What the signature covers: the SHA-256 of the stored JSON, or '' when there is no record.
	 *
	 * Derived from the column on every read instead of being stored beside it. A stored hash can be
	 * left alone while the record it describes is rewritten; this one cannot.
	 */
	public static function fingerprint( string $canonical_json ): string {
		return '' === $canonical_json ? '' : hash( 'sha256', $canonical_json );
	}

	/**
	 * The record of a stored row, or [] when the row carries none (every row written before this
	 * column existed, and every acceptance whose browser sent nothing).
	 */
	public static function from_row( object $row ): array {
		$json = (string) ( $row->interaction_json ?? '' );
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * The record as label/value pairs for the admin screens and the exports.
	 *
	 * @return array<int, array{label:string, value:string}>
	 */
	public static function describe( array $record ): array {
		if ( [] === $record ) {
			return [];
		}

		$rows    = [];
		$pointer = (string) ( $record['pointer_type'] ?? 'unknown' );

		$pointer_labels = [
			'mouse'    => __( 'Maus', 'wm-prototype-nda-gate' ),
			'pen'      => __( 'Stift', 'wm-prototype-nda-gate' ),
			'touch'    => __( 'Touch', 'wm-prototype-nda-gate' ),
			'keyboard' => __( 'Tastatur', 'wm-prototype-nda-gate' ),
			'unknown'  => __( 'nicht erkannt', 'wm-prototype-nda-gate' ),
		];

		$rows[] = [
			'label' => __( 'Eingabegerät', 'wm-prototype-nda-gate' ),
			'value' => $pointer_labels[ $pointer ] ?? $pointer_labels['unknown'],
		];

		if ( isset( $record['accept_input'] ) ) {
			$rows[] = [
				'label' => __( 'Bestätigt per', 'wm-prototype-nda-gate' ),
				'value' => 'keyboard' === $record['accept_input']
					? __( 'Tastatur', 'wm-prototype-nda-gate' )
					: __( 'Zeigegerät', 'wm-prototype-nda-gate' ),
			];
		}

		if ( isset( $record['dwell_ms'] ) ) {
			$rows[] = [
				'label' => __( 'Zeit im Dialog', 'wm-prototype-nda-gate' ),
				'value' => self::format_seconds( (int) $record['dwell_ms'] ),
			];
		}

		if ( isset( $record['check_to_accept_ms'] ) ) {
			$rows[] = [
				'label' => __( 'Zwischen Häkchen und Bestätigung', 'wm-prototype-nda-gate' ),
				'value' => self::format_seconds( (int) $record['check_to_accept_ms'] ),
			];
		}

		if ( isset( $record['scroll_pct'] ) ) {
			$rows[] = [
				'label' => __( 'Gelesener Anteil des Textfensters', 'wm-prototype-nda-gate' ),
				/* translators: %d: percentage of the scrollable agreement text that was scrolled through */
				'value' => sprintf( __( '%d %% durchgeblättert', 'wm-prototype-nda-gate' ), (int) $record['scroll_pct'] ),
			];
		}

		if ( isset( $record['move_samples'], $record['path_px'] ) ) {
			$rows[] = [
				'label' => __( 'Zeigerbewegung', 'wm-prototype-nda-gate' ),
				'value' => sprintf(
					/* translators: 1: number of pointer samples, 2: travelled distance in CSS pixels */
					__( '%1$d Messpunkte, %2$d px Weg', 'wm-prototype-nda-gate' ),
					(int) $record['move_samples'],
					(int) $record['path_px']
				),
			];
		}

		if ( isset( $record['pressure_max_permille'] ) ) {
			$rows[] = [
				'label' => __( 'Höchster Druckwert', 'wm-prototype-nda-gate' ),
				'value' => self::describe_pressure( (int) $record['pressure_max_permille'], $pointer ),
			];
		}

		if ( isset( $record['viewport_w'], $record['viewport_h'] ) ) {
			$rows[] = [
				'label' => __( 'Sichtfenster', 'wm-prototype-nda-gate' ),
				'value' => sprintf( '%d × %d px', (int) $record['viewport_w'], (int) $record['viewport_h'] ),
			];
		}

		if ( isset( $record['tz_offset_min'] ) ) {
			$rows[] = [
				'label' => __( 'Zeitzonenversatz des Geräts', 'wm-prototype-nda-gate' ),
				/* translators: %s: signed offset from UTC in minutes, as reported by the browser */
				'value' => sprintf( __( '%s Minuten gegenüber UTC', 'wm-prototype-nda-gate' ), sprintf( '%+d', (int) $record['tz_offset_min'] ) ),
			];
		}

		return $rows;
	}

	/**
	 * One line for a table cell: the input device and how long the dialog was open.
	 */
	public static function short_summary( array $record ): string {
		if ( [] === $record ) {
			return __( 'Kein Interaktionsnachweis', 'wm-prototype-nda-gate' );
		}

		$described = self::describe( $record );
		$parts     = [];
		foreach ( array_slice( $described, 0, 2 ) as $row ) {
			$parts[] = $row['value'];
		}

		return implode( ' · ', $parts );
	}

	/**
	 * A duration in seconds with one decimal; milliseconds say more precision than a browser timer has.
	 */
	private static function format_seconds( int $milliseconds ): string {
		return sprintf(
			/* translators: %s: duration in seconds with one decimal */
			__( '%s s', 'wm-prototype-nda-gate' ),
			number_format_i18n( $milliseconds / 1000, 1 )
		);
	}

	/**
	 * Pressure, named for what the hardware can say. Pointer Events report exactly 0.5 for a device
	 * without a pressure sensor while a button is down (W3C Pointer Events, PointerEvent.pressure),
	 * so 500 per mille from a mouse is the constant, not a measurement.
	 */
	private static function describe_pressure( int $permille, string $pointer_type ): string {
		if ( 'mouse' === $pointer_type || ( 'unknown' === $pointer_type && self::MOUSE_PRESSURE_PERMILLE === $permille ) ) {
			return __( 'kein Drucksensor (Zeigegerät meldet den Festwert 0,5)', 'wm-prototype-nda-gate' );
		}

		return sprintf(
			/* translators: %s: pressure between 0 and 1 with two decimals */
			__( '%s von 1,0', 'wm-prototype-nda-gate' ),
			number_format_i18n( $permille / 1000, 2 )
		);
	}
}
