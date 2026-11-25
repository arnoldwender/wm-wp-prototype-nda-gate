=== WM Prototype NDA Gate ===
Contributors: arnoldwender
Tags: nda, security, confidentiality, clickwrap, privacy
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asks logged-in users to accept a confidentiality agreement and keeps every acceptance in an HMAC-chained register.

== Description ==

WM Prototype NDA Gate asks every logged-in account to accept the active version of a confidentiality agreement before it uses the site, and records each acceptance with a chained signature.

* **Four checkpoints for logged-in users:** a checkbox on wp-login.php (checked after the password), a redirect before any wp-admin screen, a redirect on the frontend, and HTTP 403 on REST routes. Anonymous visitors are not gated.
* **Register:** each acceptance stores the account, the text hash, the time, a daily-salted IP hash and an HMAC-SHA256 signature chained to the previous one; the newest link is sealed with the same key and `wp nda verify` recomputes the chain.
* **Click-wrap record:** with the acceptance the browser reports input device, time in the dialog, time between ticking and confirming, scrolled share, pointer samples and travel, highest pressure value, viewport and offset from UTC. Its SHA-256 goes into the signed payload, so it cannot be changed afterwards without breaking the chain. It comes from the browser: it shows the dialog was operated, not by whom, and it is no biometric identification.
* **Forensic screen watermark (off by default):** a faint pattern over every page of a signed user, carrying the account ID and the minute in the pixels. `wp nda wasserzeichen-lesen` reads it back out of a screenshot, or says "not found" — it never guesses an account. It survives cropping and JPEG, not scaling or a photograph of the screen, and it costs contrast (5.10:1 becomes 4.49:1 for grey on white).
* **Versions:** a new text version asks everyone to accept again; the previous version stays active until the new one is stored.
* **Export:** CSV (formula-injection escaped) and JSON with the register check, both with every acceptance.
* **Block Bindings & GenerateBlocks:** exposes NDA acceptance status and active version through WP 6.5+ Block Bindings (`wm-nda/status`) and GenerateBlocks 2.x dynamic tag (`{{wm_nda_status}}`).
* **Opt-in Uninstall & Translations:** preserves NDA terms and acceptance tables on uninstall unless `wm_nda_delete_data_on_uninstall` is explicitly enabled; ships 28 translated `.po`/`.mo` locale catalogs.
* **WP-CLI:** `wp nda status`, `list`, `verify`, `demodaten`, `retention`, `wasserzeichen-lesen`, `wasserzeichen-probe`.

The plugin makes no legal assessment of the agreement or of the measures.

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-prototype-nda-gate/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload the `wm-prototype-nda-gate` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the active terms and gate enforcement under 'WM NDA Gate'.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-prototype-nda-gate/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Cockpit: acceptance rate, signed and pending users, the active agreement version and a preview of the signing modal
2. Versions: the stored agreement versions with status and SHA-256 hash, and the form that publishes a new one
3. Settings: project name, the four gates (login checkbox, wp-admin, frontend, REST), the screen watermark, the retention window and opt-in data removal on uninstall
4. WordPress dashboard widget summarizing active NDA acceptance counts

== Changelog ==


= 1.3.4 =
* Changed: The data-processing notes (`docs/DATA-PROCESSING.md`, until now `docs/DSGVO-COMPLIANCE.md`) describe what the plugin stores and name no legal basis. The help tab and the handbook no longer say that a new version invalidates earlier acceptances: those stay in the register and keep verifying.
* Changed: Nothing is labelled a certificate: the export page is «Export» and the JSON download `wm-nda-audit-register-<date>.json`; the keys inside the JSON keep their names. The REST answer for another version says it is not the active one. In 22 locales the plugin description gains its closing sentence: the plugin does not assess whether agreement and measures hold up legally.
* Fixed: `wp nda demodaten --reset --yes` confirms the reset without a prompt; the option was refused before.
* The screenshots do not change.

= 1.3.3 =
* Changed: The default agreement text is a sample without legal review. It cites no statute, names no vendor and lists what the register stores for each acceptance. New installations and factory resets get it; published versions keep their text and their signatures.
* Changed: The modal notice, the login checkbox and the accept button claim no binding effect. The privacy eraser, the retention field and `wp help nda retention` give the chained signature, not Art. 17 DSGVO, as the reason for what they keep. The versions screen says «Aktiv» and «Version aktivieren».
* Screenshot 2 is re-shot with «Aktiv» and the new default text; screenshots 1, 3 and 4 do not change.

= 1.3.2 =
* Fixed: A shortened hash in the admin tables broke across two lines when its column was narrower than the hash: «8dfac83423be6a76» on one line and «...» on the next (versions screen at 1440 px, the IP-HMAC on the signatures screen, the cockpit at 1280 px). It now stays on one line, and where its column is narrower it is clipped with an ellipsis.
* Fixed: On the versions screen the title no longer breaks inside a word at 1440 px; the version column has its own width. Screenshot 2 is re-shot.
* Fixed: Below 1440 px e-mail addresses, «Interaktionsnachweis» and the version title broke wherever a column ended. An e-mail address now breaks after the «@»; headers, the version title and the interaction summary are hyphenated at a syllable.
* Fixed: At 1100 px the status pill on the versions screen spilled into the next column. The form now moves below the table from 1280 px down instead of from 1024 px.

= 1.3.1 =
* Fixed: On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.
* The first three screenshots are re-shot with the full-width header. Their descriptions named a signature chain on the cockpit and a "forensic canvas envelope" on the settings screen; they now list what each screen shows.

= 1.3.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.2.4 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-prototype-nda-gate` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.2.3 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..4.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-prototype-nda-gate/`).

= 1.2.2 =
* Point Plugin URI to the official plugin page.

= 1.2.1 =
* Multisite provisioning: hooked `wp_initialize_site` for network-activated installs and added `$network_wide` site provisioning in `register_activation_hook` with `try`/`finally` blog context restoration.
* Option autoload hygiene: set `$autoload = false` explicitly on non-frontend options (`wm_nda_schema_version`, `wm_nda_retention_days`, `wm_nda_delete_data_on_uninstall`) while keeping frontend gate options and `wm_nda_cache_salt` intact.

= 1.2.0 =
* Opt-in uninstall setting (`wm_nda_delete_data_on_uninstall`, default off) so removing or reinstalling the plugin preserves NDA terms and acceptance records unless explicitly enabled.
* WordPress 6.5+ Block Bindings source (`wm-nda/status`) and GenerateBlocks 2.x dynamic tag (`{{wm_nda_status}}`) with `wm_nda_status_value` filter.
* Rate-limited public REST permission callback (`check_public_nda_access`, 60 req/min per salted IP HMAC, filterable via `wm_nda_rest_rate_limit`) on `/verify` and `/active-terms`, plus parameterized `%i` table identifiers across all database queries.
* Complete 28-locale `.po` and `.mo` translation catalogs, CycloneDX 1.6 `bom.json`, and WordPress Playground blueprint.
* Forensic screen watermark: identifies the account a leaked screenshot was served to, off until switched on, with its measured limits documented.
* Click-wrap interaction record: what the browser observed while the agreement was on screen, sealed into the signature of its acceptance. No coordinates and no timing series are collected. Acceptances recorded earlier carry none and keep verifying unchanged.
* A version number over 50 characters no longer leaves the site without an active agreement while reporting success.
* The register tip is sealed: deleting the newest acceptances together with the tip, or blanking the chain, no longer verifies; access needs a signature that checks out.
* Simultaneous acceptances no longer fork the register.
* A requested URL with its own query arguments no longer sends the gate into a redirect loop.
* The JSON export's checksum can be reproduced with the recipe it states; exports carry every acceptance.
* "Demodaten bereinigen" no longer deletes a real acceptance from an "@demo-" address.
* The REST refusal no longer claims a forensic record; accounts with long display names can sign; a login whose acceptance cannot be stored is refused.
* The hub reports a missing table as ERROR and switched-off gates as DEGRADED; the privacy tools find acceptances after an e-mail change and report what they keep.
* No IP address in transient names; KPIs count existing accounts; contrast of the modal fixed; the focus trap stays in the dialog; catalogs generated from the code.
* The hub no longer reports a broken register as healthy.
* Demo data no longer counts real accounts as signed and can no longer break the register; the factory reset leaves a register that verifies.
* `wp nda demodaten --import` and `--reset` no longer end in a fatal error.
* German admin screens without legal claims; the retention setting says that nothing applies it.
* Documentation rewritten from the code.
* Security: the NDA check on login runs after the password check; a failed login no longer records a signature or a bypass attempt.
* Users who already signed the active term log in without ticking the box again; unsigned users get a clear instruction.
* Admin screens and dashboard widget on the WM Suite family sheets (shared tokens and components, visible focus rings, native wrap, family badge); KPI tiles, status pills, columns and filter bar now carry styles.
* Dashboard widget shows the cockpit's real figures (signed, pending, active version) instead of counters of tables the plugin never creates.
* Dashicons instead of emoji in the admin.

= 1.1.0 =
* Language catalogs, developer documentation, REST controller, spoke adapter, uninstaller.

= 1.0.0 =
* Initial enterprise release.

== Upgrade Notice ==

= 1.3.4 =
Wording and documentation, plus `--yes` for `wp nda demodaten --reset`. The JSON export is downloaded under a new file name; its content and keys do not change. No data or settings change.

= 1.3.3 =
Wording only: the default agreement, the modal notice, the checkbox and the button no longer cite statutes or claim a binding effect. Published versions, signatures and settings do not change.

= 1.3.2 =
Display fix only: text in the admin tables no longer breaks inside a word or a hash. No data or settings change.

= 1.3.1 =
Stylesheet fix only: the admin header spans the page on screens with a help tab. No data or settings change.

= 1.3.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
