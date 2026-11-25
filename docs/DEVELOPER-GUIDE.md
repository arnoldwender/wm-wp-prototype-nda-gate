# WM Prototype NDA Gate — Request Lifecycle and Gotchas

> **Repository:** `wm-prototype-nda-gate` | **Author:** Arnold Wender  
> Measured against the code on 2026-09-15; sections 3, 6 and 7 re-checked against 1.3.3 on 2026-11-21, sections 3, 5 and 6 against 1.3.4 on 2026-11-22. The reference (classes, options, routes, hooks, CLI) lives in [DEVELOPERS.md](../DEVELOPERS.md). The edition before 2026-09-14 listed classes named `WM_NDA_Gate_*`, routes `/status` and `/active-term`, hooks `wm_nda_term_created` and `wm_nda_should_intercept`, a script `nda-modal.js`, a `WM_NDA_Gate_Privacy` class and `Cache-Control: no-store` headers for pending users; none of those exist. The edition of 2026-09-14 advised a frontend gate page that looped and said simultaneous submissions return the existing ID, which they did not.

---

## 1. What the plugin is for

A WordPress site that shows unpublished work to logged-in customers or partners asks each account to accept a confidentiality agreement once per text version, and keeps a register of those acceptances in which a changed, removed or inserted row, or a rewritten tip, is detected as long as the key is not known. It does not keep anonymous visitors out, and it does not decide whether the agreement or the measures satisfy any law.

---

## 2. The four interception points

```text
                                  USER REQUEST
                                       │
                ┌──────────────────────┴──────────────────────┐
                ▼                                             ▼
       [Frontend Page Request]                       [REST API Request]
                │                                             │
                ▼                                             ▼
  Layer 1: Template Interceptor               Layer 3: REST API Filter
  (template_redirect hook, priority 0)        (rest_pre_dispatch filter, priority 5)
  - Is user logged in?                        - Is user logged in?
  - Has user accepted active term?            - Is route exempt (/wm-nda/v1/, /wp/v2/users/me, /wm-hub/v1/)?
  - NO: HTTP 302 → admin.php?page=wm-nda-gate - NO: Return HTTP 403 rest_forbidden
        &redirect_to=<requested URL, encoded>
                │                                             │
                ▼                                             ▼
  Layer 2: Login Interceptor                  Layer 4: Admin Interceptor
  (authenticate filter, priority 99)          (admin_init hook, priority 1)
  - Runs after core checked the password      - HTTP 302 → gate page before any admin
  - Checkbox only for unsigned users            screen is built; admin-ajax → 403 JSON
```

The gate page (`admin.php?page=wm-nda-gate`, capability `read`, no menu entry) shows the modal over the admin screen; WordPress still prints the admin menu and notices into its HTML. Once the signature is stored through `POST /wm-nda/v1/accept` the modal reloads the page and Layer 4 forwards the user to `redirect_to` — validated against the site host — or to the admin root. The requested URL is encoded into `redirect_to`: until 2026-09-15 a URL with its own `&page=` replaced the gate's page argument and the gate redirected to itself.

Sites that keep some roles out of wp-admin can point the `wm_nda_gate_url` filter at a frontend page. The frontend gate does not redirect that page to itself; the page has to print `WM_NDA_Modal::render_gate_modal( null, true )` and load `WM_NDA_Core::get_instance()->interceptor->enqueue_gate_assets()`, and after signing the user stays on it. The WooCommerce "prevent admin access" redirect is lifted for the gate page so the two do not loop. WP-CLI and cron are never gated, so an administrator locked out by a broken REST route can always run `wp option update wm_nda_enforce_admin_gate 0`. The HTTP behaviour is covered by `tests/runtime/verify-gate-http.sh` against a local WordPress.

---

## 3. The register

```text
signature_hash = HMAC-SHA256( prev_hash | user_id | term_id | term_hash | accepted_at | ip_hmac [ | sha256( interaction_json ) ] , key )
key            = AUTH_KEY, or wp_salt( 'auth' ) when AUTH_KEY is missing or the wp-config-sample placeholder
ip_hmac        = HMAC-SHA256( ip , gmdate( 'Y-m-d' ) . NONCE_SALT (or wp_salt( 'nonce' )) )
tip option     = { hash of the newest signature, number of rows, key check, HMAC over hash and count }
```

The last field is appended only when the acceptance carries a click-wrap record; rows written without one sign the six-field payload and keep verifying.

Every write takes a MySQL named lock, reads the tip, inserts and seals the new tip before it releases the lock, so two acceptances at the same moment cannot both chain to the same predecessor and a double submission returns the existing acceptance ID. `WM_NDA_Database::verify_register()` walks the table in order, recomputes each signature and compares each predecessor, then checks that the tip is present, sealed with this key and matches the last signature and the row count. That catches an altered, removed or inserted row, a cut-off end and a rewritten or deleted tip. It cannot catch someone who holds the key, nor an older sealed tip that was copied beforehand and put back. That is why the CLI and the signatures screen warn when `AUTH_KEY` is missing and the key comes from the database.

Access follows the same rule: a user counts as signed only with a chained row whose signature checks out.

A new text version gets a new `term_id`, so every user has to accept again; the earlier acceptances stay in the register with their version and keep verifying. `bump_version()` stores the new version before it switches the old one off; a version number over 50 or a title over 255 characters is refused and nothing changes.

---

## 4. Gotchas

### 4.1 Hook order on login

`wp_authenticate_user` runs before WordPress compares the password. Until 2026-09-14 the check lived there and a wrong password with the box ticked stored a signature. It now runs on `authenticate` at priority 99 (core validates at 20). A test in group 5 pins the hook and priority. When the acceptance cannot be stored (no lock, a database error) the login is refused with `wm_nda_not_recorded`.

### 4.2 WP-CLI answers "accepted" for everyone

`has_user_accepted()` returns `true` under WP-CLI unless `WM_NDA_TESTING_MODE` is defined, so server processes are never blocked. Measure signatures with a query on `wp_wm_nda_acceptances`, not with that function in `wp eval`.

### 4.3 Deleting rows breaks the chain

Any row deleted from the register makes it fail verification — by design. Demo data (rows with user ID 0) is therefore only imported into a register without real signatures and only deleted while nothing real is chained with it; the factory reset truncates everything, removes the tip and rotates the cache salt. `test-nda-interception.php` and `test-register-integrity.php` empty the register and `test-nda-privacy-tools.php` writes a probe row: run them on a disposable site only.

### 4.4 Caches

The plugin sends no cache headers of its own. A full-page cache that serves logged-in users can hide the frontend redirect; exclude logged-in sessions from page caching. The active term is cached in a transient for 12 hours and cleared on every version change; acceptances are cached per user for 6 hours under a key that includes `wm_nda_cache_salt`, cleared on signing and retired by the factory reset even on object caches without group flushing.

### 4.5 REST 401 and 403

`/accept` answers 401 without a session. Other routes answer 403 `rest_forbidden` to logged-in users without an acceptance; anonymous requests pass this filter and meet the routes' own permission checks.

### 4.6 A different key

A new `AUTH_KEY`, or a database copied from another installation, leaves every stored signature unverifiable: the register reports that no signature matches the key of this installation, every user has to accept again, and nothing re-signs the old rows. The demo Box runs a database copied from a local WordPress install and shows exactly that.

### 4.7 Login forms without the box

Only `wp-login.php` prints the checkbox. `wp_login_form()`, theme and shop forms and XML-RPC reach the same check without it and refuse unsigned users; switch the login checkbox off on such sites and let the admin and frontend gates ask.

---

## 5. WP-CLI

```bash
wp nda status
wp nda list [--limit=20]
wp nda verify
wp nda demodaten --import | --delete | --reset [--yes]
```

---

## 6. Translations

Strings use the text domain `wm-prototype-nda-gate`; source strings are German, and the scripts receive theirs from PHP. `languages/` carries `wm-prototype-nda-gate.pot` (261 entries) and 28 complete `.po` / `.mo` locale catalogs.

---

## 7. WordPress privacy tools

`WM_NDA_Exporter::export_personal_data()` and `WM_NDA_Exporter::erase_personal_data()` are registered on `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` and match the e-mail stored at signing as well as the account that holds the address now. Erasure pseudonymises name, e-mail and user agent, keeps account ID, signature, time, version, `ip_hmac` and the interaction record, which the signature covers, reports them as retained and deletes the account's last-acceptance meta. Deleting a WordPress user does not touch the register, and audit events in WM Activity Sentinel or the error log are not part of either tool.
