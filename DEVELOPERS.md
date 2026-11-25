# WM Prototype NDA Gate — Developer Manual

> Measured against the code in this repository on 2026-09-15, after a reader audit that found this manual promising more than the code did (register detection, JSON checksum, redirect after signing, privacy tools). The previous edition before 2026-09-14 documented REST routes `/status` `/submit` `/export`, the tables `wp_wm_nda_signatures` and `wp_wm_nda_audit_trail`, a `wm_nda_signed` hook, a Gutenberg block and a `wm_suite_hub_register_spoke` registration; none of those exist here. The request lifecycle is drawn in [docs/DEVELOPER-GUIDE.md](docs/DEVELOPER-GUIDE.md).

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-prototype-nda-gate.php` | Header, constants (`WM_NDA_GATE_VERSION` / `_FILE` / `_PATH` / `_URL` / `_BASENAME`), requires, hub spoke registration, single-site & `$network_wide` activation (`wm_nda_activate_plugin()` -> `wm_nda_provision_site()`), `wp_initialize_site` multisite provisioning (`wm_nda_initialize_site()`), schema upgrade on `plugins_loaded` (1), `WM_NDA_Retention::init()` on `plugins_loaded` (5), deactivation (transient, retention event), text domain, plugin action links. |
| `includes/class-nda-core.php` | Singleton: interceptor hooks, REST routes on `rest_api_init`, Block Bindings (`wm-nda/status`) and GenerateBlocks dynamic tag (`wm_nda_status`), privacy exporter and eraser, admin hooks in the admin. |
| `includes/class-nda-interceptor.php` | Login checkbox and check, admin gate, gate page, frontend gate, REST gate, gate URL helpers. |
| `includes/class-nda-database.php` | Tables, default term, active term (transient), acceptance check (object cache), `record_acceptance()`, register lock, signatures, sealed tip, `verify_register()`, versions, listing, counts, KPIs, reset. |
| `includes/class-nda-evidence.php` | `WM_NDA_Evidence`: allow-list sanitiser, canonical form and fingerprint of the click-wrap interaction record, plus its German description. |
| `includes/class-nda-watermark.php` | `WM_NDA_Watermark`: block encoder, overlay markup and the extractor that reads a screenshot back. |
| `includes/class-nda-retention.php` | `WM_NDA_Retention`: the daily event `wm_nda_apply_retention` and `apply()`, which pseudonymises acceptances older than `wm_nda_retention_days` (§9). |
| `includes/class-nda-modal.php` | `WM_NDA_Modal::render_gate_modal()`. |
| `includes/class-nda-rest-api.php` | `/accept`, `/verify`, `/register-integrity`, `/active-terms` under `wm-nda/v1`. |
| `includes/class-nda-exporter.php` | CSV and JSON export, WordPress privacy exporter and eraser. |
| `includes/class-nda-audit.php` | `WM_NDA_Audit::record()` (WM Activity Sentinel or `error_log`), `is_forensic()`. |
| `includes/class-nda-seeder.php` | Demo data (guarded), factory reset. |
| `includes/class-nda-admin.php` | Six admin screens, forms, exports, help tabs, dashboard widget. |
| `includes/class-nda-cli.php` | `wp nda` with `status`, `list`, `verify`, `demodaten`, `retention`, `wasserzeichen-lesen`, `wasserzeichen-probe`. |
| `includes/class-nda-i18n.php` | Default German sample agreement (no statutes, lists what the register stores) and gate strings. |
| `includes/class-nda-spoke-adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/css/` | `wm-admin-tokens.css`, `wm-admin-ui.css` (hub copies, pinned by `tests/test-ui-family.php`), `nda-admin.css`, `nda-login.css`, `nda-gate-modal.css` (the last two define their own tokens). |
| `assets/js/` | `nda-gate-modal.js` (focus trap, accept via REST, messages from data attributes, interaction record), `nda-watermark.js` (paints the watermark block), `nda-admin.js` (confirmation from `wmNdaAdmin`). |
| `languages/` | Template and 28 complete `.po` / `.mo` pairs (261 entries each). |
| `uninstall.php` | Preserves options, user meta and both tables unless `wm_nda_delete_data_on_uninstall` is enabled; when enabled, deletes fourteen options, transients and three user meta keys, and drops both tables. |

---

## 2. Storage

`wp_wm_nda_terms`: `term_id`, `term_version` (VARCHAR 50), `term_title` (VARCHAR 255), `term_content`, `term_hash` (SHA-256 of the stored content), `is_active`, `created_at`, `created_by`; unique (`term_version`, `term_hash`).

`wp_wm_nda_acceptances`: `acceptance_id`, `user_id`, `term_id`, `user_email` (VARCHAR 100), `user_name` (VARCHAR 100; longer display names are cut), `term_hash_at_signing`, `ip_hmac`, `user_agent`, `interaction_json` (TEXT, the canonical click-wrap record or empty), `accepted_at` (UTC), `prev_hash`, `signature_hash`.

| Option | Written by | Read by |
| --- | --- | --- |
| `wm_nda_require_login_checkbox` | activation, settings, factory reset | login checkbox and check, adapter |
| `wm_nda_enforce_admin_gate` | activation, settings, factory reset | admin gate, widget, adapter |
| `wm_nda_enforce_frontend_gate` | activation, settings, factory reset | frontend gate, widget, adapter |
| `wm_nda_enforce_rest_api` | activation, settings, factory reset | REST gate, adapter |
| `wm_nda_project_name` | activation, settings | modal subtitle, settings screen, CLI status, JSON export; the default agreement text when it is seeded (activation without a term, factory reset) |
| `wm_nda_retention_days` | activation (`autoload = false`), settings (`autoload = false`), factory reset (`autoload = false`) | `WM_NDA_Retention::configured_days()` (daily event, `wp nda retention`), settings screen |
| `wm_nda_register_tip` | `record_acceptance()`, seeder, schema 3 migration (`seal_tip()` only, `autoload = false`) | `verify_register()` |
| `wm_nda_watermark` | activation (0), settings, factory reset (0) | `WM_NDA_Watermark::should_render()` |
| `wm_nda_delete_data_on_uninstall` | activation (0, `autoload = false`), settings (`autoload = false`), factory reset (0, `autoload = false`) | `uninstall.php`, settings screen |
| `wm_nda_cache_salt` | activation, schema upgrade, factory reset (`autoload = true`) | acceptance cache keys |
| `wm_nda_schema_version` | activation (`autoload = false`), upgrade (`autoload = false`) | upgrade check (current: 4) |

Transient `wm_nda_active_term` (12 h), object cache group `wm_nda_gate` (acceptance per user, term and cache salt, 6 h), transients `wm_nda_rl_<first 32 hex of the daily-salted IP HMAC>` and `wm_nda_rl_pub_<first 32 hex of the daily-salted IP HMAC>` (REST rate limits). Cron event `wm_nda_apply_retention` (daily while `wm_nda_retention_days` is 30 or more; removed below that and on deactivation). User meta on signing: `wm_nda_last_accepted_term_id`, `wm_nda_last_accepted_at`.

---

## 3. Register

- **Signature:** `HMAC-SHA256( "prev_hash|user_id|term_id|term_hash|accepted_at|ip_hmac", key )`, with `|sha256( interaction_json )` appended when the acceptance carries a click-wrap record; key `AUTH_KEY` when it is defined and not the wp-config-sample placeholder (`chain_key_source()` = `wp-config`), else `wp_salt( 'auth' )`. The first chained row's predecessor is 64 zeros. An empty record appends nothing, so every row written before schema 4 signs the payload it was signed with and keeps verifying.
- **IP hash:** `HMAC-SHA256( ip, gmdate( 'Y-m-d' ) . ( NONCE_SALT or wp_salt( 'nonce' ) ) )`.
- **Tip:** `{hash, count, key, mac}` in `wm_nda_register_tip`: the newest signature, the row count, a 16-character check derived from the key and `HMAC-SHA256( "wm-nda-tip|hash|count", key )`. Written only by `seal_tip()`.
- **`record_acceptance()`:** takes the register lock (`GET_LOCK`, 10 s; without it nothing is written and it returns `false`), returns the existing ID when the user already holds a valid acceptance of the term, reads the tip, inserts inside a transaction, seals the new tip, releases the lock; then clears the cache, writes user meta, fires `wm_nda_accepted` and records `nda_consent_signed`. On SQLite the lock is not available and writes are not serialised.
- **Access:** `has_user_accepted()` needs a chained row of the user and term whose signature checks out with the current key. Rows written before the chain existed (empty `prev_hash`) give no access.
- **`verify_register()`:** `{valid, checked, unchained_legacy, broken_at_id, error, key_source}`. Rows with an empty `prev_hash` before the first chained row count as `unchained_legacy`; after it, as an insertion. Checks predecessor and recomputed signature of every chained row, then the tip: present, sealed, sealed with this key, seal matching, hash and count matching the rows. When no stored signature matches the key, the error names the key instead of an altered row. An empty table with a tip count > 0 fails. Not detected: an older sealed tip copied beforehand and put back; anything done by someone who holds the key.
- **Interaction record:** `WM_NDA_Evidence::sanitize()` keeps ten integers within closed ranges (`move_samples`, `path_px`, `pressure_max_permille`, `pressure_samples`, `dwell_ms`, `check_to_accept_ms`, `scroll_pct`, `viewport_w`, `viewport_h`, `tz_offset_min`) and two enumerations (`pointer_type` = mouse/pen/touch/keyboard/unknown, `accept_input` = pointer/keyboard), drops everything else, refuses input over 2 048 bytes and sets `schema` itself. `canonical_json()` sorts the keys; `fingerprint()` is the SHA-256 of that string, derived from the column on every verification, never stored beside it. No coordinates and no timing series are collected. The record is asserted by the browser: it shows the dialog was operated, not by whom.
- **Schema 4 migration:** `dbDelta` adds `interaction_json` empty. Nothing is re-signed and no row changes.
- **Screen watermark:** `WM_NDA_Watermark` builds an 80-bit block — 8 bits sync word `0xB2`, 4 version, 24 account ID, 24 minutes since 2026-01-01, 20 bits of HMAC-SHA256 under the chain key — and hands the page its hexadecimal. `assets/js/nda-watermark.js` paints it as 16 px cells at 3 % alpha, black for a one and white for a zero, tiled with every second block inverted (`(bx + by) % 2`). The extractor locates the cell grid from the per-column and per-row gradient energy, tries all eighty block phases at three device scales, accumulates every block's deviation from its own mean with the carrier's sign and **weighted by the inverse of that block's own variance** (a block over a hero image swings by a hundred steps and the pattern by seven), ranks alignments by sync correlation times magnitude and decodes the best 24; the MAC decides, so an image without a watermark reports "not found". Images above `MAX_PIXELS` (2 000 000) are reduced by a whole factor first, which is also how a 2× screenshot lands on the 1× grid. Reading a 1440 × 900 screenshot takes about a fifth of a second.
- **Schema 3 migration:** `seal_existing_tip()` seals the tip of a register that verifies under the unsealed rules and whose tip names the last row and the row count (a register of pre-chain rows only is sealed with the genesis hash). Other registers keep their tip and fail verification with the reason.
- **Versions:** `bump_version()` sanitises, refuses a version over 50 or a title over 255 characters, inserts the new term inactive (or finds an identical version and hash), then one `UPDATE … CASE` activates it and deactivates the rest; clears transient and cache. Returns `false` when nothing was stored; the previous version stays active.
- **Reset:** `reset_tables()` deletes the tip option, rotates the cache salt, truncates both tables and seeds version 1.0.0.

---

## 4. Gates

| Where | Hook | Condition | Effect |
| --- | --- | --- | --- |
| Login | `authenticate` (99, 3 args) | valid credentials, active term, box option on, user not accepted, no application password | without `wm_nda_accept=1`: `WP_Error wm_nda_required` + `nda_bypass_attempt` (critical); with it: acceptance recorded, or `WP_Error wm_nda_not_recorded` when it could not be stored |
| wp-admin | `admin_init` (1) | logged in, option on, not WP-CLI or cron, user not accepted | 302 to the gate page with the requested URL encoded in `redirect_to`; admin-ajax → JSON 403 `wm_nda_required` |
| Gate page | `admin_menu`, `load-admin_page_wm-nda-gate` | `admin.php?page=wm-nda-gate`, capability `read` | the modal over the admin screen WordPress still builds (menu, notices in the HTML); signed users are forwarded to `redirect_to` (same host) or the admin root |
| Frontend | `template_redirect` (0) | logged in, option on, not admin/ajax/cron/CLI, user not accepted, request not on the page the gate URL names | 302 to the gate page |
| REST | `rest_pre_dispatch` (5) | option on, logged in, user not accepted, route not whitelisted | `WP_Error rest_forbidden` 403 "Bitte bestätigen Sie zuerst die aktuelle Vertraulichkeitsvereinbarung (NDA)." |

`has_user_accepted()` returns `true` under WP-CLI unless `WM_NDA_TESTING_MODE` is defined.

Only `wp-login.php` prints the box (`login_form`). Logins through `wp_login_form()`, theme or shop forms and XML-RPC reach the same check without it and are refused for unsigned users; on such sites switch the login checkbox off and let the admin and frontend gates ask.

A gate URL moved to a frontend page with `wm_nda_gate_url` is exempt from the frontend redirect; that page has to call `WM_NDA_Modal::render_gate_modal( null, true )` and load `WM_NDA_Core::get_instance()->interceptor->enqueue_gate_assets()`. After signing, the modal reloads that page; nothing forwards to `redirect_to` there.

---

## 5. Hooks the plugin offers

| Type | Name | Arguments |
| --- | --- | --- |
| action | `wm_nda_accepted` | `$acceptance_id, $user_id, $term_id, $signature_hash` |
| action | `wm_nda_bypass_attempt` | `$user_id, $term_id` |
| action | `wm_nda_audit_degraded` | `$action, $user_id, $context` — an audit event went to `error_log` |
| filter | `wm_nda_gate_url` | `$url, $redirect_to` |
| filter | `wm_nda_whitelisted_routes` | `$prefixes, $request` |
| filter | `wm_nda_rest_rate_limit` | `$limit, $request` — public REST requests per minute per salted IP HMAC (default 60) |
| filter | `wm_nda_status_value` | `$value, $field, $user_id` — status string returned by Block Bindings (`wm-nda/status`) and GenerateBlocks (`{{wm_nda_status}}`) |

---

## 6. REST

| Method | Route | Permission | Response |
| --- | --- | --- | --- |
| `POST` | `/wp-json/wm-nda/v1/accept` | logged in; 10 per minute per IP | `term_id` must be the active term (400 otherwise); optional `interaction` object, deliberately without a schema type so a malformed record cannot refuse the acceptance; `200 {success, message, acceptance_id, user_id, term_version, accepted_at, interaction_recorded}`, 429, 500 (also when the register lock was not obtained) |
| `GET` | `/wp-json/wm-nda/v1/verify` | public (`check_public_nda_access`, 60 per minute per IP, `manage_options` bypass) | own status `{user_id, is_accepted, active_version}`; another `user_id` only for `manage_options` (403); 429 when rate-limited |
| `GET` | `/wp-json/wm-nda/v1/register-integrity` | `manage_options` | `verify_register()`; 409 when invalid |
| `GET` | `/wp-json/wm-nda/v1/active-terms` | public (`check_public_nda_access`, 60 per minute per IP, `manage_options` bypass) | active term with content and hash; 404 without one; 429 when rate-limited |

The watermark has no route: it is painted from the markup `WM_NDA_Watermark::render_overlay()` prints into `wp_footer` and `admin_footer`, and read back only through WP-CLI.

---

## 7. WP-CLI

```bash
wp nda status                      # active version, text hash, signature count, gate options
wp nda list [--limit=20]           # recent acceptances with their interaction record
wp nda verify                      # error exit when the register does not verify; warns without AUTH_KEY
wp nda demodaten --import          # five demo rows with user_id 0; refused when real signatures exist
wp nda demodaten --delete          # deletes the rows with user_id 0; refused while real signatures are chained with them
wp nda demodaten --reset [--yes]   # factory reset (asks for confirmation; --yes answers it, for scripts)
wp nda retention [--dry-run]       # one retention pass now (§9); --dry-run counts and writes nothing
wp nda wasserzeichen-lesen <datei> # reads the screen watermark out of a screenshot; --porcelain prints the account ID alone
wp nda wasserzeichen-probe         # writes a test screenshot carrying the watermark (--user, --datei, --breite, --hoehe)
```

---

## 8. Admin

Menu `wm-nda-dashboard` (position 26, `manage_options`) with submenus `wm-nda-signatures`, `wm-nda-versions`, `wm-nda-export`, `wm-nda-settings`, `wm-nda-docs`; the pending count appears as a badge. Forms on `admin_init` with nonces: settings, new version (redirect with `bumped` or `bump_failed`, which says the previous version stays active), demo import, demo delete, factory reset. Exports on `admin_init` for `action=wm_nda_export_csv|json` (nonce, `manage_options`). Help tabs on screens whose ID contains `wm-nda`. Widget `wm_nda_gate_widget` with signed, pending and version from `get_kpis()`. Assets on hooks containing `wm-nda` or `wm-prototype-nda-gate` and on `index.php`; the version confirmation text reaches `nda-admin.js` through `wp_localize_script()`.

KPIs count signed accounts that still exist (join with the users table); "pending" is every existing account without an acceptance.

---

## 9. Export and privacy

- **CSV:** semicolon-separated with BOM, RFC 4180 quoting (empty escape character), every cell through `sanitize_csv_cell()` (leading `= + - @ \t \r` get a quote), every acceptance in batches of 1 000 (`get_all_acceptances()`).
- **JSON:** `WM_NDA_Exporter::build_json_certificate()`: metadata (`issuer`, `system_url`, `generated_at_utc`, `project_name`, `total_signatures`, `scope`, `signature_payload`, `master_checksum_method`, `master_checksum_scope`), term definition, every signature record oldest first with its `interaction` and `interaction_sha256`, `register_integrity`, and `master_checksum_sha256`, taken last: removing that one field and re-encoding with the stated flags reproduces it. The method and the key `certificate_metadata` keep their names from 1.2 because the stated recipe names the key; the file is no certificate. The download is `wm-nda-audit-register-<date>.json` (until 1.3.3 `wm-nda-audit-certificate-…`).
- **Privacy tools:** exporter and eraser `wm-nda-signatures` match the e-mail stored at signing and the account that holds the address now. The exporter lists the interaction record as well. The eraser pseudonymises `user_name`, `user_email`, `user_agent`, keeps account ID, signature, time, version, `ip_hmac` and `interaction_json` (all part of the signed payload, so rewriting them would report the register as tampered), reports `items_retained` naming the record, and deletes the account's `wm_nda_last_accepted_*` meta. Audit events are not touched.
- **Retention:** `WM_NDA_Retention::apply()` runs on the daily event and through `wp nda retention`. `configured_days()` reads `wm_nda_retention_days`, clamps it to 3650 and reads anything below 30 as "keep everything" (no event, nothing changed). Rows with `accepted_at` before the cutoff get `user_name` `[Aufbewahrungsfrist abgelaufen]`, `user_email` `retained-<acceptance_id>@privacy.local` and `user_agent` `[Anonymisiert]`, at most 500 per pass; a second pass finds nothing. No row is deleted or re-signed, and `ip_hmac` and `interaction_json` stay: both are part of the signed payload (§3).

---

## 10. WM Suite Hub spoke

`WM_NDA_Spoke_Adapter`: slug `wm-prototype-nda-gate`, version from the constant; health `ERROR` without the plugin's tables or when `verify_register()` fails, `NEEDS_CONFIG` without an active term, `DEGRADED` when all four gates are off, the key comes from the database or pre-chain rows exist, `HEALTHY` otherwise; SBOM with the SHA-256 of the main file, the licence as the SPDX id `GPL-2.0-or-later` alone (until 2026-11-05 `Proprietary`, with an id and a name) and two feature properties; no dependencies (Sentinel is an optional audit sink); settings URL `admin.php?page=wm-nda-settings`; telemetry `tables_present`, `active_version`, `active_term_hash`, `total_signatures`, `register_integrity` (`VERIFIED` / `BROKEN`), `register_error`, `unchained_legacy`, `key_source`, `audit_sink`, four gate flags; actions `self_test` / `verify_register` (boolean success, false without the tables) and `purge_cache`.

---

## 11. Known drift (documented, not fixed)

1. The default agreement text is a sample without legal review. Since 1.3.3 (decided 2026-11-21) it, the modal notice, the checkbox and the accept button cite no statute and claim no binding effect, and the eraser and retention texts give the signed chain, not Art. 17 DSGVO, as the reason for what they keep (test group 19). Since 1.3.4 (decided 2026-11-22) `docs/DATA-PROCESSING.md` (until then `docs/DSGVO-COMPLIANCE.md`) names no legal basis, the help tab and the handbook no longer say a new version invalidates earlier acceptances, and nothing is labelled a certificate.
2. The retention pass pseudonymises name, e-mail and user agent and deletes nothing: `ip_hmac` and the click-wrap record are signed and stay with the row unless the plugin is uninstalled with `wm_nda_delete_data_on_uninstall` enabled.
3. `verify_register()` reads the whole table on every call (signatures screen, hub).
4. KPI "pending" counts all registered users without an acceptance.
5. Two WP-CLI suites empty the register, one writes and deletes a probe row.
6. The gate page covers the admin chrome instead of leaving it out.
7. A new `AUTH_KEY` or a copied database leaves every signature unverifiable; there is no re-signing.
8. The screen watermark survives cropping and JPEG down to quality 40, not scaling, rotation or a photograph of the screen; a strongly photographic background defeats it. All of those answer "not found".
9. The overlay costs contrast: #6e6e6e on white goes from 5.10:1 to 4.49:1, so text just above the WCAG threshold falls below it.
10. The interaction record comes from the browser. It is sealed against later editing, not against a client that reported something else in the first place.

---

## 12. Tests

```bash
php tests/test-suite.php            # groups 1-14: seeding, IP hash, chain, CSV escaping, login check and hook order, adapter, admin and frontend gate, privacy tools, demo data, reset, CLI, wording, uninstall; reader audit 2026-09-15 (D1-D22): bump, sealed tip, key, gate URL, REST text, lock, long names, exports, demo rows, cache salt, health, privacy after an e-mail change, rate-limit key, KPIs, focus trap, tokens and contrast, catalogs, documents; click-wrap record (group 12): sanitiser, payload compatibility, seal, both transports, wording, exports and privacy; watermark (group 13): block, round trip through rendered screenshots, measured limits, overlay and client constants; retention (group 14): floor and range, off, dry run, pseudonymisation, no row deleted, `ip_hmac` untouched, idempotence
bash tests/run-mutations.sh         # undoes each correction listed in tests/mutations.php; the suite must turn red
php tests/watermark-envelope.php --check   # renders screenshots and reads them back: the table behind the documented watermark limits, exit 1 when a case disagrees
wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/wp-runtime-nda.php --user=1   # read-only runtime check; exit 1 when an option changed
tests/runtime/verify-gate-http.sh   # HTTP against a local WordPress
wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/test-nda-security.php --allow-root      # creates and deletes a probe user
wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/test-audit-fallback.php --allow-root    # writes one audit event
```

---

## 13. Security disclosure

See [SECURITY.md](SECURITY.md).
