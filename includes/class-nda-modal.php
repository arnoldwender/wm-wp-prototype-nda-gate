<?php
/**
 * WM Suite Prototype NDA Gate — signature modal (dialog role, focus trap, keyboard operation)
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Modal {

	/**
	 * Render the full-screen accessible NDA Gate Modal
	 */
	public static function render_gate_modal( ?object $term = null, bool $is_admin_gate = false ): void {
		if ( null === $term ) {
			$term = WM_NDA_Database::get_active_term();
		}

		if ( ! $term ) {
			return;
		}

		$current_user = wp_get_current_user();
		$user_id = $current_user->exists() ? (int) $current_user->ID : 0;
		$user_email = $current_user->exists() ? $current_user->user_email : '';
		$user_name = $current_user->exists() ? $current_user->display_name : '';
		$project_name = get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) . ' (Prototyp)' );
		$nonce = wp_create_nonce( 'wp_rest' );
		$rest_url = esc_url_raw( rest_url( 'wm-nda/v1/accept' ) );
		$logout_url = wp_logout_url( home_url() );

		?>
		<div id="wm-nda-gate-overlay" class="wm-nda-gate-overlay <?php echo $is_admin_gate ? 'wm-nda-gate-fullscreen' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="wm-nda-title" aria-describedby="wm-nda-subtitle" data-term-id="<?php echo esc_attr( (string) $term->term_id ); ?>" data-rest-url="<?php echo esc_attr( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-user-id="<?php echo esc_attr( (string) $user_id ); ?>" data-user-email="<?php echo esc_attr( $user_email ); ?>" data-user-name="<?php echo esc_attr( $user_name ); ?>" data-msg-success="<?php esc_attr_e( 'Zustimmung gespeichert. Weiterleitung …', 'wm-prototype-nda-gate' ); ?>" data-msg-error="<?php esc_attr_e( 'Fehler bei der Signaturübermittlung.', 'wm-prototype-nda-gate' ); ?>" data-msg-network="<?php esc_attr_e( 'Netzwerkfehler:', 'wm-prototype-nda-gate' ); ?>">
			
			<div class="wm-nda-gate-card" tabindex="-1">
				
				<!-- Modal Header -->
				<header class="wm-nda-gate-header">
					<div class="wm-nda-gate-badge">
						<span class="dashicons dashicons-shield" aria-hidden="true"></span>
						<?php // Was "GeschGehG & UrhG Schutzsystem": a statute-named label for a legal effect the plugin does not assess. ?>
						<span><?php esc_html_e( 'Vertraulichkeitsvereinbarung', 'wm-prototype-nda-gate' ); ?></span>
					</div>
					<h2 id="wm-nda-title" class="wm-nda-gate-title">
						<?php echo esc_html( WM_NDA_I18n::get_gate_heading() ); ?>
					</h2>
					<p id="wm-nda-subtitle" class="wm-nda-gate-subtitle">
						<?php echo esc_html( $term->term_title ); ?> &mdash; <strong><?php echo esc_html( $project_name ); ?></strong> (v<?php echo esc_html( $term->term_version ); ?>)
					</p>
				</header>

				<!-- Legal Terms Content Container (Scrollable with Keyboard Support) -->
				<div class="wm-nda-gate-body" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Vertragstext der Geheimhaltungsvereinbarung', 'wm-prototype-nda-gate' ); ?>">
					<div class="wm-nda-legal-notice">
						<span class="dashicons dashicons-lock" aria-hidden="true"></span>
						<p>
							<?php // Until 1.3.3 this cited § 2 GeschGehG and § 69e UrhG; it now says what the click stores (Arnold, 21.11.2026). ?>
							<?php esc_html_e( 'Hinweis: Der Zugang zu diesem Prototyp setzt Ihre Zustimmung zur folgenden Vereinbarung voraus. Gespeichert werden Benutzer-ID, E-Mail-Adresse, Anzeigename, User-Agent, Zeitpunkt, die Prüfsumme des Textes, ein Pseudonym Ihrer IP-Adresse und der Interaktionsnachweis.', 'wm-prototype-nda-gate' ); ?>
						</p>
					</div>
					
					<div class="wm-nda-legal-text">
						<?php echo wp_kses_post( $term->term_content ); ?>
					</div>

					<div class="wm-nda-hash-signature-preview">
						<span class="wm-nda-hash-label"><?php esc_html_e( 'Dokumenten-Prüfsumme (SHA-256):', 'wm-prototype-nda-gate' ); ?></span>
						<code class="wm-nda-hash-value"><?php echo esc_html( $term->term_hash ); ?></code>
					</div>
				</div>

				<!-- Acceptance Confirmation & Action Footer -->
				<footer class="wm-nda-gate-footer">
					<div class="wm-nda-checkbox-wrapper">
						<label for="wm-nda-modal-confirm" class="wm-nda-checkbox-label">
							<input type="checkbox" id="wm-nda-modal-confirm" name="wm_nda_modal_confirm" value="1" required aria-required="true" />
							<span class="wm-nda-checkbox-custom" aria-hidden="true"></span>
							<span class="wm-nda-checkbox-text">
								<?php echo esc_html( WM_NDA_I18n::get_login_checkbox_label() ); ?>
							</span>
						</label>
					</div>

					<div id="wm-nda-status-message" class="wm-nda-status-message" aria-live="polite"></div>

					<div class="wm-nda-buttons-row">
						<a href="<?php echo esc_url( $logout_url ); ?>" class="button wm-nda-btn-decline" id="wm-nda-btn-decline">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php echo esc_html( WM_NDA_I18n::get_decline_button_text() ); ?>
						</a>
						
						<button type="button" class="button button-primary wm-nda-btn-accept" id="wm-nda-btn-accept" disabled aria-disabled="true">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<span class="wm-nda-btn-text"><?php echo esc_html( WM_NDA_I18n::get_accept_button_text() ); ?></span>
							<span class="wm-nda-spinner" aria-hidden="true"></span>
						</button>
					</div>
				</footer>

			</div>
		</div>
		<?php
	}
}
