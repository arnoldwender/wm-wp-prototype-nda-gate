<?php
/**
 * WP-CLI Commands for WM Prototype NDA Gate
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage the NDA Gate register: status, acceptances, verification, demo data, retention and the screen watermark.
 */
class WM_NDA_CLI_Command {

	/**
	 * Apply the configured retention window to the acceptance register.
	 *
	 * Signatures older than `wm_nda_retention_days` have their name, e-mail address and user agent
	 * replaced. Nothing is deleted: the register is a hash chain, and a deleted row breaks verification
	 * for every row after it. Account ID, time, version, IP pseudonym and interaction record stay
	 * because they are part of the signed chain.
	 *
	 * ip_hmac is never touched — it is part of the signed payload and is already a day-salted
	 * pseudonym.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what a pass would change and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda retention --dry-run
	 *     wp nda retention
	 *
	 * @subcommand retention
	 */
	public function retention( $args, $assoc_args ) {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$days    = WM_NDA_Retention::configured_days();

		if ( 0 === $days ) {
			WP_CLI::warning( 'Aufbewahrungsfrist steht auf "alles behalten" (unter 30 Tagen). Es wird nichts verändert.' );
			return;
		}

		$result = WM_NDA_Retention::apply( $dry_run );
		WP_CLI::line( sprintf( 'Frist: %d Tage — Stichtag %s (UTC)', $days, $result['cutoff'] ) );

		if ( 0 === $result['candidates'] ) {
			WP_CLI::success( 'Keine Signatur ist älter als die Frist und trägt noch personenbezogene Felder.' );
			return;
		}

		$rest = $result['remaining'] ? ' Weitere warten auf den nächsten Durchlauf.' : '';

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d Signatur(en) würden pseudonymisiert.%s', $result['candidates'], $rest ) );
			return;
		}

		WP_CLI::success( sprintf( '%d von %d Signatur(en) pseudonymisiert.%s', $result['applied'], $result['candidates'], $rest ) );
	}

	/**
	 * Show active NDA terms and total verified signatures.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda status
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$active_term = WM_NDA_Database::get_active_term();
		$total_sigs  = WM_NDA_Database::get_acceptances_count();

		if ( ! $active_term ) {
			WP_CLI::warning( 'Kein aktiver NDA-Vertragstext in der Datenbank gefunden.' );
			return;
		}

		$on = static fn( string $option ): string => (bool) get_option( $option, 1 ) ? 'an' : 'aus';

		WP_CLI::line( "=== WM Prototype NDA Gate Status ===" );
		WP_CLI::line( "Projekt-Name:      " . get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) ) );
		WP_CLI::line( "Aktive Version:    " . $active_term->term_version );
		WP_CLI::line( "Vertragstitel:     " . $active_term->term_title );
		WP_CLI::line( "Text-Hash:         " . $active_term->term_hash );
		// Was "Gültig seit": a validity the plugin does not assess, like the versions screen's old "Aktiv & Gültig".
		WP_CLI::line( "Aktiv seit (UTC):  " . $active_term->created_at );
		WP_CLI::line( "Signaturen gesamt: " . $total_sigs );
		WP_CLI::line( "Login-Checkbox:    " . $on( 'wm_nda_require_login_checkbox' ) );
		WP_CLI::line( "Admin-Gate:        " . $on( 'wm_nda_enforce_admin_gate' ) );
		WP_CLI::line( "Frontend-Gate:     " . $on( 'wm_nda_enforce_frontend_gate' ) );
		WP_CLI::line( "REST-Sperre:       " . $on( 'wm_nda_enforce_rest_api' ) );
		// Was: "Rechtsschild: GeschGehG § 2, UrhG § 69e, DSGVO Art. 6/7" and "NDA Gate ist aktiv und
		// schützt das System" — a legal label, and "active" even with every gate switched off.
		WP_CLI::line( 'Register prüfen:   wp nda verify' );
	}

	/**
	 * List recent NDA signatures.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of signatures to display (default: 20).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda list --limit=10
	 *
	 * @subcommand list
	 */
	public function list( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;

		$acceptances = WM_NDA_Database::get_acceptances( [
			'limit'  => $limit,
			'offset' => 0,
		] );

		if ( empty( $acceptances ) ) {
			WP_CLI::line( 'Keine Signaturen gefunden.' );
			return;
		}

		$items = [];
		foreach ( $acceptances as $a ) {
			$items[] = [
				'ID'        => $a->acceptance_id,
				'User'      => $a->user_name,
				'E-Mail'    => $a->user_email,
				'Version'   => $a->term_version,
				'Zeit (UTC)'=> $a->accepted_at,
				'Interaktion' => WM_NDA_Evidence::short_summary( WM_NDA_Evidence::from_row( $a ) ),
				'Hash'      => substr( $a->signature_hash, 0, 12 ) . '...',
			];
		}

		WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'User', 'E-Mail', 'Version', 'Zeit (UTC)', 'Interaktion', 'Hash' ] );
	}

	/**
	 * Recompute every stored consent signature and walk the chain.
	 *
	 * Until this command existed, generate_signature_hash() was only ever written — no code in
	 * the plugin ever recomputed a stored row, so the "kryptografische Prüfsummen" the admin
	 * screen advertised had never been checked by anything.
	 *
	 * Exits non-zero when the register does not verify, so it works as a cron gate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda verify
	 *
	 * @subcommand verify
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$result = WM_NDA_Database::verify_register();

		if ( 'wp-config' !== $result['key_source'] ) {
			WP_CLI::warning(
				'AUTH_KEY ist in dieser Installation nicht definiert. Der Signaturschlüssel stammt aus ' .
				'der Datenbank — also von dort, wo ein Angreifer ohnehin schreiben würde. Setzen Sie ' .
				'AUTH_KEY in der wp-config.php.'
			);
		}

		if ( ! $result['valid'] ) {
			$where = null === $result['broken_at_id'] ? 'am Ende des Registers' : 'bei Zustimmung #' . $result['broken_at_id'];
			WP_CLI::error( "Register NICHT integer {$where}: " . (string) $result['error'] );
		}

		WP_CLI::success( "Register integer: {$result['checked']} Zustimmungen neu berechnet und verkettet geprüft." );

		if ( $result['unchained_legacy'] > 0 ) {
			WP_CLI::warning(
				"{$result['unchained_legacy']} Zustimmung(en) stammen aus der Zeit vor der Verkettung und tragen " .
				'keine prüfbare Signatur. Sie gelten als ungeprüft, nicht als integer.'
			);
		}
	}

	/**
	 * Read the forensic watermark out of a screenshot and name the account the screen was served to.
	 *
	 * Answers "not found" whenever the block's MAC does not check out — it never guesses an account.
	 * It survives cropping and JPEG, not scaling, rotation or a photograph of a screen.
	 *
	 * ## OPTIONS
	 *
	 * <datei>
	 * : Screenshot to read (PNG, JPEG or WebP).
	 *
	 * [--porcelain]
	 * : Print the account ID alone, and nothing when no watermark verified.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda wasserzeichen-lesen leak.png
	 *
	 * @subcommand wasserzeichen-lesen
	 */
	public function wasserzeichen_lesen( $args, $assoc_args ) {
		$result = WM_NDA_Watermark::extract_from_file( (string) ( $args[0] ?? '' ) );

		if ( ! $result['found'] ) {
			if ( isset( $assoc_args['porcelain'] ) ) {
				WP_CLI::halt( 1 );
			}
			WP_CLI::error( (string) $result['error'] );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $result['user_id'] );
			return;
		}

		$user  = get_userdata( (int) $result['user_id'] );
		$known = $user ? "{$user->user_login} <{$user->user_email}>" : 'kein Konto mit dieser ID mehr vorhanden';

		WP_CLI::line( '=== Wasserzeichen gefunden ===' );
		WP_CLI::line( 'Konto-ID:          ' . $result['user_id'] );
		WP_CLI::line( 'Konto:             ' . $known );
		WP_CLI::line( 'Seite ausgeliefert: ' . $result['issued_at'] . ' UTC' );
		WP_CLI::line( 'Screenshot-Maßstab: ' . $result['scale'] . 'x' );
		WP_CLI::line( 'Gelesene Blöcke:   ' . $result['blocks'] );
		WP_CLI::line( 'Rasterübereinstimmung: ' . $result['sync_score'] );
		WP_CLI::line( 'Das benennt das Konto, dem die Seite ausgeliefert wurde — nicht, wer den Screenshot gemacht hat.' );
	}

	/**
	 * Write a test screenshot that carries the watermark, to try the reader with.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Account the block names (default: 1).
	 *
	 * [--datei=<pfad>]
	 * : Where to write the PNG (default: a file in the system temp directory).
	 *
	 * [--breite=<px>]
	 * : Width in pixels (default: 1440).
	 *
	 * [--hoehe=<px>]
	 * : Height in pixels (default: 900).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda wasserzeichen-probe --user=7 --datei=/tmp/probe.png
	 *
	 * @subcommand wasserzeichen-probe
	 */
	public function wasserzeichen_probe( $args, $assoc_args ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			WP_CLI::error( 'Die PHP-Erweiterung GD fehlt.' );
		}

		$user_id = isset( $assoc_args['user'] ) ? (int) $assoc_args['user'] : 1;
		$width   = isset( $assoc_args['breite'] ) ? max( 320, (int) $assoc_args['breite'] ) : 1440;
		$height  = isset( $assoc_args['hoehe'] ) ? max( 256, (int) $assoc_args['hoehe'] ) : 900;
		$file    = isset( $assoc_args['datei'] ) ? (string) $assoc_args['datei'] : rtrim( sys_get_temp_dir(), '/' ) . '/wm-nda-wasserzeichen-' . $user_id . '.png';

		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width, $height, (int) imagecolorallocate( $image, 250, 250, 250 ) );

		// Lines of "text": a flat page would make the reader look better than it is.
		$ink = (int) imagecolorallocate( $image, 30, 30, 35 );
		for ( $y = 40; $y < $height - 40; $y += 26 ) {
			$line = (int) round( $width * 0.62 ) - ( intdiv( $y, 26 ) % 5 ) * 40;
			imagefilledrectangle( $image, 60, $y, 60 + max( 40, $line ), $y + 9, $ink );
		}

		WM_NDA_Watermark::render_onto( $image, $user_id );
		imagepng( $image, $file );

		WP_CLI::success( "Testbild geschrieben: {$file}" );
		WP_CLI::line( "Gegenprobe: wp nda wasserzeichen-lesen {$file}" );
	}

	/**
	 * Manage Demo Data and Factory Reset.
	 *
	 * ## OPTIONS
	 *
	 * [--import]
	 * : Import five demo signatures (no WordPress accounts). Refused when the register holds real signatures.
	 *
	 * [--delete]
	 * : Delete the demo signatures. Refused when real signatures are chained with them.
	 *
	 * [--reset]
	 * : Factory reset - delete all signatures and terms, restore the default NDA contract and gate options.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation of --reset (scripts, docker exec without a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nda demodaten --import
	 *     wp nda demodaten --delete
	 *     wp nda demodaten --reset
	 *     wp nda demodaten --reset --yes
	 *
	 * @subcommand demodaten
	 */
	public function demodaten( $args, $assoc_args ) {
		// Until 2026-09-14 --import called WM_NDA_Seeder::seed() and --reset factory_reset(), methods that
		// do not exist: both options ended in a fatal error.
		if ( isset( $assoc_args['import'] ) ) {
			WP_CLI::line( 'Importiere NDA Gate Demodaten...' );
			$res = WM_NDA_Seeder::seed_demo_data();
			if ( $res['refused'] ) {
				WP_CLI::error( 'Das Register enthält echte Signaturen: Demodaten würden mit ihnen verkettet. Nichts importiert.' );
			}
			WP_CLI::success( "{$res['signatures']} Demodaten-Signaturen (ohne WordPress-Konto) wurden erzeugt." );
		} elseif ( isset( $assoc_args['delete'] ) ) {
			WP_CLI::line( 'Lösche NDA Gate Demodaten...' );
			$res = WM_NDA_Seeder::delete_demo_data();
			if ( $res['refused'] ) {
				WP_CLI::error( 'Demodaten sind mit echten Signaturen verkettet; Löschen würde das Register brechen. Nichts gelöscht.' );
			}
			WP_CLI::success( "{$res['deleted']} Demodaten-Signaturen wurden gelöscht." );
		} elseif ( isset( $assoc_args['reset'] ) ) {
			// $assoc_args carries --yes. Until 1.3.4 the command did not declare it, so WP-CLI refused the flag and
			// the reset could only be confirmed by piping "y" into the prompt.
			WP_CLI::confirm( 'WARNUNG: Möchten Sie wirklich alle Signaturen löschen und den NDA-Vertragstext auf Werkseinstellungen zurücksetzen?', $assoc_args );
			WM_NDA_Seeder::reset_to_factory_defaults();
			WP_CLI::success( 'WM Prototype NDA Gate wurde auf Werkseinstellungen zurückgesetzt.' );
		} else {
			WP_CLI::error( 'Bitte geben Sie eine Option an: --import, --delete oder --reset.' );
		}
	}
}

WP_CLI::add_command( 'nda', 'WM_NDA_CLI_Command' );
