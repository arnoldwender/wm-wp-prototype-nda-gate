<?php
/**
 * Literal mutations for tests/run-mutations.sh: each entry undoes one correction (2026-09-14 gate, 2026-09-15
 * reader audit); the standalone suite must report a failure for every one. Search strings must occur exactly once.
 *
 * @package WM_Prototype_NDA_Gate
 */

return [
	[
		'Admin redirect removed (the page renders under the modal again)',
		[
			[ 'includes/class-nda-interceptor.php', "wp_safe_redirect( \$this->gate_url( \$this->current_url() ) );\n\t\texit;", 'return;' ],
		],
	],
	[
		'pagenow check removed (index.php?page=wm-nda-gate unlocks the dashboard)',
		[
			[ 'includes/class-nda-interceptor.php', "if ( 'admin.php' !== (string) ( \$GLOBALS['pagenow'] ?? '' ) ) {\n\t\t\treturn false;\n\t\t}", '' ],
		],
	],
	[
		'Frontend gate removed',
		[
			[ 'includes/class-nda-interceptor.php', 'wp_safe_redirect( $this->gate_url( $current ) );', 'return;' ],
		],
	],
	[
		'D1 every term switched off before the insert that can fail',
		[
			[ 'includes/class-nda-database.php', ' || mb_strlen( $version ) > self::MAX_VERSION_LENGTH', '' ],
			[ 'includes/class-nda-database.php', "\t\tif ( \$term_id <= 0 ) {\n", "\t\t\$wpdb->query( \$wpdb->prepare( 'UPDATE %i SET is_active = 0', \$terms_table ) );\n\t\tif ( \$term_id <= 0 ) {\n" ],
		],
	],
	[
		'D1 text hash taken before sanitising',
		[
			[ 'includes/class-nda-database.php', '$term_hash = hash( \'sha256\', $content );', '$term_hash = hash( \'sha256\', $new_content );' ],
		],
	],
	[
		'D1 versions screen ignores the result and says "bumped"',
		[
			[ 'includes/class-nda-admin.php', "\$bumped = WM_NDA_Database::bump_version( \$version, \$title, \$content, get_current_user_id() );\n\t\t\twp_safe_redirect( add_query_arg( [ 'page' => 'wm-nda-versions', false === \$bumped ? 'bump_failed' : 'bumped' => '1' ], admin_url( 'admin.php' ) ) );", "WM_NDA_Database::bump_version( \$version, \$title, \$content, get_current_user_id() );\n\t\t\twp_safe_redirect( add_query_arg( [ 'page' => 'wm-nda-versions', 'bumped' => '1' ], admin_url( 'admin.php' ) ) );" ],
		],
	],
	[
		'D2 tip seal not checked',
		[
			[ 'includes/class-nda-database.php', '! hash_equals( self::tip_mac( (string) $tip[\'hash\'], (int) $tip[\'count\'] ), (string) $tip[\'mac\'] )', 'false' ],
		],
	],
	[
		'D2 a missing tip passes',
		[
			[ 'includes/class-nda-database.php', '$tip_error = self::tip_error( $tip, $walk[\'last_hash\'], count( $rows ) );', '$tip_error = is_array( $tip ) ? self::tip_error( $tip, $walk[\'last_hash\'], count( $rows ) ) : null;' ],
		],
	],
	[
		'D2 tip hash and count not compared with the rows',
		[
			[ 'includes/class-nda-database.php', 'if ( ! hash_equals( (string) $tip[\'hash\'], $last_hash ) || (int) $tip[\'count\'] !== $row_count ) {', 'if ( false ) {' ],
		],
	],
	[
		'D2 an unsealed tip passes',
		[
			[ 'includes/class-nda-database.php', 'if ( ! isset( $tip[\'key\'], $tip[\'mac\'] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D2 any row opens the gates',
		[
			[ 'includes/class-nda-database.php', "if ( self::row_signature_valid( \$row ) ) {\n\t\t\t\treturn (int) \$row->acceptance_id;", "if ( true ) {\n\t\t\t\treturn (int) \$row->acceptance_id;" ],
		],
	],
	[
		'D2 migration seals a tip that does not match the rows',
		[
			[ 'includes/class-nda-database.php', 'if ( $walk[\'checked\'] > 0 && ( ! is_array( $tip ) || ! hash_equals( (string) ( $tip[\'hash\'] ?? \'\' ), $walk[\'last_hash\'] ) || (int) ( $tip[\'count\'] ?? -1 ) !== count( $rows ) ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D5 wp-config-sample placeholder accepted as a key',
		[
			[ 'includes/class-nda-database.php', 'return \'\' !== $key && self::KEY_PLACEHOLDER !== $key;', 'return \'\' !== $key;' ],
		],
	],
	[
		'D5 a different key reported as an altered row',
		[
			[ 'includes/class-nda-database.php', 'self::any_signature_valid( $rows ) ? \'signature\' : \'key\';', '\'signature\';' ],
		],
	],
	[
		'D3 redirect_to not encoded (redirect loop)',
		[
			[ 'includes/class-nda-interceptor.php', '$args[\'redirect_to\'] = rawurlencode( $redirect_to );', '$args[\'redirect_to\'] = $redirect_to;' ],
		],
	],
	[
		'D4 writes without the register lock',
		[
			[ 'includes/class-nda-database.php', "if ( ! self::acquire_register_lock() ) {\n\t\t\treturn false;\n\t\t}", "if ( false ) {\n\t\t\treturn false;\n\t\t}" ],
		],
	],
	[
		'D8 REST refusal claims a forensic record again',
		[
			[ 'includes/class-nda-interceptor.php', 'esc_html( WM_NDA_I18n::get_consent_required_text() ),', 'esc_html( \'Zugriff verweigert (Sicherheitsverstoß): Die zwingende Zustimmung zur Geheimhaltungsvereinbarung (NDA) wurde umgangen oder ist für diese Version noch nicht erfolgt. Der Vorgang wurde forensisch erfasst.\' ),' ],
		],
	],
	[
		'D9 display name not cut to the column',
		[
			[ 'includes/class-nda-database.php', '\'user_name\'            => mb_substr( sanitize_text_field( $user_name ), 0, self::MAX_NAME_LENGTH ),', '\'user_name\'            => sanitize_text_field( $user_name ),' ],
		],
	],
	[
		'D9 login goes on without a stored acceptance',
		[
			[ 'includes/class-nda-interceptor.php', 'if ( false === $recorded ) {', 'if ( false ) {' ],
		],
	],
	[
		'D10 factory reset keeps the cache salt',
		[
			[ 'includes/class-nda-database.php', "\t\tself::rotate_cache_salt();\n", '' ],
		],
	],
	[
		'D11 frontend gate page redirected to itself',
		[
			[ 'includes/class-nda-interceptor.php', 'if ( $this->same_page( $this->gate_url(), $current ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D6 checksum scope added after hashing',
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\t'master_checksum_scope'  => 'Covers this exported file only. It shows the document was not altered after export; it says nothing about the database it was read from. That question is answered by register_integrity.',\n", '' ],
			[ 'includes/class-nda-exporter.php', "\t\treturn \$payload;", "\t\t\$payload['certificate_metadata']['master_checksum_scope'] = 'Covers this exported file only.';\n\t\treturn \$payload;" ],
		],
	],
	[
		'D16 export stops at 10 000 rows',
		[
			[ 'includes/class-nda-database.php', '} while ( count( $batch ) === self::EXPORT_BATCH );', '} while ( false );' ],
			[ 'includes/class-nda-database.php', '\'limit\'   => self::EXPORT_BATCH,', '\'limit\'   => 10000,' ],
		],
	],
	[
		'D17 CSV rows without an explicit escape character',
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\t';',\n\t\t\t\t'\"',\n\t\t\t\t''\n\t\t\t);\n\t\t}", "\t\t\t\t';'\n\t\t\t);\n\t\t}" ],
		],
	],
	[
		'D7 demo rows recognised by address and name again',
		[
			[ 'includes/class-nda-seeder.php', '$demo  = (int) $wpdb->get_var( $wpdb->prepare( \'SELECT COUNT(*) FROM %i WHERE user_id = %d\', $table, 0 ) );', '$demo  = (int) $wpdb->get_var( $wpdb->prepare( \'SELECT COUNT(*) FROM %i WHERE user_email LIKE %s OR user_name LIKE %s\', $table, \'%@demo-%\', \'%[Demo]%\' ) );' ],
			[ 'includes/class-nda-seeder.php', '$wpdb->query( $wpdb->prepare( \'DELETE FROM %i WHERE user_id = %d\', $table, 0 ) );', '$wpdb->query( $wpdb->prepare( \'DELETE FROM %i WHERE user_email LIKE %s OR user_name LIKE %s\', $table, \'%@demo-%\', \'%[Demo]%\' ) );' ],
		],
	],
	[
		'D12 every gate off still HEALTHY',
		[
			[ 'includes/class-nda-spoke-adapter.php', '[] === $gates_on || ', '' ],
		],
	],
	[
		'D12 missing tables still HEALTHY',
		[
			[ 'includes/class-nda-spoke-adapter.php', "if ( ! WM_NDA_Database::tables_exist() ) {\n\t\t\treturn 'ERROR';", "if ( false ) {\n\t\t\treturn 'ERROR';" ],
		],
	],
	[
		'SBOM declares the proprietary licence again, with an id and a name at once',
		[
			[ 'includes/class-nda-spoke-adapter.php', "'id' => 'GPL-2.0-or-later',", "'id'   => 'Proprietary',\n\t\t\t\t\t\t'name' => 'Proprietary Commercial License'," ],
		],
	],
	[
		'SBOM licence carries an id and a name at once again, which CycloneDX 1.6 does not accept',
		[
			[ 'includes/class-nda-spoke-adapter.php', "'id' => 'GPL-2.0-or-later',", "'id' => 'GPL-2.0-or-later',\n\t\t\t\t\t\t'name' => 'GNU General Public License v2.0 or later'," ],
		],
	],
	[
		'D13 privacy tools match only the stored e-mail',
		[
			[ 'includes/class-nda-exporter.php', 'return $user ? (int) $user->ID : -1;', 'return -1;' ],
		],
	],
	[
		'D13 eraser reports nothing retained',
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\$items_retained = true;\n", '' ],
		],
	],
	[
		'D13 eraser claims "keinen Rückschluss auf die Person"',
		[
			[ 'includes/class-nda-exporter.php', 'Die Konto-ID verweist weiter auf das WordPress-Konto, wer den Salt kennt, kann das IP-Pseudonym gegen Kandidaten-Adressen prüfen, und Sichtfenster und Zeitzonenversatz beschreiben das benutzte Gerät.', 'Sie lassen keinen Rückschluss auf die Person zu.' ],
		],
	],
	[
		'D14 rate-limit key is an MD5 of the IP again',
		[
			[ 'includes/class-nda-rest-api.php', 'substr( WM_NDA_Database::generate_ip_hmac( $ip ), 0, 32 )', 'md5( $ip )' ],
		],
	],
	[
		'D15 KPIs count rows of deleted accounts',
		[
			[ 'includes/class-nda-database.php', "'SELECT COUNT(DISTINCT a.user_id) FROM %i a INNER JOIN %i u ON u.ID = a.user_id WHERE a.term_id = %d',\n\t\t\t\t\t\$acceptances_table,\n\t\t\t\t\t\$wpdb->users,", "'SELECT COUNT(DISTINCT user_id) FROM %i WHERE term_id = %d AND user_id > 0',\n\t\t\t\t\t\$acceptances_table," ],
		],
	],
	[
		'D20 focus trap lists disabled controls again',
		[
			[ 'assets/js/nda-gate-modal.js', '\'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])\'', '\'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])\'' ],
		],
	],
	[
		'Modal success message German in the script again',
		[
			[ 'assets/js/nda-gate-modal.js', 'statusMsg.textContent = message(\'success\');', 'statusMsg.textContent = \'NDA-Zustimmung erfolgreich signiert. Weiterleitung...\';' ],
		],
	],
	[
		'Admin confirmation German in the script again',
		[
			[ 'assets/js/nda-admin.js', '(window.wmNdaAdmin && window.wmNdaAdmin.confirmVersion)', '(\'Sind Sie sicher, dass Sie eine neue Version veröffentlichen möchten?\')' ],
		],
	],
	[
		'D22 dead get_gate_subheading() back',
		[
			[ 'includes/class-nda-i18n.php', 'public static function get_gate_heading(): string {', "public static function get_gate_subheading(): string {\n\t\treturn '';\n\t}\n\n\tpublic static function get_gate_heading(): string {" ],
		],
	],
	[
		'D21 success message of 2026-09-14 (3.15:1)',
		[
			[ 'assets/css/nda-gate-modal.css', '--wm-nda-success: #15803d;', '--wm-nda-success: #16a34a;' ],
		],
	],
	[
		'D21 muted text of 2026-09-14 (4.34:1 on the hash box)',
		[
			[ 'assets/css/nda-gate-modal.css', '--wm-nda-text-muted: #475569;', '--wm-nda-text-muted: #64748b;' ],
		],
	],
	[
		'D21 error red of 2026-09-14 (4.41:1)',
		[
			[ 'assets/css/nda-gate-modal.css', '--wm-nda-danger: #b91c1c;', '--wm-nda-danger: #dc2626;' ],
		],
	],
	[
		'Raw colour in a modal rule',
		[
			[ 'assets/css/nda-gate-modal.css', "\tcolor: var(--wm-nda-success);\n", "\tcolor: #15803d;\n" ],
		],
	],
	[
		'D21 inline green docs link in the plugin list',
		[
			[ 'wm-prototype-nda-gate.php', "\t\t'<a href=\"%s\">%s</a>',\n\t\tesc_url( admin_url( 'admin.php?page=wm-nda-docs' ) ),", "\t\t'<a href=\"%s\" style=\"font-weight:600; color:#059669;\">%s</a>',\n\t\tesc_url( admin_url( 'admin.php?page=wm-nda-docs' ) )," ],
		],
	],
	[
		'Inline style on the signatures screen again',
		[
			[ 'includes/class-nda-admin.php', 'inline wm-nda-integrity-notice">', 'inline" style="margin: 12px 0;">' ],
		],
	],
	[
		'Modal badge "GeschGehG & UrhG Schutzsystem" again',
		[
			[ 'includes/class-nda-modal.php', '<?php esc_html_e( \'Vertraulichkeitsvereinbarung\', \'wm-prototype-nda-gate\' ); ?></span>', '<?php esc_html_e( \'GeschGehG & UrhG Schutzsystem\', \'wm-prototype-nda-gate\' ); ?></span>' ],
		],
	],
	[
		'Dashboard heading "Letzte rechtliche Signaturen" again',
		[
			[ 'includes/class-nda-admin.php', '<h2><?php esc_html_e( \'Letzte Signaturen\', \'wm-prototype-nda-gate\' ); ?></h2>', '<h2><?php esc_html_e( \'Letzte rechtliche Signaturen\', \'wm-prototype-nda-gate\' ); ?></h2>' ],
		],
	],
	[
		'F13 CLI without strict types',
		[
			[ 'includes/class-nda-cli.php', "declare(strict_types=1);\n\nif ( ! defined( 'ABSPATH' ) ) {", 'if ( ! defined( \'ABSPATH\' ) ) {' ],
		],
	],
	[
		'Uninstall leaves the cache salt behind',
		[
			[ 'uninstall.php', "\t'wm_nda_cache_salt',\n", '' ],
		],
	],
	[
		'Template without a string of the code',
		[
			[ 'languages/wm-prototype-nda-gate.pot', "msgid \"Vertraulichkeitsvereinbarung\"\n", "msgid \"Vertraulichkeitsvereinbarungen\"\n" ],
		],
	],
	[
		'de_DE catalog double-encoded again',
		[
			[ 'languages/wm-prototype-nda-gate-de_DE.po', "msgid \"Einstellungen\"\n", "msgid \"EinstellungÃ¤n\"\n" ],
		],
	],
	[
		'README embeds the marketing banner again',
		[
			[ 'README.md', 'No marketing mockup from', "![WM Prototype NDA Gate banner](assets/images/prototype-nda-gate-banner.jpg)\n\nNo marketing mockup from" ],
		],
	],
	[
		'Marketing images in the package again',
		[
			[ '.distignore', "\nassets/images/\n", "\nassets/imgs-kept/\n" ],
		],
	],
	[
		'CI without the mutation check',
		[
			[ '.github/workflows/ci.yml', 'run: bash tests/run-mutations.sh', 'run: echo skipped' ],
		],
	],
	[
		'CHANGELOG heading with an emoji again',
		[
			[ 'CHANGELOG.md', '### Fixed (2026-09-15, reader pass)', '### 🐛 Fixed (2026-09-15, reader pass)' ],
		],
	],
	[
		'humans.txt says PHP 8.1 again',
		[
			[ 'public/humans.txt', 'PHP 8.2+ (strict types)', 'PHP 8.1+ (strict types)' ],
		],
	],
	[
		'PR checklist names the destructive interception suite again',
		[
			[ '.github/PULL_REQUEST_TEMPLATE.md', '`php tests/test-suite.php`, `php tests/test-ui-family.php`', '`tests/test-nda-interception.php`, `php tests/test-ui-family.php`' ],
		],
	],
	[
		'CLAUDE.md calls the privacy-tools suite destructive again',
		[
			[ 'CLAUDE.md', '`tests/test-nda-interception.php` and `test-register-integrity.php` empty the register; `test-nda-privacy-tools.php` writes and deletes one probe row.', '`tests/test-nda-interception.php`, `test-nda-privacy-tools.php` and `test-register-integrity.php` empty the register.' ],
		],
	],

	// --- Click-wrap interaction record (2026-09-16) ---
	[
		"Interaction record left out of the signed payload",
		[
			[ 'includes/class-nda-database.php', "\t\tif ( '' !== \$evidence_hash ) {\n\t\t\t\$payload .= \"|{\$evidence_hash}\";\n\t\t}\n", "" ],
		],
	],
	[
		"Empty interaction record appended too (every pre-schema-4 acceptance turns \"tampered\")",
		[
			[ 'includes/class-nda-database.php', "if ( '' !== \$evidence_hash ) {", "if ( true ) {" ],
		],
	],
	[
		"Verification ignores the stored record (it can be rewritten freely)",
		[
			[ 'includes/class-nda-database.php', "WM_NDA_Evidence::fingerprint( (string) ( \$row->interaction_json ?? '' ) )", "''" ],
		],
	],
	[
		"Sanitiser takes the schema number from the request",
		[
			[ 'includes/class-nda-evidence.php', "\$record['schema'] = self::SCHEMA;", "\$record['schema'] = isset( \$raw['schema'] ) ? (int) \$raw['schema'] : self::SCHEMA;" ],
		],
	],
	[
		"Sanitiser keeps values outside their range",
		[
			[ 'includes/class-nda-evidence.php', "if ( \$value >= \$min && \$value <= \$max ) {", "if ( true ) {" ],
		],
	],
	[
		"Sanitiser copies the request through (unknown keys survive)",
		[
			[ 'includes/class-nda-evidence.php', "\t\t\$record = [];\n", "\t\t\$record = \$raw;\n" ],
		],
	],
	[
		"Stored record not key-sorted (the same record hashes differently)",
		[
			[ 'includes/class-nda-evidence.php', "\t\tksort( \$record );\n\n", "" ],
		],
	],
	[
		"Oversized record parsed instead of dropped",
		[
			[ 'includes/class-nda-evidence.php', " || strlen( \$raw ) > self::MAX_INPUT_BYTES", "" ],
		],
	],
	[
		"Mouse pressure sold as a measurement",
		[
			[ 'includes/class-nda-evidence.php', "if ( 'mouse' === \$pointer_type || ( 'unknown' === \$pointer_type && self::MOUSE_PRESSURE_PERMILLE === \$permille ) ) {", "if ( false ) {" ],
		],
	],
	[
		"Login path drops the record the form posted",
		[
			[ 'includes/class-nda-interceptor.php', "WM_NDA_Evidence::sanitize( isset( \$_POST['wm_nda_interaction'] ) ? wp_unslash( (string) \$_POST['wm_nda_interaction'] ) : '' )", "[]" ],
		],
	],
	[
		"Login form without the field the record travels in",
		[
			[ 'includes/class-nda-interceptor.php', "<input type=\"hidden\" name=\"wm_nda_interaction\" id=\"wm_nda_interaction\" value=\"\" />", "" ],
		],
	],
	[
		"REST route drops the record it was sent",
		[
			[ 'includes/class-nda-rest-api.php', "\$interaction = WM_NDA_Evidence::sanitize( \$request->get_param( 'interaction' ) );", "\$interaction = [];" ],
		],
	],
	[
		"Privacy export omits the interaction record",
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\tforeach ( WM_NDA_Evidence::describe( WM_NDA_Evidence::from_row( \$sig ) ) as \$detail ) {\n\t\t\t\t\t\$item_data[] = [\n\t\t\t\t\t\t'name'  => \$detail['label'],\n\t\t\t\t\t\t'value' => esc_html( \$detail['value'] ),\n\t\t\t\t\t];\n\t\t\t\t}\n", "" ],
		],
	],
	[
		"Eraser does not name the retained interaction record",
		[
			[ 'includes/class-nda-exporter.php', ", das tagesgesalzene IP-Pseudonym und der Interaktionsnachweis (Eingabegerät, Dauer, Zeigerweg, Sichtfenster, Zeitzonenversatz)", " und das tagesgesalzene IP-Pseudonym" ],
		],
	],
	[
		"JSON certificate without the fingerprint the signature covers",
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\t\t\t'interaction_sha256'   => WM_NDA_Evidence::fingerprint( (string) ( \$a->interaction_json ?? '' ) ),\n", "" ],
		],
	],
	[
		"CSV without the interaction column",
		[
			[ 'includes/class-nda-exporter.php', "\t\t\t\t'Interaktionsnachweis (JSON)',\n", "" ],
		],
	],
	[
		"Demo data written without interaction records",
		[
			[ 'includes/class-nda-seeder.php', "WM_NDA_Evidence::sanitize( \$signer['interaction'] )", "[]" ],
		],
	],
	[
		"Signatures screen claims the record identifies the signer",
		[
			[ 'includes/class-nda-admin.php', "Vom Browser gemeldet und mit der Signatur dieser Zeile versiegelt: nachträglich geändert bricht die Kette. Ob der Browser richtig gemeldet hat, kann das Plugin nicht prüfen.", "Biometrisch erfasst und zweifelsfrei dem Unterzeichner zugeordnet." ],
		],
	],
	[
		"Modal sends no record with the REST acceptance",
		[
			[ 'assets/js/nda-gate-modal.js', "term_id: parseInt(termId, 10),\n\t\t\t\t\t\tinteraction: buildRecord()", "term_id: parseInt(termId, 10)" ],
		],
	],
	[
		"Modal sends a pointer coordinate",
		[
			[ 'assets/js/nda-gate-modal.js', "tz_offset_min: -new Date().getTimezoneOffset()\n", "tz_offset_min: -new Date().getTimezoneOffset(),\n\t\t\t\tclientX: interaction.lastX\n" ],
		],
	],

	// --- Forensic screen watermark (2026-09-16) ---
	[
		"Watermark block without its sync word",
		[
			[ 'includes/class-nda-watermark.php', "\$bits = self::int_to_bits( self::SYNC_WORD, self::SYNC_BITS );", "\$bits = array_fill( 0, self::SYNC_BITS, 0 );" ],
		],
	],
	[
		"Watermark decoded without checking its MAC (any grid names an account)",
		[
			[ 'includes/class-nda-watermark.php', "if ( \$mac !== self::mac( \$version, \$user_id, \$minutes ) ) {", "if ( false ) {" ],
		],
	],
	[
		"Watermark carrier off in the painter (the page no longer cancels)",
		[
			[ 'includes/class-nda-watermark.php', "\$inverted = self::CARRIER_INVERTS_ODD_BLOCKS && 1 === ( ( \$columns + \$rows ) % 2 );", "\$inverted = false;" ],
		],
	],
	[
		"Watermark carrier off in the reader",
		[
			[ 'includes/class-nda-watermark.php', "\$sign       = ( self::CARRIER_INVERTS_ODD_BLOCKS && 1 === ( ( \$bx + \$by ) % 2 ) ) ? -1 : 1;", "\$sign       = 1;" ],
		],
	],
	[
		"Watermark blocks counted equally instead of by how quiet they are",
		[
			[ 'includes/class-nda-watermark.php', "\t\t\t\t\t\$votes[ \$index ] += \$weight * \$sign * ( \$block_mean - \$means[ \$index ] );", "\t\t\t\t\t\$votes[ \$index ] += \$sign * ( \$block_mean - \$means[ \$index ] );" ],
		],
	],
	[
		"Watermark alpha raised past what the contrast note promises",
		[
			[ 'includes/class-nda-watermark.php', "public const ALPHA_PERMILLE = 30;", "public const ALPHA_PERMILLE = 60;" ],
		],
	],
	[
		"Watermark overlay printed for accounts that never signed",
		[
			[ 'includes/class-nda-watermark.php', "return \$term && WM_NDA_Database::has_user_accepted( get_current_user_id(), (int) \$term->term_id );", "return null !== \$term;" ],
		],
	],
	[
		"Watermark overlay on by default",
		[
			[ 'includes/class-nda-watermark.php', "if ( ! (bool) get_option( self::OPTION, 0 ) || ! is_user_logged_in() ) {", "if ( ! (bool) get_option( self::OPTION, 1 ) || ! is_user_logged_in() ) {" ],
		],
	],
	[
		"Watermark overlay carries the account in the markup",
		[
			[ 'includes/class-nda-watermark.php', "'<div id=\"wm-nda-watermark\" class=\"wm-nda-watermark-host\" aria-hidden=\"true\" data-block=\"%s\"", "'<div id=\"wm-nda-watermark\" class=\"wm-nda-watermark-host\" aria-hidden=\"true\" data-user=\"' . get_current_user_id() . '\" data-block=\"%s\"" ],
		],
	],
	[
		"Watermark script without the carrier",
		[
			[ 'assets/js/nda-watermark.js', "var inverted = inverts && 1 === ((bx + by) % 2);", "var inverted = false;" ],
		],
	],
	[
		"Watermark script paints one ink",
		[
			[ 'assets/js/nda-watermark.js', "tileContext.fillStyle = (bit ? 'rgba(0,0,0,' : 'rgba(255,255,255,') + alpha + ')';", "tileContext.fillStyle = 'rgba(0,0,0,' + (bit ? alpha : alpha / 3) + ')';" ],
		],
	],
	[
		"Watermark overlay takes clicks",
		[
			[ 'assets/css/nda-watermark.css', "\tpointer-events: none;\n", "" ],
		],
	],
	[
		"Uninstall keeps the watermark switch",
		[
			[ 'uninstall.php', "\t'wm_nda_watermark',\n", "" ],
		],
	],
	[
		'Retention rewrites ip_hmac, which is inside the signed payload — every pseudonymised row reads as tampered',
		[
			[ 'includes/class-nda-retention.php', "'user_agent' => self::NEUTRAL_AGENT,", "'user_agent' => self::NEUTRAL_AGENT,\n\t\t\t\t\t'ip_hmac'    => 'rewritten'," ],
		],
	],
	[
		'A retention of 0 is read as "pseudonymise everything" instead of "keep everything"',
		[
			[ 'includes/class-nda-retention.php', "\t\tif ( \$days < 30 ) {\n\t\t\treturn 0;\n\t\t}\n", '' ],
		],
	],
	[
		'The retention cutoff lands in the future, so signatures inside the window are pseudonymised too',
		[
			// Mutates the VALUE, not the shape of the query: rewriting the SQL text unbalances
			// $wpdb->prepare()'s placeholders and PHP 8 throws, so the suite would go red on a
			// fatal instead of on the assertion — a mutation that proves nothing.
			[ 'includes/class-nda-retention.php', "time() - ( \$days * DAY_IN_SECONDS )", "time() + ( \$days * DAY_IN_SECONDS )" ],
		],
	],
	[
		'The retention field claims deletion again, which is what it promised and never did',
		[
			[ 'includes/class-nda-admin.php', 'Gelöscht wird nichts', 'Einträge werden automatisch gelöscht' ],
		],
	],
	[
		'Uninstaller deletes options and drops tables without opt-in',
		[
			[ 'uninstall.php', "if ( ! (bool) get_option( 'wm_nda_delete_data_on_uninstall', 0 ) ) {\n\treturn;\n}\n", '' ],
		],
	],
	[
		'Admin omits wm_nda_delete_data_on_uninstall setting registration',
		[
			[ 'includes/class-nda-admin.php', "'wm_nda_delete_data_on_uninstall',\n\t\t\t[\n\t\t\t\t'type'              => 'boolean',", "'wm_nda_unused_setting',\n\t\t\t[\n\t\t\t\t'type'              => 'boolean'," ],
		],
	],
	[
		'Public REST route reverts to __return_true without rate limiting',
		[
			[ 'includes/class-nda-rest-api.php', "'callback'            => [ \$this, 'handle_active_terms' ],\n\t\t\t\t'permission_callback' => [ \$this, 'check_public_nda_access' ],", "'callback'            => [ \$this, 'handle_active_terms' ],\n\t\t\t\t'permission_callback' => '__return_true'," ],
		],
	],
	[
		'Public REST rate limit fails open for non-admin callers',
		[
			[ 'includes/class-nda-rest-api.php', "if ( ! \$this->enforce_rate_limit( \$ip_address, \$limit, 'pub_' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'Block Bindings source wm-nda/status is omitted',
		[
			[ 'includes/class-nda-core.php', "register_block_bindings_source(\n\t\t\t'wm-nda/status',", "register_block_bindings_source(\n\t\t\t'wm-nda/missing'," ],
		],
	],
	[
		'GenerateBlocks dynamic tag wm_nda_status is omitted',
		[
			[ 'includes/class-nda-core.php', "'tag'         => 'wm_nda_status',", "'tag'         => 'wm_nda_missing'," ],
		],
	],

	// --- R2-B8: Multisite wp_initialize_site & non-frontend autoload = false (2026-11-18) ---
	[
		'R2-B8 wp_initialize_site hook omitted',
		[
			[ 'wm-prototype-nda-gate.php', "add_action( 'wp_initialize_site', 'wm_nda_initialize_site', 10, 1 );", '// wp_initialize_site hook omitted' ],
		],
	],
	[
		'R2-B8 wp_initialize_site ignores is_multisite() check',
		[
			[ 'wm-prototype-nda-gate.php', "if ( ! is_multisite() ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8 wp_initialize_site ignores is_plugin_active_for_network check',
		[
			[ 'wm-prototype-nda-gate.php', "if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8 wp_initialize_site omits switch_to_blog',
		[
			[ 'wm-prototype-nda-gate.php', "\tswitch_to_blog( \$blog_id );\n\ttry {\n\t\twm_nda_provision_site();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\twm_nda_provision_site();" ],
		],
	],
	[
		'R2-B8 wp_initialize_site omits try/finally around restore_current_blog',
		[
			[ 'wm-prototype-nda-gate.php', "\tswitch_to_blog( \$blog_id );\n\ttry {\n\t\twm_nda_provision_site();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\tswitch_to_blog( \$blog_id );\n\twm_nda_provision_site();\n\trestore_current_blog();" ],
		],
	],
	[
		'R2-B8 network activation ignores $network_wide',
		[
			[ 'wm-prototype-nda-gate.php', "if ( \$network_wide && is_multisite() && function_exists( 'get_sites' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8 wm_nda_schema_version autoload not set to false on provisioning',
		[
			[ 'wm-prototype-nda-gate.php', "\tWM_NDA_Database::create_tables();\n\tupdate_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION, false );", "\tWM_NDA_Database::create_tables();\n\tupdate_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION );" ],
		],
	],
	[
		'R2-B8 wm_nda_schema_version autoload not set to false on schema upgrade',
		[
			[ 'wm-prototype-nda-gate.php', "\t\tWM_NDA_Database::rotate_cache_salt();\n\t}\n\tupdate_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION, false );", "\t\tWM_NDA_Database::rotate_cache_salt();\n\t}\n\tupdate_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION );" ],
		],
	],
	[
		'R2-B8 wm_nda_retention_days autoload not set to false on provisioning',
		[
			[ 'wm-prototype-nda-gate.php', "if ( false === get_option( 'wm_nda_retention_days' ) ) {\n\t\tupdate_option( 'wm_nda_retention_days', 90, false );", "if ( false === get_option( 'wm_nda_retention_days' ) ) {\n\t\tupdate_option( 'wm_nda_retention_days', 90 );" ],
		],
	],
	[
		'R2-B8 wm_nda_delete_data_on_uninstall autoload not set to false on provisioning',
		[
			[ 'wm-prototype-nda-gate.php', "if ( false === get_option( 'wm_nda_delete_data_on_uninstall' ) ) {\n\t\tupdate_option( 'wm_nda_delete_data_on_uninstall', 0, false );", "if ( false === get_option( 'wm_nda_delete_data_on_uninstall' ) ) {\n\t\tupdate_option( 'wm_nda_delete_data_on_uninstall', 0 );" ],
		],
	],
	[
		'R2-B8 wm_nda_retention_days autoload not set to false on settings save',
		[
			[ 'includes/class-nda-admin.php', "update_option( 'wm_nda_retention_days', absint( \$_POST['wm_nda_retention_days'] ?? 90 ), false );", "update_option( 'wm_nda_retention_days', absint( \$_POST['wm_nda_retention_days'] ?? 90 ) );" ],
		],
	],
	[
		'R2-B8 wm_nda_delete_data_on_uninstall autoload not set to false on settings save',
		[
			[ 'includes/class-nda-admin.php', "update_option( 'wm_nda_delete_data_on_uninstall', isset( \$_POST['wm_nda_delete_data_on_uninstall'] ) ? 1 : 0, false );", "update_option( 'wm_nda_delete_data_on_uninstall', isset( \$_POST['wm_nda_delete_data_on_uninstall'] ) ? 1 : 0 );" ],
		],
	],
	[
		'R2-B8 wm_nda_retention_days autoload not set to false on factory reset',
		[
			[ 'includes/class-nda-seeder.php', "\t\tupdate_option( 'wm_nda_retention_days', 90, false );", "\t\tupdate_option( 'wm_nda_retention_days', 90 );" ],
		],
	],
	[
		'R2-B8 wm_nda_delete_data_on_uninstall autoload not set to false on factory reset',
		[
			[ 'includes/class-nda-seeder.php', "\t\tupdate_option( 'wm_nda_delete_data_on_uninstall', 0, false );", "\t\tupdate_option( 'wm_nda_delete_data_on_uninstall', 0 );" ],
		],
	],
	[
		'Shortened hash: short_hash() appends three dots again',
		[
			[ 'includes/class-nda-admin.php', "return substr( \$hash, 0, \$length ) . '…';", "return substr( \$hash, 0, \$length ) . '...';" ],
		],
	],
	[
		'Shortened hash: the versions cell prints the dots after a bare <code> again',
		[
			[ 'includes/class-nda-admin.php', '<td><code class="wm-nda-hash"><?php echo esc_html( self::short_hash( $t->term_hash ) ); ?></code></td>', '<td><code><?php echo esc_html( substr( $t->term_hash, 0, 16 ) ); ?>...</code></td>' ],
		],
	],
	[
		'Shortened hash: may wrap again (no white-space: nowrap)',
		[
			[ 'assets/css/nda-admin.css', "\twhite-space: nowrap;\n\tvertical-align: top;", "\tvertical-align: top;" ],
		],
	],
	[
		'Shortened hash: drawn into the next column (no clip with an ellipsis)',
		[
			[ 'assets/css/nda-admin.css', "\toverflow: hidden;\n\ttext-overflow: ellipsis;\n", '' ],
		],
	],
	[
		'Versions table: the version column takes an equal share again',
		[
			[ 'includes/class-nda-admin.php', '<th class="wm-nda-col-version">', '<th>' ],
		],
	],
	[
		'Narrow columns: an e-mail address has no break opportunity after the @ again',
		[
			[ 'includes/class-nda-admin.php', "return str_replace( '@', '@<wbr>', esc_html( \$email ) );", 'return esc_html( $email );' ],
		],
	],
	[
		'Narrow columns: the signatures screen prints the bare e-mail address again',
		[
			[ 'includes/class-nda-admin.php', "(ID: <?php echo esc_html( (string) \$acc->user_id ); ?>)</td>\n\t\t\t\t\t\t\t\t<td><?php echo wp_kses( self::breakable_email( \$acc->user_email ), [ 'wbr' => [] ] ); ?></td>", "(ID: <?php echo esc_html( (string) \$acc->user_id ); ?>)</td>\n\t\t\t\t\t\t\t\t<td><?php echo esc_html( \$acc->user_email ); ?></td>" ],
		],
	],
	[
		'Narrow columns: German compounds break inside the word again (no hyphens: auto)',
		[
			[ 'assets/css/nda-admin.css', "\thyphens: auto;\n\thyphenate-limit-chars: 10 4 4;\n", '' ],
		],
	],
	[
		'Narrow columns: the version title is not hyphenated again',
		[
			[ 'includes/class-nda-admin.php', '<td class="wm-nda-hyphenate"><?php echo esc_html( $t->term_title ); ?></td>', '<td><?php echo esc_html( $t->term_title ); ?></td>' ],
		],
	],
	[
		'Narrow columns: the versions form stays beside the table down to 1024 px again',
		[
			[ 'assets/css/nda-admin.css', '@media (max-width: 1280px)', '@media (max-width: 1024px)' ],
		],
	],
	// Legal texts neutralised (Arnold, 21.11.2026): each entry puts one of the 1.3.2 claims back.
	[
		'Legal texts: the checkbox agrees "verbindlich" again',
		[
			[ 'includes/class-nda-i18n.php', "'Ich habe die Vertraulichkeitsvereinbarung (NDA) für dieses Prototyp-System gelesen und stimme ihr zu.'", "'Ich stimme der Vertraulichkeitsvereinbarung (NDA) für dieses Prototyp-System verbindlich zu.'" ],
		],
	],
	[
		'Legal texts: the accept button is "verbindlich" again',
		[
			[ 'includes/class-nda-i18n.php', "'NDA akzeptieren & fortfahren'", "'NDA verbindlich akzeptieren & fortfahren'" ],
		],
	],
	[
		'Legal texts: the default agreement cites a statute again',
		[
			[ 'includes/class-nda-i18n.php', '<p>Gesetzlich zwingende Rechte bleiben unberührt.</p>', '<p>Gesetzlich zwingende Rechte (§ 69e UrhG) bleiben unberührt.</p>' ],
		],
	],
	[
		'Legal texts: the default agreement names the vendor as owner again',
		[
			[ 'includes/class-nda-i18n.php', 'dem Betreiber dieser Website oder dem jeweiligen Rechteinhaber', 'der WM Suite oder dem jeweiligen Rechteinhaber' ],
		],
	],
	[
		'Legal texts: the default agreement leaves out the user agent the register stores',
		[
			[ 'includes/class-nda-i18n.php', 'Anzeigename, User-Agent, Zeitpunkt (UTC)', 'Anzeigename, Zeitpunkt (UTC)' ],
		],
	],
	[
		'Legal texts: the modal notice cites § 2 GeschGehG again',
		[
			[ 'includes/class-nda-modal.php', 'Der Zugang zu diesem Prototyp setzt Ihre Zustimmung zur folgenden Vereinbarung voraus.', 'Der Zugang zu diesem Prototyp setzt die Anerkennung der Pflichten nach § 2 GeschGehG voraus.' ],
		],
	],
	[
		'Legal texts: the eraser cites Art. 17 as permission again',
		[
			[ 'includes/class-nda-exporter.php', "'Beibehalten, weil sie Teil der verketteten Signatur sind: Konto-ID", "'Beibehalten (Art. 17 Abs. 3 lit. e DSGVO): Konto-ID" ],
		],
	],
	[
		'Legal texts: the eraser placeholder names Art. 17 again',
		[
			[ 'includes/class-nda-exporter.php', "'user_name'  => '[Pseudonymisiert]',", "'user_name'  => '[DSGVO Art. 17 Gelöscht]'," ],
		],
	],
	[
		'Legal texts: the active version is "Gültig" again',
		[
			[ 'includes/class-nda-admin.php', "esc_html_e( 'Aktiv', 'wm-prototype-nda-gate' )", "esc_html_e( 'Aktiv & Gültig', 'wm-prototype-nda-gate' )" ],
		],
	],
	[
		'Legal texts: the retention field cites Art. 17 as permission again',
		[
			[ 'includes/class-nda-admin.php', 'Gelöscht wird nichts — eine gelöschte Zeile würde das Register unterbrechen.', 'Gelöscht wird nichts — der Nachweis darf nach Art. 17 Abs. 3 lit. e DSGVO aufbewahrt werden.' ],
		],
	],
	[
		'Legal texts: handbook card 2 lists the statutes of the old text again',
		[
			[ 'includes/class-nda-admin.php', "'2. Der mitgelieferte Vertragstext'", "'2. Worauf der Standard-Vertragstext Bezug nimmt: GeschGehG, UrhG'" ],
		],
	],
	[
		'Legal texts: "wp help nda retention" cites Art. 17 as permission again',
		[
			[ 'includes/class-nda-cli.php', 'for every row after it. Account ID, time, version, IP pseudonym and interaction record stay', 'for every row after it, as Art. 17 Abs. 3 lit. e DSGVO allows. Account ID, time, version, IP pseudonym and interaction record stay' ],
		],
	],
	[
		'Legal texts (1.3.4): the help tab says a new version declares earlier acceptances invalid again',
		[
			[ 'includes/class-nda-admin.php', 'Die bisherigen Bestätigungen bleiben mit ihrer Fassung im Register und lassen sich weiter prüfen.', 'Alle bisherigen Signaturen werden damit für ungültig erklärt.' ],
		],
	],
	[
		'Legal texts (1.3.4): the handbook labels the export "DSGVO-Export & Zertifikate" again',
		[
			[ 'includes/class-nda-admin.php', "esc_html_e( 'Export:', 'wm-prototype-nda-gate' )", "esc_html_e( 'DSGVO-Export & Zertifikate:', 'wm-prototype-nda-gate' )" ],
		],
	],
	[
		'Legal texts (1.3.4): POST /accept calls another version invalid again',
		[
			[ 'includes/class-nda-rest-api.php', "'Die angeforderte NDA-Version ist nicht die aktive Fassung.'", "'Die angeforderte NDA-Version ist nicht mehr aktiv oder ungültig.'" ],
		],
	],
	[
		'Legal texts (1.3.4): the JSON download is a certificate again',
		[
			[ 'includes/class-nda-exporter.php', "'wm-nda-audit-register-'", "'wm-nda-audit-certificate-'" ],
		],
	],
	[
		'Legal texts (1.3.4): docs/DATA-PROCESSING.md names a legal basis again',
		[
			[ 'docs/DATA-PROCESSING.md', 'Eine rechtliche Bewertung enthält diese Datei nicht', 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO; eine weitere Bewertung enthält diese Datei nicht' ],
		],
	],
	[
		'Legal texts (1.3.4): README.md has a "Compliance" heading again',
		[
			[ 'README.md', '## Data and limits', '## Compliance' ],
		],
	],
	[
		'WP-CLI (1.3.4): --yes no longer reaches the confirmation of "wp nda demodaten --reset"',
		[
			[ 'includes/class-nda-cli.php', 'zurücksetzen?\', $assoc_args );', 'zurücksetzen?\' );' ],
		],
	],
	[
		'Legal texts (1.3.4): readme.txt is tagged compliance, gdpr and dsgvo again',
		[
			[ 'readme.txt', 'Tags: nda, security, confidentiality, clickwrap, privacy', 'Tags: nda, security, confidentiality, clickwrap, compliance, gdpr, dsgvo' ],
		],
	],
];
