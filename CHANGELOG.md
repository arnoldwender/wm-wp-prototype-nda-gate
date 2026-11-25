# Changelog — WM Prototype NDA Gate

All notable changes to **WM Prototype NDA Gate** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Added `Tested up to: 7.1` to `wm-prototype-nda-gate.php` and synchronized `bom.json`.

## [1.3.4] - 2026-11-22

### Changed

- `docs/DSGVO-COMPLIANCE.md` is now `docs/DATA-PROCESSING.md`. It describes what the plugin stores and what the privacy tools and the retention pass change, and names no legal basis: the section «Mögliche Rechtsgrundlagen» (Art. 6 Abs. 1 lit. b and f DSGVO) and the note that the text awaited a review are gone, and the watermark paragraph no longer classifies screenshots as processing of personal data. The READMEs link it under the heading «Data and limits» (was «Compliance», and a heading with a scales emoji in the 24 translations).
- The help tab and the handbook said that a new version declares every earlier signature invalid. Earlier acceptances stay in the register with their version and keep verifying, and both texts now say so. `POST /wm-nda/v1/accept` for another version answers «Die angeforderte NDA-Version ist nicht die aktive Fassung.» (was «… nicht mehr aktiv oder ungültig.»).
- Nothing is labelled a certificate any more: the export page is titled «Export» (was «Export & Zertifikate»), the handbook bullet reads «Export:» (was «DSGVO-Export & Zertifikate:») and the JSON download is `wm-nda-audit-register-<date>.json` (was `wm-nda-audit-certificate-…`). The keys inside the JSON keep their names, `certificate_metadata` included: the checksum recipe every export states names them.
- `wp help nda` describes the command without «compliance». readme.txt is tagged nda, security, confidentiality, clickwrap and privacy (was …, compliance, gdpr, dsgvo).
- README.de.md and README.es.md no longer reserve all rights next to the GPL. The 24 translated READMEs show PHP 8.2 | 8.3 (they said 8.1 | 8.2 | 8.3; the plugin requires 8.2).
- Catalogs: the 4 new strings are translated in all 28 locales and the 5 replaced ones are gone (261 entries). In 22 locales the plugin description lacked its last sentence, «Ob Vereinbarung und Maßnahmen rechtlich tragen, beurteilt das Plugin nicht.»; it is added.
- The screenshots do not change: none of the changed texts appears on the cockpit, the versions screen, the settings or the dashboard widget.

### Fixed

- `wp nda demodaten --reset --yes` was refused as an unknown parameter: the command did not declare `--yes`, so a reset could only be confirmed by typing or piping «y». The option is declared and reaches the confirmation.

## [1.3.3] - 2026-11-21

### Changed

- The default agreement text is a sample without legal review. It cites no statute (until now § 2 and § 23 GeschGehG, § 69e UrhG and Art. 6 and 7 DSGVO), no longer names "WM Suite" as the owner or as the addressee of notices, and lists what the register stores for each acceptance: user ID, e-mail address, display name, user agent, time (UTC), the checksum of the text, an IP pseudonym and the interaction record. New installations and factory resets get it; published versions keep their text and their signatures.
- The modal notice says what the click stores instead of announcing a "rechtlich verbindliche Anerkennung" under § 2 GeschGehG and § 69e UrhG. The login checkbox reads «Ich habe die Vertraulichkeitsvereinbarung (NDA) für dieses Prototyp-System gelesen und stimme ihr zu.» (it agreed "verbindlich" and announced legal steps) and the accept button «NDA akzeptieren & fortfahren».
- The privacy eraser writes «[Pseudonymisiert]» instead of «[DSGVO Art. 17 Gelöscht]» and gives the chained signature, not Art. 17 Abs. 3 lit. e DSGVO, as the reason for what it keeps; the retention field in the settings, `wp help nda retention` and the README do the same. Rows erased earlier keep the old placeholder: the name is not part of the signature, and nothing rewrites them.
- Versions screen: the active version is «Aktiv» (was «Aktiv & Gültig») and the button «Version aktivieren» (was «Version verbindlich aktivieren»); `wp nda status` prints «Aktiv seit (UTC)» instead of «Gültig seit (UTC)».
- Handbook card 2 says that the default text is an unreviewed sample and that you publish your own reviewed version under «Versionen», instead of listing the statutes the old text cited.
- Catalogs: the 10 new strings are translated in all 28 locales and the 15 replaced ones are gone (262 entries).
- Screenshot 2 in `.wordpress-org/` is re-shot: it shows «Aktiv» and the new default text. Screenshots 1, 3 and 4, retaken against 1.3.3, came out pixel-identical and stay.

## [1.3.2] - 2026-11-21

### Fixed

- A shortened hash in the admin tables broke across two lines when its column was narrower than the hash. The tables have fixed column widths and WordPress lets any word in them break where it would overflow, so the hash was cut where the column ended: on the versions screen at 1440 px the SHA-256 showed «8dfac83423be6a76» on one line and «...» on the next, and the IP-HMAC on the signatures screen and the cockpit's hashes at 1280 px did the same. A shortened hash is now its prefix and «…» in one element that stays on one line; where its column is narrower, the browser clips it and draws the ellipsis.
- On the versions screen the title broke inside a word at 1440 px («Nutzungsvereinbarun» / «g») while the version column stood half empty: the version column now has its own width.
- Below 1440 px the same tables broke words wherever a column ended: e-mail addresses on the signatures screen and in the cockpit («e.rostova@exa» / «mple.com»), «Interaktionsnachweis» on the signatures screen and the version title. An e-mail address now breaks after the «@». Headers, the version title and the interaction summary are hyphenated at a syllable («Interaktions-» / «nachweis»); names and e-mail addresses are not hyphenated.
- At 1100 px the versions table kept 105 px per column beside the form, and the status pill spilled into the next column: the form now moves below the table from 1280 px down instead of from 1024 px.
- Measured in a browser on the cockpit, the signatures and the versions screen at 1440, 1280 and 1100 px: 65 places where text broke inside a word or hash or spilled out of its cell before, 22 of them split hashes; 0 after, and 0 at 1920, 1366 and 1024 px.

### Changed

- Screenshot 2 (versions) in `.wordpress-org/` is re-shot.

## [1.3.1] - 2026-11-21

### Fixed

- On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.

### Changed

- The first three screenshots in `.wordpress-org/` are re-shot with the full-width header; the dashboard widget's is unchanged. Their descriptions in `readme.txt` named a signature chain on the cockpit and a "forensic canvas envelope" on the settings screen; they now list what each screen shows.
- The catalogs carry the Author URI as `https://arnoldwender.com`, as the plugin header does.

## [1.3.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.2.4] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-prototype-nda-gate/`, `https://github.com/arnoldwender/wm-wp-prototype-nda-gate`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [1.2.3] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-4.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-prototype-nda-gate/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`.

### Changed
- Archived superseded early mockups and banners non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata and SBOM (`bom.json`) to `1.2.3`.

---

## [1.2.2] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-prototype-nda-gate/` and align release metadata at `v1.2.2`.

---

## [1.2.1] - 2026-11-18

### Added

- **Multisite New-Site & Network-Wide Provisioning (`wp_initialize_site`):** extracted site provisioning into `wm_nda_provision_site()`, hooked `wp_initialize_site` via `wm_nda_initialize_site()` when `is_multisite()` and `is_plugin_active_for_network()` hold (wrapping `switch_to_blog()` / `restore_current_blog()` in `try`/`finally`), and added `$network_wide` support to `wm_nda_activate_plugin()`.

### Changed

- **Non-Frontend Option Autoload Hygiene (`autoload = false`):** explicitly set `$autoload = false` on `wm_nda_schema_version`, `wm_nda_retention_days`, and `wm_nda_delete_data_on_uninstall` across activation/schema upgrade (`wm-prototype-nda-gate.php`), settings save (`includes/class-nda-admin.php`), and factory reset (`includes/class-nda-seeder.php`), while keeping frontend gate options and `wm_nda_cache_salt` autoloaded.

---

## [1.2.0] - 2026-11-16

### Added

- **WordPress 6.5+ Block Bindings and GenerateBlocks 2.x Dynamic Tag:** registered the `wm-nda/status` Block Bindings source (`field`: `label`, `state`, `active_version`) and the `{{wm_nda_status}}` GenerateBlocks dynamic tag (`key`: `label`, `state`, `active_version`), filtered through `wm_nda_status_value`.
- **Opt-in Uninstall Setting (`wm_nda_delete_data_on_uninstall`):** `uninstall.php` now preserves plugin options, user meta, and both NDA register tables by default (`0`), deleting them only when `wm_nda_delete_data_on_uninstall` is explicitly enabled on **WM NDA Gate > Einstellungen** (`register_setting` + checkbox).
- **28 Complete UI Locale Catalogs:** regenerated `languages/wm-prototype-nda-gate.pot` (267 entries) and compiled complete `.po` and `.mo` catalogs across all 28 supported locales.
- **Release & Distribution Metadata:** added CycloneDX 1.6 `bom.json`, `.license-allowlist.yml`, and `.wordpress-org/blueprints/blueprint.json` for WordPress Playground.

### Security & Quality

- **Public REST Rate Limiting:** replaced `__return_true` on `GET /wm-nda/v1/verify` and `GET /wm-nda/v1/active-terms` with `check_public_nda_access` enforcing a transient-based daily-salted IP HMAC rate limit (60 requests/min default, filterable via `wm_nda_rest_rate_limit`, bypassed for `manage_options`).
- **SQL Identifier Parameterization:** wrapped remaining table-existence, active-term, count, and reset queries in `WM_NDA_Database` with `$wpdb->prepare()` `%i` placeholders.
- **Admin Output Escaping, Modal CSS Tokens & Debug Guards:** wrapped ternary echoes in `WM_NDA_Admin` with `esc_attr()` / `esc_html()`, exposed modal tokens on `:root` without raw hex literals outside token lines in `assets/css/nda-gate-modal.css`, and guarded fallback `error_log()` calls behind `WP_DEBUG`.

### Fixed — the docs said the retention setting was applied by nothing (2026-11-05)

- **README ("Not shipped"), DE, ES, the 24 translated READMEs and DEVELOPERS §2 and §11 said `wm_nda_retention_days` was stored and applied by nothing.** The daily retention pass has run since 2026-09-17 (`WM_NDA_Retention`, test group 14): it pseudonymises name, e-mail and user agent of acceptances older than the window and deletes no row. README's own "What the retention pass does" section already said so, which made the file contradict itself. An operator reading the old line could state the wrong retention period in a privacy notice, or count on a name that is gone.
- DEVELOPERS: §1 lists `class-nda-retention.php` and the full `wp nda` command set, §2 names its reader and the cron event `wm_nda_apply_retention`, §7 the `retention` command, §9 what a pass writes, §11.2 what it keeps (`ip_hmac` and the click-wrap record, both signed), §12 test group 14. The uninstaller deletes thirteen options, not twelve.
- README, DE and ES: the personal-data paragraph names the retention pass; DE and ES also list the click-wrap record, which the EN paragraph already did, and the `retention` and watermark commands.
- `CLAUDE.md` lists all 15 classes (Evidence, Watermark and Retention were missing).
- `docs/ARCHITECTURE.md`: measured inventory regenerated.
- Documentation only; no code changed.

### Fixed — the SBOM declared a proprietary licence (2026-11-05)

- **The SBOM the plugin reports to the hub declared `Proprietary`, with an id and a name at once.** The header and `readme.txt` went to GPLv2 or later on 2026-09-16 (`9a8fc9c`); the adapter kept the block it was written with on 2026-08-27 (`1cdc7d8`). `Proprietary` is not an SPDX id, and a CycloneDX 1.6 licence takes an SPDX id or a name, not both. `WM_NDA_Spoke_Adapter::get_sbom_data()` now declares `GPL-2.0-or-later` as the id alone, the licence the plugin ships under.
- Test group 6 pins the licence. Two entries in `tests/mutations.php` put the old block back and add a name beside the id; each turns the suite red.
- The known issue "the plugin header and `readme.txt` declare a proprietary licence" (README, DE, ES) and its twin in DEVELOPERS §11 are gone: both had been false since `9a8fc9c`. DEVELOPERS §10 names the licence in the SBOM, and §11 is numbered in order again.
- Plugin Check was not re-run for this change.
- Gate: suite 203 PASSED / 0 FAILED, mutations 94 / 94 red with the control green.

### Fixed — the badge claimed a translation the catalogs do not hold (2026-09-20)

- The badge row carried **`Translations — 24 EU Languages` in green**, which reads as a finished translation of the plugin. Measured: **367 real translations across 7,112 catalog entries**, in five locales out of 28 — `ar` 111, `bg_BG` 80, `cs_CZ` 80, `da_DK` 73, `el` 13, of 254 entries per catalog. Every other filled entry repeats the German source string word for word; `msgfmt` counts those copies as translated and a user does not.
- The badge now reads `Translated READMEs — 24 (headings only)` in grey, the label three other plugins of the fleet already use, and the section it links to gives those counts and says plainly that the plugin's own interface is another matter.

### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3. **This catalog was already current** — 253 translatable strings, 253 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it: fifteen of the fleet's twenty-four plugins had drifted, one down to a single shared entry, and not one of them had a check that could notice. Being correct today is not a mechanism.
- The gate takes its definition of "fresh" from `wp i18n make-pot` rather than a regex of its own, reads the text domain from the plugin header instead of holding a second copy, treats 0 extracted strings as a failure rather than a pass, ignores the plugin-header fields `make-pot` adds (nobody translates an author name or a URL), requires wp-cli with no escape hatch, and runs its own self-test before judging the plugin.

### The HTTP gate falsifier runs as an editor (2026-09-17)

- **`tests/runtime/verify-gate-http.sh` failed 2 of 19 checks, and neither was a defect of the gate: the probe is an editor now, not a subscriber.** Measured on a local WordPress install: core answers **403** to a subscriber on `edit.php?post_type=page` before `admin_init` runs (no `edit_pages`), so the query-argument check could never reach the gate with that role; and WooCommerce's `WC_Admin::prevent_admin_access()` sends every user without `edit_posts` to My Account, so once WooCommerce was active a signed subscriber never got `profile.php` → 200. The interceptor exempts no role. With an editor: **19/19 pass**, including the whole URL encoded in `redirect_to` (`paged%3D2`).

### Added (2026-09-16) — the measurement behind the watermark's documented limits

`tests/watermark-envelope.php` renders sixteen screenshots and reads them back: the ten that must name
the account, and the six that must name none — a crop of a single block, an interference background,
and four images carrying no watermark at all. `--check` exits 1 when any case disagrees with what the
README states, so the table there stops being a claim and becomes a command anyone can run. Until now
that table lived in a throwaway script.

### Fixed (2026-09-16) — the watermark did not survive a real page

The suite rendered its own screenshots and read them back, and passed. A screenshot taken by an actual
browser of the local demo home page did not read back at all. The canvas the browser had painted,
lifted out of the page on its own, decoded perfectly — so the script and the reader agreed, and what
defeated the reader was the page underneath.

- **Every block is now weighted by how quiet it is.** A block lying over a hero image swings by a
  hundred steps of brightness while the pattern moves seven; counted equally with the blocks over
  plain text it drowned them. Weighted by the inverse of its own variance, the real browser screenshot
  named the account. `tests/test-suite.php` group 13 gained a page with a hero image over its top
  half — the shape that failed — and `tests/mutations.php` the entry that removes the weighting.
- **A claim in the code was wrong and is corrected.** The comment on the modulation said black ink
  alone had been measured failing on dark pages. The first attempt did fail there, but it also read
  the pattern with a neighbourhood filter; once the reader averaged whole blocks, a single ink came
  back within reach on every fixture here. Two inks stay — they keep the distance between a one and a
  zero the same on light and dark pages — but the mutation that pretended to falsify a single ink was
  removed rather than left surviving.

### Added (2026-09-16) — forensic screen watermark

The other vector the specification names for this plugin: a watermark that reveals which account a
leaked screenshot came from. Off by default (`wm_nda_watermark`), because it paints on every screen.

- **What it is.** An 80-bit block over 10 × 8 cells of 16 px: sync word, version, account ID, the
  minute the page was served, and 20 bits of HMAC-SHA256 under the chain key. It tiles the viewport
  with every second block inverted. The browser is handed the finished bits — never the account, the
  minute or the key.
- **Reading it back.** `wp nda wasserzeichen-lesen <datei>` finds the cell grid from the gradient
  energy of the screenshot, tries all eighty block phases at three device scales, accumulates every
  block's deviation from its own mean with the carrier's sign, and decodes the strongest alignments.
  The MAC decides: an image with no watermark is answered "not found", never with an account.
  `wp nda wasserzeichen-probe` writes a test screenshot to try it on.
- **Measured limits, 2026-09-16.** Read back from 1440 × 900 and 2880 × 1800 screenshots, from dark
  pages, from unaligned crops down to 800 × 640, from JPEG down to quality 40 and over heavy noise.
  Not read back from a scaled screenshot, a crop holding one block, or a strongly photographic
  background. It names the account the page was served to, not who took the screenshot.
- **What it costs.** Grey #6e6e6e on white measures 5.10:1 and 4.49:1 under the overlay: text that
  sat just above the WCAG threshold falls below it. The suite pins that figure, so raising the alpha
  turns it red.
- Group 13 of the suite (28 assertions) renders screenshots and reads them back; twelve entries in
  `tests/mutations.php`.

### Confessio — three defences that defended nothing

The first modulation varied the alpha of black alone. Measured on a dark page it moved the luminance
by less than one step of an eight-bit channel: there was nothing to read. Opposite inks at one alpha
replaced it.

The first reader compared each cell with its neighbours. Over ordinary text the ink between two lines
moves a cell mean by tens of steps and the pattern by seven, so it failed on every page with text.
Averaging whole blocks and inverting every second one replaced it.

Then, tuning against those failures, three more mechanisms were added: a block-count floor, a
square-root normalisation of the ranking, and a soft-decision pass that doubted the weakest bits when
the MAC failed. Each was added after a measurement that seemed to show it mattered. Measured again
against the finished reader, across fourteen cases, **none of the three changed a single outcome** —
they had been covering for each other and for defects already fixed. All three were removed rather
than kept with a mutation that survives. What carries the reader is the two-stage search, the carrier
and the two inks.

### Added (2026-09-16) — click-wrap interaction record

The specification of this plugin names a "click-wrap signature with a biometric fingerprint"; the code
had neither. What it has now is the part that can be built honestly on the web platform, and its limits
are stated wherever it is shown.

- **What is recorded.** With the acceptance the browser reports the input device (mouse, pen, touch,
  keyboard), whether the button was clicked or operated from the keyboard, how long the dialog was open,
  how long between ticking the box and confirming, how far the agreement text was scrolled, the number of
  pointer samples and the distance travelled, the highest pressure value, the viewport and the device's
  offset from UTC. Ten whole numbers and two enumerations — no coordinates, no timing series. A pointer
  path is behavioural biometry and no purpose of this plugin needs one.
- **What it is sealed with.** The SHA-256 of the stored record is appended to the signed payload of its
  acceptance, so editing it, blanking it or inventing one for a row that carried none breaks the chain
  from that row on. The fingerprint is recomputed from the column on every verification and never stored
  beside it, which a second column would allow to drift.
- **What it does not claim.** It comes from the browser: it shows the dialog was operated, not by whom,
  and nothing checks whether the client reported truthfully. A mouse has no pressure sensor — Pointer
  Events prescribe the constant 0.5 while a button is down — and the screens name that constant instead
  of showing it as half pressure.
- **Compatibility.** An empty record appends nothing to the payload, so every acceptance written before
  today keeps verifying untouched. Schema 4 adds `interaction_json` through `dbDelta`; no row is re-signed.
- **Where it travels.** `POST /wm-nda/v1/accept` takes an `interaction` object, deliberately without a
  schema type so a malformed record cannot refuse the acceptance itself; `wp-login.php` carries it in the
  hidden field `wm_nda_interaction`, because that path records in PHP and never calls REST. Values outside
  their range are dropped rather than clamped, input above 2 048 bytes is dropped whole, and the schema
  number is set by the server.
- **Where it shows.** Signatures screen (collapsed per row), `wp nda list`, CSV and JSON export (with
  `interaction_sha256` and a `signature_payload` recipe), the Art. 15 privacy export, and the Art. 17
  eraser, which keeps it — it is part of the signed payload — and now names it among the retained data.
- Demo data carries records of three device kinds, so the screens show the feature with data.
- `tests/test-suite.php` group 12 (34 assertions) and 20 entries in `tests/mutations.php`. Two of the
  first attempt's assertions passed without measuring anything and the mutation run caught both: one
  tested the sanitiser's own field order instead of the canonical form, the other guarded a branch no
  result depends on. The first was rewritten, the second dropped.

### Family sheets (2026-09-16)

- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (2026-09-15, reader pass)

A reader audit on 2026-09-15 compared every document with the code and ran reproductions against the plugin's classes (22 code defects, 26 false claims). Measured before the fixes with a read-only probe on a local WordPress install (PHP 8.3) and the demo Box (PHP 8.4): the gate URL for `/?p=123&page=2` carried `page=2`; the REST refusal claimed a forensic record; the stated JSON recipe did not reproduce the checksum; WordPress rejected every `.mo` catalog (0 entries); the Box register reported "nachträglich verändert" for rows that had been signed on another installation with another key.

- **Publishing a version that could not be stored switched every gate off and reported success (High, D1).** `bump_version()` deactivated all terms before an insert that fails for a version number over 50 characters; no term was active, the admin, frontend and REST gates let everyone through and the screen said "erfolgreich veröffentlicht". The new version is stored first and one statement switches it on and the others off; version and title are checked against their columns; the screen reports a failure and the previous version stays active. The text hash covers the text as stored.
- **The register verified after its newest rows were deleted together with the tip, and after every predecessor was blanked (High, D2).** The tip option is sealed with an HMAC under the signing key and carries a key check; a missing, unsealed or rewritten tip fails verification. Access (`has_user_accepted()`) needs a chained row whose signature checks out, so a row rewritten to another account or stripped of its predecessor no longer opens the gates. Schema 3 seals the tip of a register that verifies and leaves every other register to say why. Not detected: an older sealed tip copied beforehand and put back.
- **Simultaneous acceptances forked the chain for good (D4).** Duplicate check, tip read, insert and seal run under a MySQL named lock; without the lock nothing is written.
- **The wp-config-sample placeholder counted as a signing key, and a different key was reported as altered rows (D5).** The placeholder falls back to `wp_salt( 'auth' )`; when no stored signature matches the key, the register names the key.
- **The gate URL carried the requested URL unencoded (D3).** An `&page=` in it replaced the gate's page argument and redirected in a loop; other arguments were cut from `redirect_to`.
- **The JSON checksum could not be reproduced with the stated recipe (D6), and both exports stopped at 10 000 rows without saying so (D16).** Every field but the checksum is in place before hashing; exports read every acceptance in batches. The CSV passes an explicit empty escape character, which PHP 8.4 otherwise deprecates into the file (D17).
- **"Demodaten bereinigen" deleted real acceptances from "@demo-" addresses (D7).** Demo rows are the rows with user ID 0.
- **The REST refusal said "Sicherheitsverstoß … forensisch erfasst" with nothing written (D8).** It says which acceptance is missing; the login text needs the measured sink.
- **Accounts with a display name over 100 characters could never sign, and the login went on anyway (D9).** The name is cut to the column; a login whose acceptance cannot be stored is refused.
- **After a factory reset, cached acceptances stayed valid on object caches without group flushing (D10).** Cache keys carry a salt the reset rotates.
- **A gate moved to a frontend page with `wm_nda_gate_url` redirected that page to itself (D11).**
- **The hub reported HEALTHY without the acceptances table and with every gate off (D12).** `ERROR` and `DEGRADED`; `self_test` fails without the tables; telemetry names `tables_present`.
- **The privacy tools missed acceptances after an e-mail change, reported nothing retained and kept the account meta (D13).** They match the account as well, report `items_retained` and name the account ID among the kept fields.
- **The rate limiter stored an unsalted MD5 of the IP address in the transient name (D14).** It uses the register's daily-salted HMAC.
- **The acceptance rate went over 100 % after users were deleted (D15).** Only accounts that still exist are counted.
- **Modal and admin details (D20-D22):** the focus trap let focus leave the dialog while the accept button was disabled; contrast below 4.5:1 for the success message (3.15:1), the hash label (4.34:1), the error red (4.41:1), the green docs link in the plugin list (3.77:1) and two handbook code colours; dead `get_gate_subheading()` and `versionForm`. Modal and login sheets define their own tokens; JavaScript strings come from the catalog; `class-nda-audit.php` and `class-nda-cli.php` declare strict types; inline styles on the admin screens moved to classes.
- **Legal labels on screens:** "Letzte rechtliche Signaturen", "IP-HMAC (DSGVO)" in the tables and "IP-HMAC (DSGVO Art. 5)" in the CSV header, the modal badge "GeschGehG & UrhG Schutzsystem", "DSGVO-konform" and "lassen keinen Rückschluss auf die Person zu" in the eraser messages; the handbook named "Art. 6 Abs. 1 lit. b & c" where the agreement names lit. b and f. The CSV column "Signatur-Criptogramm" is "Signatur-Hash (HMAC-SHA256)".
- **Catalogs:** regenerated from the code and repaired; the fleet script counted 5 134 double-encoded strings before, 0 after. Strings added or reworded since 2026-09-14 have no translation yet.
- **Tests:** the standalone suite runs every group in isolation and prints the fleet results line; its `$wpdb` refuses over-long strings, runs the terms UPDATE and the lock, and its `add_query_arg()` no longer encodes new values, which had hidden D1, D3 and D9. Groups 10 and 11 add the checks above; `tests/mutations.php` undoes each correction and `tests/run-mutations.sh` runs in CI. The WP-CLI suites exit non-zero on failure; the register-integrity fixtures passed the IP as the user agent. `tests/wp-runtime-nda.php` checks a live site without writing; `tests/runtime/verify-gate-http.sh` requests a URL with query arguments (19 checks).
- **Documents:** README (and DE/ES), DEVELOPERS, docs/, SECURITY, CONTRIBUTING, CLAUDE, readme.txt, llms.txt, humans.txt and the PR template corrected where they claimed more than the code (F1-F26): the register's detection, the JSON recipe, "contains only the modal", the privacy tools, "IP addresses only as HMAC", contrast figures, "Tab stays in the modal", catalog counts and locale codes, which suites empty the register, "Founder & CEO", PHP 8.1, Gutenberg and React. The README no longer embeds the marketing banner, and the package leaves `assets/images/` out.

### Fixed (2026-09-14, honest NDA gate)

- **The hub reported a broken register as healthy.** `WM_NDA_Spoke_Adapter` tested the array returned by `verify_register()` as a boolean; a non-empty array is always true, so a tampered or truncated register was `HEALTHY`, telemetry said `VERIFIED` and `self_test` succeeded with an array as `success`. The adapter now reads `['valid']`, reports `BROKEN` with the error, and no longer declares WM Activity Sentinel as a dependency (it is an optional audit sink). Test group 9.
- **Demo data forged acceptances for real accounts.** The five demo signers used user IDs 1 to 5, so importing demo data made the site's first accounts count as signed for the active version. Demo rows now carry user ID 0, and the import is refused while the register holds real signatures.
- **Deleting demo data or resetting broke the register for good.** Deleting the demo rows removed links from the chain; the factory reset truncated the table but kept the tip option, so the empty register reported "Das Register ist leer, obwohl zuletzt N Zustimmungen verzeichnet waren". Demo rows are only deleted when the register holds nothing else (then the tip goes too), and `reset_tables()` removes the tip option. The reset now writes the options the gate reads (it wrote `wm_nda_enforce_login_gate`, which nothing reads).
- **`wp nda demodaten --import` and `--reset` ended in a fatal error:** they called `WM_NDA_Seeder::seed()` and `factory_reset()`, which do not exist. `wp nda status` printed "Rechtsschild: GeschGehG § 2, UrhG § 69e, DSGVO Art. 6/7" and "NDA Gate ist aktiv und schützt das System" even with every gate off; it now lists the gate options.
- **Admin screens:** Spanish headers, guide, KPIs and preview became German; the guide no longer tells operators what the law requires; the handbook's signature formula named `NONCE_SALT` and left out the predecessor (the key is `AUTH_KEY`, the payload starts with `prev_hash`), promised "signierte PDF- und JSON-Zertifikate als unwiderlegbarer Rechtsnachweis" (there is no PDF) and said the REST lock covers every route except the signature route; the "Rechtliche Grundlagen" card said the agreement's effect rests on those laws. The retention setting ("Aufbewahrungsfrist (DSGVO Art. 17)") is applied by nothing and now says so. Demo import and delete show a refusal instead of claiming "Echte Kunden-Signaturen bleiben unberührt". KPI "signed" no longer counts demo rows.
- The JSON export's `compliance_shield` (a list of statutes printed into an evidence file) became `scope`; the REST accept message no longer says "revisionssicher"; the plugin action links lost their emoji; the uninstaller also removes the frontend gate option, three dead options and the signing user meta.

### Documentation (2026-09-14)

- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `docs/DEVELOPER-GUIDE.md`, `docs/ARCHITECTURE.md`, `docs/DSGVO-COMPLIANCE.md`, `docs/ACCESSIBILITY.md`, `docs/SECURITY-AUDIT.md`, `CLAUDE.md`, `AGENTS.md`, `llms.txt`, `CONTRIBUTING.md`, `SECURITY.md`, `readme.txt` and the header description describe what the plugin ships. Until now they promised an unshipped frontend shortcode, `wp nda export`, REST routes `/status` `/submit` `/export`, the tables `wp_wm_nda_signatures` and `wp_wm_nda_audit_trail`, hooks `wm_nda_signed`, `wm_nda_term_created` and `wm_nda_should_intercept`, GoBD PDF reports, `Cache-Control` headers for pending users, anonymisation when a WordPress user is deleted, Sentinel ledger sync and legal assessments with GeschGehG, UrhG, BGB, DSGVO and BFSG; none of those exist. The translated READMEs are generated from `README.md`.
- The **[1.1.0]** and **[1.0.0]** lines below that never shipped as described are marked.
- CI tested PHP 8.1 against a header requiring 8.2; it now runs both standalone suites on 8.2 and 8.3.
- Left for Arnold: the default agreement text and the modal's legal notice (they contain "revisionssicher" and a reference to criminal consequences under § 23 GeschGehG).

### Security (2026-09-14)

- **A wrong password recorded a legally binding signature (HIGH, measured in einer lokalen WordPress-Installation 2026-09-14):** the login check was hooked to `wp_authenticate_user`, which WordPress applies before it compares the password. A username plus a ticked box stored an acceptance in that user's name even though the login then failed with "Passwort nicht korrekt", and a username alone produced a critical `nda_bypass_attempt` audit entry and told the caller the account exists. The check now runs on `authenticate` at priority 99, after core has verified the password; application-password logins are not asked for the box. Measured before and after on the same site: a failed login with the box ticked went from one new acceptance row to none. Tests: group 5.
- **Users who had already signed could not submit the login form without ticking the box again:** the checkbox carried `required`, which the browser enforces before the server can see who is logging in. It is no longer required in the browser; the server still refuses unsigned users without it, now with an instruction ("Bitte bestätigen Sie die Vertraulichkeitsvereinbarung …") instead of "Zugriff verweigert (Sicherheitsverstoß)".
- **The admin gate no longer serves the page it claims to block (HIGH, fleet audit 2026-09-13 §6.2 #25):** until now `enforce_admin_gate()` only printed the full-screen modal in `admin_footer`, so every wp-admin screen — post lists, settings, exports — had already been generated underneath it, and removing one element in the browser's inspector showed all of it. Users without a signature for the active term are now redirected at `admin_init` (priority 1), before any admin screen is built, to `admin.php?page=wm-nda-gate`: a page without menu entry, reachable with capability `read`, that contains only the modal. After signing, the reload continues to `redirect_to` (validated against the site host) or the admin root. `admin-ajax.php` answers HTTP 403 (`wm_nda_required`) for unsigned users instead of running the action. Tests: group 7. — *the gate page shows the modal over the admin menu and notices, which remain in its HTML; `redirect_to` lost query arguments until 2026-09-15*

### Added (2026-09-14)

- **Frontend / staging layer (`template_redirect`, priority 0):** promised by the handbook and the developer guide since 1.0.0 and never present in the code. Logged-in users without a signature are redirected from any frontend page to the gate page, with the requested URL as `redirect_to`. New option `wm_nda_enforce_frontend_gate` (default on, checkbox under Einstellungen); anonymous visitors are not affected. Filter `wm_nda_gate_url` to move the gate elsewhere; the WooCommerce "prevent admin access" redirect is lifted for the gate page so the two cannot loop.

### Fixed (2026-09-14)

- **The WordPress privacy tools (Werkzeuge → Personenbezogene Daten exportieren / löschen) died with a fatal for every request:** both callbacks called `WM_NDA_Database::get_acceptances_table()` and `get_terms_table()`, methods that never existed (the class has `acceptances_table()` / `terms_table()`). The DSGVO Art. 15 export and the Art. 17 pseudonymisation promised since 1.0.0 ran for nobody. Caught by the new test group 8; verified in a real WordPress by `tests/test-nda-privacy-tools.php`.
- **An Art. 17 erasure turned the register into a "tampered" one:** the eraser also rewrote `ip_hmac`, which is part of the signed payload, so `verify_register()` reported the erased row as "nachträglich verändert" and the dashboard showed the whole register as not intact. The daily-salted IP pseudonym is now retained together with the signature hash (Art. 17 Abs. 3 lit. e), and the eraser's messages say what stays and why.
- Plugin Check (dist view, 2026-09-14): the "Signaturen & Log" menu label concatenated a badge inside `__()`, five `sprintf`-translated strings had no translators comment, `paginate_links()` output was echoed raw, the register-tip count and the acceptance list interpolated table names into SQL, and the JSON certificate echo plus the `php://output` close were unannotated. Table names now travel through `%i`; the two remaining `phpcs:ignore` lines carry their reason. `readme.txt` Stable tag matches the plugin version (1.1.0).
- Three dead admin links pointed at pages that were never registered: the dashboard widget button (`wm-nda-gate` → `wm-nda-dashboard`), the plugin action link "Signatur-Protokoll" (→ `wm-nda-signatures`) and the row meta "NDA-Vertragstext bearbeiten" (`wm-nda-terms` → `wm-nda-versions`).
- Settings, help tab, in-plugin handbook, `docs/ARCHITECTURE.md` and `docs/DEVELOPER-GUIDE.md` now describe the redirect gate; the developer guide's hook names (`rest_pre_dispatch`, `wp_authenticate_user`) and the `WM_NDA_Interceptor` / `WM_NDA_Modal` method lists match the code.
- `tests/test-nda-security.php` (WP-CLI) is self-contained: it forces `wm_nda_enforce_rest_api` on for its own run and restores the value, loads `wp-admin/includes/user.php` so the probe user is actually deleted, and removes a stale probe user first. It used to fail on any site whose REST gate was switched off.
- Login checkbox value is unslashed and sanitized before comparison; the wp-login.php nonce warning is documented as intentional (core issues no nonce there).

### Quality & Tests (2026-09-14)

- Standalone suite: group 7 (17 assertions) for the admin redirect, the gate page exemption, `index.php?page=wm-nda-gate`, the `redirect_to` host guard, admin-ajax 403, the frontend layer, the option switches, the page registration and the WooCommerce shim. Three mutation checks (admin redirect removed, `pagenow` check removed, frontend gate removed) each turn the suite red.
- `tests/runtime/verify-gate-http.sh`: 18 HTTP checks against a local WordPress (login as a subscriber, redirects, gate page, admin-ajax 403, signing through the REST route, `redirect_to` on and off host).

### Design family (hackaton phase 3, 2026-09-14)

- The six admin screens and the dashboard widget render from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) plus an NDA layer (`assets/css/nda-admin.css`) that carries only what the family does not: KPI tiles with an icon column and the acceptance bar, the modal preview, version status pills, the two-column versions layout, the filter bar, the danger zone and the handbook code samples. Every colour is a token, no focus suppression (the gate card's `outline: none` became a `:focus-visible` ring). Before this the sheet styled the header, tabs and guide only: KPI tiles, cards, status pills, columns, filter bar and alerts had no rule at all (measured 2026-09-14: 0 matching selectors).
- Native `.wrap.wm-admin`, one shared header helper with the family badge (hub/standalone), family menu icon, same enqueue handles as every WM plugin; the family sheets also load on the dashboard, where the widget already used the family classes without any sheet delivering them, and on the page the hub dispatches (`wm-suite_page_wm-prototype-nda-gate`). Sheets and scripts (admin, gate modal, login) versioned by file time.
- **The dashboard widget showed figures nothing could ever change:** it counted rows of `wm_nda_access_tokens` and `wm_nda_audit_log`, tables this plugin never creates, and read `wm_nda_gate_enabled` and `wm_nda_terms_version`, options nothing writes — so it always reported 0 tokens, 0 violations and "Standby", and named Ed25519 signatures the plugin does not use. It now shows the cockpit's own figures (`get_kpis()`: signed users of the active version, pending users, active version) and the state of the admin/frontend gate options.
- **The gate page printed a PHP deprecation and a "headers already sent" warning on every visit under WP_DEBUG:** a submenu page registered without a parent gets no title from `get_admin_page_title()`, so `admin-header.php` handed a null to `strip_tags()`. The title is now set on the page's `load-` hook, before the header runs (measured: the warning text sat above the modal in the before capture of the gate page and is gone in the after capture).
- Dashicons instead of emoji in the widget title, tabs, jump links, guide pills, preview and demo buttons and the help sidebar (those msgids changed; the catalog pass D10 picks them up). `aria-current` on the active tab; the modal preview on the dashboard is marked decorative and its fake controls left the tab order. The jump links to the preview and to the recent signatures pointed at ids that did not exist; both anchors exist now. Inline styles with hex colours in the dashboard, settings and handbook screens moved to tokenised classes.

### Known, not fixed here (2026-09-14)

- `tests/test-i18n.php` fails: every `.po` catalog in `languages/` carries double-encoded UTF-8 (150 lines per catalog, 262 in `bg_BG`), including the English ones, so the French `msgstr` for "Einstellungen" reads `RÃÂ©glages`. Fleet-wide translation decision, tracked in the hackaton plan (D10). — *fixed on 2026-09-15; the counts were wrong as well: the fleet script found 192 strings in most catalogs, 304 in `bg_BG` and none in `ar`, `fi` and `tr_TR`*

---

## [1.1.0] — 2026-08-29

### Added

- **24 Official European Union Languages:** Full binary `.mo` and source `.po` translation packages in `languages/` (`bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `en_GB`, `es_ES`, `et_EE`, `fi_FI`, `fr_FR`, `ga_IE`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv_LV`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`). — *the catalogs carried double-encoded UTF-8 (D10); the Greek, Estonian, Irish and Latvian files are named `el`, `et`, `ga`, `lv`, and Finnish has both `fi` and `fi_FI`*
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with complete architecture diagrams, hook references, database schema, and REST API guides. — *the manual described routes, tables and hooks that never shipped; rewritten from the code on 2026-09-14*
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-nda/v1` with strict nonce and permission validation. — *four routes: `/accept`, `/verify`, `/register-integrity`, `/active-terms`*
- **Zero-CDN Frontend Shortcode:** Listed in historical release notes with inline SVG icons and CSS design tokens. — *never shipped: there is no shortcode*
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**. — *the adapter returns one component array with a SHA-256 of the main file; no CycloneDX document is produced*
- **Enterprise Showcase Assets:** High-fidelity 16:9 marketing banner and cockpit showcase mockups conditioned on real screenshot topology. — *nothing records how the images were made; the banner shows features the plugin does not have*
- **Clean Uninstallation Lifecycle:** Comprehensive `uninstall.php` handler for clean database and transient cache purging. — *it left the frontend gate option and user meta behind until 2026-09-14*

### Security

- **CWE-1236 Mitigation:** Active sanitization against formula injection attacks on all CSV exports and dynamic text inputs. — *CSV export cells only*
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains. — *the chain is verified with `hash_equals()`; nothing recomputed it before `wp nda verify` existed*
- **SQL Parameterization:** Refactored all queries to use strict `$wpdb->prepare()` with parameterized placeholders. — *values go through `prepare()`; table names come from `$wpdb->prefix`*

### Quality & Tests

- Expanded standalone deterministic test suite (`tests/test-suite.php`) with green pass rate and zero external dependencies.

---

## [1.0.0] — 2026-08-25

### Initial Enterprise Release

- Core architectural implementation for IP Protection & Legal Security.
- WP-CLI management commands under `wp nda`.
- Cryptographic ledger and telemetry hooks. — *never shipped: no GoBD ledger*
- Reference text citing GeschGehG § 2 Nr. 1b, UrhG § 69e, BGB § 145 ff., DSGVO Art. 6(1)(b) & BFSG 2025. — *no legal assessment is made by the code*
