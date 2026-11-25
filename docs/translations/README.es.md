# WM Prototype NDA Gate (Español)

<p align="center">
  [🇧🇬 Български](README.bg.md) • [🇨🇿 Čeština](README.cs.md) • [🇩🇰 Dansk](README.da.md) • [🇩🇪 Deutsch](../../README.de.md) • [🇬🇷 Ελληνικά](README.el.md) • [🇬🇧 English](../../README.md) • [🇪🇸 Español](../../README.es.md) • [🇪🇪 Eesti](README.et.md) • [🇫🇮 Suomi](README.fi.md) • [🇫🇷 Français](README.fr.md) • [🇮🇪 Gaeilge](README.ga.md) • [🇭🇷 Hrvatski](README.hr.md)<br/>[🇭🇺 Magyar](README.hu.md) • [🇮🇹 Italiano](README.it.md) • [🇱🇹 Lietuvių](README.lt.md) • [🇱🇻 Latviešu](README.lv.md) • [🇲🇹 Malti](README.mt.md) • [🇳🇱 Nederlands](README.nl.md) • [🇵🇱 Polski](README.pl.md) • [🇵🇹 Português](README.pt.md) • [🇷🇴 Română](README.ro.md) • [🇸🇰 Slovenčina](README.sk.md) • [🇸🇮 Slovenščina](README.sl.md) • [🇸🇪 Svenska](README.sv.md)
</p>

---

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.4%20--%207.1+-21759b.svg?logo=wordpress&logoColor=white" alt="WordPress Version" /></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3+-777bb4.svg?logo=php&logoColor=white" alt="PHP Version" /></a>
  <img src="https://img.shields.io/badge/Language-es_ES-blue.svg" alt="Español" />
  <img src="https://img.shields.io/badge/Zero--CDN-100%25%20Local-purple.svg" alt="Zero-CDN" />
  <a href="../../LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%20or%20later-brightgreen.svg" alt="License" /></a>
</p>

> **Asks logged-in users to accept a confidentiality agreement before they use wp-login, wp-admin, the frontend or the REST API, and keeps every acceptance in an HMAC-chained register with CSV and JSON export — it makes no legal assessment of the agreement**  
> **Página del plugin:** [https://arnoldwender.com/es/plugins-wordpress/wm-prototype-nda-gate/](https://arnoldwender.com/es/plugins-wordpress/wm-prototype-nda-gate/)  
> **Desarrollado con un agentic workflow:** creado con agentes de programación de IA. Revísalo y pruébalo como cualquier código de terceros antes de usarlo en producción.  
> **Support & Liability:** Zero support — reproducible bugs on [GitHub Issues](https://github.com/arnoldwender/wm-wp-prototype-nda-gate/issues) only · no private support · AS IS / no liability ([SUPPORT.md](../../SUPPORT.md))

---

## 📸 Español Enterprise Showcase

!WM Prototype NDA Gate Banner

![WM Prototype NDA Gate Dashboard UI](../../.wordpress-org/screenshot-1.png)

---

## 🟢 NIVEL 1: GUÍA RÁPIDA PARA PRINCIPIANTES Y CREADORES WEB (NO-CODE)

### ¿Qué hace este plugin por tu sitio web?
Asks logged-in users to accept a confidentiality agreement before they use wp-login, wp-admin, the frontend or the REST API, and keeps every acceptance in an HMAC-chained register with CSV and JSON export — it makes no legal assessment of the agreement

### 🚀 Cómo empezar en 2 minutos (Paso a paso sin código):
1. Install and activate the plugin (WordPress 6.4+, PHP 8.2+). Activation creates the tables and version 1.0.0 of the default agreement.
2. Read the default text under **WM NDA Gate › Versionen** and publish your own version; have it reviewed before real use.
3. Under **Einstellungen** check the four gates and set the project name.
4. Log in with a test account that has not accepted yet: without ticking the checkbox the login is refused; after ticking it the acceptance appears under **Signaturen & Log**.
5. Run `wp nda verify` or open **Signaturen & Log** to see the register check.

### 💡 Preguntas Frecuentes para Principiantes:
#### ❓ What is not included?
No gate for anonymous visitors (a site that must stay closed to them needs a login requirement of its own); no shortcode or standalone block (dynamic status is exposed via Block Bindings `wm-nda/status` and GenerateBlocks `{{wm_nda_status}}`); no PDF export; no deletion of acceptances after the retention window — the daily retention pass pseudonymises name, e-mail and user agent and keeps the row ([below](#what-the-retention-pass-does)); no e-mail notifications, bypass roles or IP allow-lists; no electronic signature in the sense of eIDAS, only a recorded click; no biometric identification — the click-wrap record counts how the dialog was operated and cannot tell one person from another; no assessment of whether the agreement or the measures satisfy GeschGehG, UrhG or DSGVO.

---

## 🟡 NIVEL 2: GUÍA PARA WEBMASTERS Y ADMINISTRADORES DE SITIOS

### What the plugin does
1. **Login checkbox.** On `wp-login.php` a 24 × 24 px checkbox with the agreement label and a button that opens the full text. The check runs on `authenticate` at priority 99, after WordPress has verified the password: a user who has not accepted the active version and did not tick the box is refused with an instruction and a `nda_bypass_attempt` audit event; ticking records the acceptance, and a login whose acceptance cannot be stored is refused. Users who already accepted log in without ticking. Application-password logins are not asked. Only `wp-login.php` shows the box: logins through `wp_login_form()`, theme or shop forms and XML-RPC refuse unsigned users with the same instruction without offering it. Option `wm_nda_require_login_checkbox`.
2. **Admin and frontend gate.** For logged-in users without an acceptance of the active version: `admin_init` (priority 1) redirects every wp-admin request to `admin.php?page=wm-nda-gate`, a page without menu entry (capability `read`) whose full-screen modal covers the admin menu and notices WordPress still prints under it, and `admin-ajax.php` answers HTTP 403 `wm_nda_required`; `template_redirect` (priority 0) sends frontend requests to the same page. The requested URL travels along encoded in `redirect_to`; after accepting through the modal the user continues there (same host only). Options `wm_nda_enforce_admin_gate`, `wm_nda_enforce_frontend_gate`; filter `wm_nda_gate_url` (a frontend page it points at is not redirected to itself, has to print the modal and load its assets, and keeps the user after signing). Anonymous visitors, WP-CLI and cron are not gated.
3. **REST lock.** `rest_pre_dispatch` answers HTTP 403 `rest_forbidden` ("Bitte bestätigen Sie zuerst die aktuelle Vertraulichkeitsvereinbarung (NDA).") to logged-in users without an acceptance, except routes under `/wm-nda/v1/`, `/wp/v2/users/me` and `/wm-hub/v1/` (filter `wm_nda_whitelisted_routes`). Option `wm_nda_enforce_rest_api`.
4. **Register.** Each acceptance stores user ID, e-mail, display name, the SHA-256 of the agreement text, an HMAC of the IP address with a daily salt, the user agent, the time (UTC), the previous signature and its own signature (HMAC-SHA256 over previous signature, user ID, term ID, text hash, time and IP hash, key `AUTH_KEY` or `wp_salt( 'auth' )`). Writes run under a MySQL named lock, so simultaneous acceptances cannot fork the chain. The newest signature and the row count are kept in `wm_nda_register_tip`, sealed with an HMAC under the same key. A user counts as signed only with a row whose signature checks out. `WM_NDA_Database::verify_register()` recomputes every row and reports an altered, removed, inserted or cut-off acceptance and a missing, unsealed or rewritten tip, as long as the key is not known; an older sealed tip that was copied beforehand and put back is not noticed. When no stored signature matches the key (a new `AUTH_KEY`, a database from another installation) it says so. `wp nda verify`, `GET /wp-json/wm-nda/v1/register-integrity` (409 when broken) and the signatures screen show the result. Audit events go to WM Activity Sentinel when it is active, otherwise to the PHP error log.
5. **Click-wrap record.** With the acceptance the browser reports what it observed while the agreement was on screen: input device (mouse, pen, touch, keyboard), how long the dialog was open, how long between ticking the box and confirming, how far the text was scrolled, how many pointer samples and how much travel, the highest pressure value, the viewport and the device's offset from UTC. Ten whole numbers and two enumerations, nothing else — no coordinates, no timing series. Everything outside its range is dropped, the whole record above 2 048 bytes is dropped, and a record that cannot be read never refuses the acceptance. What is kept is sealed: the SHA-256 of the stored record is appended to the signed payload of its row, so editing, blanking or inventing one breaks the chain from there on. It comes from the browser, so it shows that the dialog was operated, not by whom; a mouse has no pressure sensor and the screens name its constant 0.5 for what it is. Acceptances recorded before 2026-09-16 and browsers without JavaScript carry none, and those rows keep verifying unchanged.
6. **Forensic screen watermark.** Off by default (`wm_nda_watermark`). Switched on, every page a signed user sees carries a faint pattern of 16 px cells: eighty of them make a block holding a sync word, the account ID, the minute the page was served and a 20-bit HMAC under `AUTH_KEY`, and the block repeats across the viewport with every second one inverted. `wp nda wasserzeichen-lesen <datei>` reads it back out of a screenshot and names the account, or says "not found" — the HMAC decides, so it never guesses one. **Measured on 2026-09-16** (`tests/test-suite.php` group 13, synthetic screenshots of a page of text): read back from 1440 × 900 and 2880 × 1800 screenshots, from dark pages, from pages whose top half is a hero image, from crops down to 800 × 640 whether or not they are aligned to the grid, and from JPEG down to quality 40 — and, measured with a real browser against the local demo home page, from a screenshot of an actual WordPress page. **Not** read back from a screenshot that was scaled, from one holding a single block, or from a strongly photographic background; those answer "not found". It names the account the page was served to, not who took the screenshot, and the pattern costs contrast: grey #6e6e6e on white measures 5.10:1 and 4.49:1 under the overlay, so text that sat just above the WCAG threshold falls below it.
7. **Versions.** Publishing a new text stores it and then deactivates the previous version; every user has to accept again. A version number over 50 characters or a title over 255 is refused, and the previous version stays active. The default text is a German sample agreement without legal review: it cites no statute, names no vendor and lists what the register stores for each acceptance.
8. **Admin.** Menu *WM NDA Gate* (position 26) with cockpit (acceptance rate, signed users, pending users, version, recent signatures, modal preview), signatures with verification result, versions, export (CSV with formula-injection escaping, JSON with the register check and a checksum whose recipe the file states; both with every acceptance), settings with demo data and factory reset, handbook; dashboard widget. German interface on the WM Suite admin family sheets.
9. **REST and WP-CLI.** `POST /wp-json/wm-nda/v1/accept` (logged in, 10 per minute per IP), `GET /verify` (own status; others only for administrators; 60 per minute per IP via `check_public_nda_access`, filter `wm_nda_rest_rate_limit`), `GET /register-integrity` (administrators), `GET /active-terms` (public, 60 per minute per IP via `check_public_nda_access`). `wp nda status`, `wp nda list [--limit]`, `wp nda verify` (error exit when broken), `wp nda demodaten --import|--delete|--reset [--yes]`, `wp nda retention [--dry-run]`, `wp nda wasserzeichen-lesen <datei> [--porcelain]`, `wp nda wasserzeichen-probe [--user] [--datei] [--breite] [--hoehe]`.
10. **WordPress privacy tools.** Exporter and eraser for acceptances by e-mail, matching the address stored at signing and the account that holds the address now; the eraser pseudonymises name, e-mail and user agent, reports what it keeps (account ID, signature, time, version, IP hash — all part of the signed payload) and removes the account's last-acceptance meta.
11. **WM Suite Hub spoke.** Health `ERROR` without the plugin's tables or when the register does not verify, `NEEDS_CONFIG` without an active version, `DEGRADED` when every gate is off, the key comes from the database or pre-chain rows exist, `HEALTHY` otherwise; telemetry with tables, version, signatures, register state, key source, audit sink and gate options; actions `self_test` and `purge_cache`.
12. **Block Bindings and GenerateBlocks.** Registers the WordPress 6.5+ Block Bindings source `wm-nda/status` (`field` argument: `label`, `state`, `active_version`) and the GenerateBlocks 2.x dynamic tag `{{wm_nda_status}}` (`key` option: `label`, `state`, `active_version`), filtered through `wm_nda_status_value`.

### Not shipped
No gate for anonymous visitors (a site that must stay closed to them needs a login requirement of its own); no shortcode or standalone block (dynamic status is exposed via Block Bindings `wm-nda/status` and GenerateBlocks `{{wm_nda_status}}`); no PDF export; no deletion of acceptances after the retention window — the daily retention pass pseudonymises name, e-mail and user agent and keeps the row ([below](#what-the-retention-pass-does)); no e-mail notifications, bypass roles or IP allow-lists; no electronic signature in the sense of eIDAS, only a recorded click; no biometric identification — the click-wrap record counts how the dialog was operated and cannot tell one person from another; no assessment of whether the agreement or the measures satisfy GeschGehG, UrhG or DSGVO.

---

## 🔴 NIVEL 3: GUÍA PARA DESARROLLADORES & INGENIEROS DE SOFTWARE

Classes, hooks, options, the admin screen and the WM Suite Hub adapter are documented in the English manual: **[DEVELOPERS.md](../../DEVELOPERS.md)**.

---

## Datos y límites

- **What the plugin establishes:** that a logged-in account ticked or clicked an acceptance of a given text version at a given time, recorded with a chained signature that reveals later changes made without the key. It does not decide whether the agreement is valid, whether the measures count as reasonable under GeschGehG § 2, or which evidential weight the register has.
- **Outbound requests:** none.
- **Screen watermark:** nothing new is stored. The pattern is computed per request and carries the account ID and the minute in the pixels of the screen; the browser is handed the finished bits, never the account, the minute or the key.
- **Personal data:** per acceptance the user ID, e-mail, display name, user agent, the click-wrap record (input device, durations, pointer travel, viewport, offset from UTC — the last two describe the device used), time and a daily-salted HMAC of the IP address, in two tables of the site's database; the WordPress privacy tools export and pseudonymise them, and the daily retention pass pseudonymises name, e-mail and user agent once an acceptance is older than `wm_nda_retention_days` (default 90; below 30 nothing is changed). Audit events (user ID, login name, IP HMAC) go to WM Activity Sentinel or the PHP error log, which neither the privacy tools nor the uninstaller touch. Uninstalling preserves the tables, options and user meta by default unless `wm_nda_delete_data_on_uninstall` is enabled under **Einstellungen**.
- **Field by field:** [docs/DATA-PROCESSING.md](../../docs/DATA-PROCESSING.md) (German) lists what is stored where and what the privacy tools and the retention pass change. It names no legal basis: which one applies, and which information duties follow, is for the site operator to decide.

---

## 🔒 Licencia y Propiedad Intelectual

Distributed under the **GNU General Public License v2 or later**.  
Copyright (C) 2026 Arnold Wender.  
