<?php
/**
 * WM Suite Prototype NDA Gate — Default agreement text and gate strings
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_I18n {

	/**
	 * Default agreement text in German: a sample without legal review that cites no statute and names no
	 * vendor (Arnold, 21.11.2026). Section 3 lists what the register stores for each acceptance. Operators
	 * publish their own reviewed text under «Versionen»; published versions keep their text and signatures.
	 */
	public static function get_default_legal_content( string $project_name = '' ): string {
		if ( empty( $project_name ) ) {
			$project_name = get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) . ' (Prototyp)' );
		}

		return '<h3>1. Gegenstand</h3>
<p>Diese Vereinbarung betrifft alle Inhalte, die im Projekt <strong>' . esc_html( $project_name ) . '</strong> zugänglich sind, insbesondere Software, Benutzeroberflächen, Datenmodelle und Dokumentation (die „vertraulichen Informationen“). Sie gehören dem Betreiber dieser Website oder dem jeweiligen Rechteinhaber.</p>

<h3>2. Pflichten</h3>
<p>Sie verpflichten sich, die vertraulichen Informationen</p>
<ul>
    <li>nicht an Dritte weiterzugeben;</li>
    <li>nicht zu kopieren, herunterzuladen oder zu exportieren, soweit es für die Nutzung des Prototyps nicht erforderlich ist;</li>
    <li>nicht ohne schriftliche Zustimmung des Betreibers kommerziell zu nutzen oder nachzubauen.</li>
</ul>
<p>Gesetzlich zwingende Rechte bleiben unberührt.</p>

<h3>3. Protokollierung</h3>
<p>Ihre Zustimmung wird mit Benutzer-ID, E-Mail-Adresse, Anzeigename, User-Agent, Zeitpunkt (UTC), der Prüfsumme dieses Textes, einem Pseudonym Ihrer IP-Adresse und dem Interaktionsnachweis gespeichert. Die Einträge werden miteinander verkettet signiert.</p>

<h3>4. Verstöße</h3>
<p>Bei einem Verstoß kann der Betreiber Ihren Zugang sperren.</p>';
	}

	/**
	 * Login checkbox text: states what the user does (read and agree) and claims no legal consequence.
	 */
	public static function get_login_checkbox_label(): string {
		return __( 'Ich habe die Vertraulichkeitsvereinbarung (NDA) für dieses Prototyp-System gelesen und stimme ihr zu.', 'wm-prototype-nda-gate' );
	}

	/**
	 * Gate header text
	 */
	public static function get_gate_heading(): string {
		return __( 'Zwingende Vertraulichkeitsverpflichtung (NDA)', 'wm-prototype-nda-gate' );
	}

	/**
	 * Accept button text
	 */
	public static function get_accept_button_text(): string {
		return __( 'NDA akzeptieren & fortfahren', 'wm-prototype-nda-gate' );
	}

	/**
	 * Decline / Logout button text
	 */
	public static function get_decline_button_text(): string {
		return __( 'Ablehnen & Abmelden', 'wm-prototype-nda-gate' );
	}

	/**
	 * Login error when valid credentials arrive without the box for a term the user has not signed.
	 * Since the checkbox is no longer `required` in the browser this is the normal way to learn that a
	 * signature is due, so it instructs instead of accusing; the attempt is still logged.
	 *
	 * $is_forensic has no default: the text may only claim the record its caller measured (reader audit D8).
	 */
	public static function get_login_required_text( bool $is_forensic ): string {
		$base = __( 'Bitte bestätigen Sie die Vertraulichkeitsvereinbarung (NDA) mit dem Kästchen unter dem Passwort, um sich anzumelden. Für die aktuelle Fassung liegt von Ihnen noch keine Bestätigung vor.', 'wm-prototype-nda-gate' );

		// Only claim a forensic record when a tamper-evident sink actually took it.
		return $base . ' ' . ( $is_forensic
			? __( 'Der Anmeldeversuch wurde forensisch erfasst.', 'wm-prototype-nda-gate' )
			: __( 'Der Anmeldeversuch wurde protokolliert.', 'wm-prototype-nda-gate' ) );
	}

	/**
	 * REST and admin-ajax answer for a logged-in user without an acceptance of the active version. Nothing is
	 * recorded on these paths, so the text claims no record.
	 */
	public static function get_consent_required_text(): string {
		return __( 'Bitte bestätigen Sie zuerst die aktuelle Vertraulichkeitsvereinbarung (NDA).', 'wm-prototype-nda-gate' );
	}
}
