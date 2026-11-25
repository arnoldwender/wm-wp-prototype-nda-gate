#!/usr/bin/env bash
#
# Runtime falsifier for the admin / frontend NDA gate against a LOCAL WordPress.
#
# What it proves, with real HTTP against a real WordPress (not the in-memory suite):
#   1. A logged-in user without a signature is redirected (302) from wp-admin, from
#      index.php?page=wm-nda-gate and from the frontend to admin.php?page=wm-nda-gate.
#   2. The gate page answers 200, contains the full-screen modal and is listed in no menu.
#   3. admin-ajax.php answers 403 with code wm_nda_required for that user.
#   4. Signing through POST /wp-json/wm-nda/v1/accept (nonce taken from the gate page) opens
#      wp-admin and the frontend again, the gate page forwards to redirect_to on the site host
#      and falls back to the admin root for a foreign host.
#
# Usage:
#   tests/runtime/verify-gate-http.sh [BASE_URL] [CONTAINER]
#   defaults: http://localhost:8080  wordpress
#
# Requires: docker with WP-CLI inside the container, curl, python3.
# Side effects: creates and deletes an editor "nda-gate-probe"; the login checkbox option is
# switched off for the run and restored on exit; one acceptance row for the probe user stays in
# the register (deleting it would break the hash chain on purpose — the register is append-only).
# Exit code: 0 when every check passed, 1 otherwise. Never run against production.

set -uo pipefail

BASE="${1:-http://localhost:8080}"
CONTAINER="${2:-wordpress}"
PLUGIN="wm-prototype-nda-gate"
PROBE_USER="nda-gate-probe"
PROBE_PASS="$(python3 -c 'import secrets; print(secrets.token_urlsafe(18))')"
JAR="$(mktemp)"
GATE_PATH="/wp-admin/admin.php?page=wm-nda-gate"
FAIL=0

wp() { docker exec "$CONTAINER" wp --allow-root "$@"; }

cleanup() {
	rm -f "$JAR"
	wp user delete "$PROBE_USER" --yes >/dev/null 2>&1 || true
	wp option update wm_nda_require_login_checkbox "${CHECKBOX_BEFORE:-1}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

check() {
	local desc="$1" ok="$2"
	if [ "$ok" = "1" ]; then
		echo "  [PASS] $desc"
	else
		echo "  [FAIL] $desc"
		FAIL=$((FAIL + 1))
	fi
}

# HTTP helpers — every request carries and updates the cookie jar.
http_status() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
http_location() {
	curl -s -o /dev/null -D - -b "$JAR" -c "$JAR" "$@" | tr -d '\r' | python3 -c 'import sys
for line in sys.stdin:
    if line.lower().startswith("location:"):
        print(line.split(":", 1)[1].strip())'
}
http_body() { curl -s -b "$JAR" -c "$JAR" "$@"; }

echo "=== NDA gate runtime falsifier — $BASE ($CONTAINER) ==="

# ---------------------------------------------------------------- setup
wp plugin activate "$PLUGIN" >/dev/null 2>&1 || true
CHECKBOX_BEFORE="$(wp option get wm_nda_require_login_checkbox 2>/dev/null || echo 1)"
# Login must NOT record an acceptance, otherwise there is nothing to gate.
wp option update wm_nda_require_login_checkbox 0 >/dev/null
wp option update wm_nda_enforce_admin_gate 1 >/dev/null
wp option update wm_nda_enforce_frontend_gate 1 >/dev/null
wp user delete "$PROBE_USER" --yes >/dev/null 2>&1 || true
# The probe is an EDITOR, not a subscriber (changed 2026-09-17, both reasons measured on a local WordPress install):
#  - core answers 403 to a subscriber on edit.php?post_type=page before admin_init runs
#    (user_can_access_admin_page() — the subscriber has no edit_pages), so the query-argument check
#    below could never reach the gate with that role;
#  - WooCommerce's WC_Admin::prevent_admin_access() sends every user without edit_posts,
#    manage_woocommerce or view_admin_dashboard to My Account on admin_init, so once WooCommerce is
#    active a signed subscriber never gets profile.php → 200.
# The interceptor exempts no role (only WP-CLI and cron), so an editor exercises the same gate.
wp user create "$PROBE_USER" "$PROBE_USER@example.invalid" --role=editor --user_pass="$PROBE_PASS" >/dev/null
check "probe editor exists" "$( [ -n "$(wp user get "$PROBE_USER" --field=ID 2>/dev/null)" ] && echo 1 || echo 0 )"

# ---------------------------------------------------------------- login
curl -s -o /dev/null -c "$JAR" "$BASE/wp-login.php"
curl -s -o /dev/null -b "$JAR" -c "$JAR" \
	--data-urlencode "log=$PROBE_USER" \
	--data-urlencode "pwd=$PROBE_PASS" \
	--data-urlencode "wp-submit=Log In" \
	--data-urlencode "testcookie=1" \
	--data-urlencode "redirect_to=$BASE/wp-admin/" \
	"$BASE/wp-login.php"
check "login issued an auth cookie" "$( grep -q 'wordpress_logged_in' "$JAR" && echo 1 || echo 0 )"

# ---------------------------------------------------------------- unsigned: everything is gated
LOC="$(http_location "$BASE/wp-admin/")"
check "wp-admin/ → 302 to the gate page ($LOC)" "$( [[ "$LOC" == *"$GATE_PATH"* ]] && echo 1 || echo 0 )"
check "redirect_to carries the requested admin URL" "$( [[ "$LOC" == *"redirect_to="* ]] && echo 1 || echo 0 )"

LOC="$(http_location "$BASE/wp-admin/profile.php")"
check "wp-admin/profile.php → gate" "$( [[ "$LOC" == *"$GATE_PATH"* ]] && echo 1 || echo 0 )"

# A requested URL with its own query arguments: until 2026-09-15 they were not encoded, "&page=" replaced the
# gate's page argument and the gate redirected to itself (reader audit D3).
LOC="$(http_location "$BASE/wp-admin/edit.php?post_type=page&paged=2")"
check "edit.php?post_type=page&paged=2 → gate with the whole URL encoded in redirect_to ($LOC)" "$( [[ "$LOC" == *"$GATE_PATH"* ]] && [[ "$LOC" == *"paged%3D2"* ]] && echo 1 || echo 0 )"

# Core resolves the page's parent as index.php and dies with 403 before admin_init, because the
# hook "dashboard_page_wm-nda-gate" is not registered (wp-admin/includes/menu.php →
# user_can_access_admin_page()). Either outcome is acceptable: 403 with nothing served, or our
# own 302 to the gate. What must never happen is a 200 — the dashboard under the slug.
IDX_STATUS="$(http_status "$BASE/wp-admin/index.php?page=wm-nda-gate")"
LOC="$(http_location "$BASE/wp-admin/index.php?page=wm-nda-gate")"
check "index.php?page=wm-nda-gate serves nothing (the slug alone unlocks nothing) [$IDX_STATUS $LOC]" "$( { [ "$IDX_STATUS" = "403" ] || [[ "$LOC" == *"$GATE_PATH"* ]]; } && echo 1 || echo 0 )"

LOC="$(http_location "$BASE/")"
check "frontend / → gate" "$( [[ "$LOC" == *"$GATE_PATH"* ]] && echo 1 || echo 0 )"

GATE_HTML="$(http_body "$BASE$GATE_PATH")"
check "gate page answers 200" "$( [ "$(http_status "$BASE$GATE_PATH")" = "200" ] && echo 1 || echo 0 )"
check "gate page contains the full-screen modal" "$( grep -q 'wm-nda-gate-overlay wm-nda-gate-fullscreen' <<<"$GATE_HTML" && echo 1 || echo 0 )"
# Only the admin menu counts: admin-header.php prints a <link rel="canonical"> with the page URL.
MENU_LINKS="$(python3 -c '
import re, sys
html = sys.stdin.read()
m = re.search(r"<ul id=\"adminmenu\".*?</ul>\s*</div>", html, re.S)
print(len(re.findall(r"page=wm-nda-gate", m.group(0))) if m else "no-menu")' <<<"$GATE_HTML")"
check "gate page is linked from no menu (links in #adminmenu: $MENU_LINKS)" "$( [ "$MENU_LINKS" = "0" ] && echo 1 || echo 0 )"

AJAX_BODY="$(http_body -X POST --data 'action=heartbeat' "$BASE/wp-admin/admin-ajax.php")"
AJAX_STATUS="$(http_status -X POST --data 'action=heartbeat' "$BASE/wp-admin/admin-ajax.php")"
check "admin-ajax.php → 403 wm_nda_required ($AJAX_STATUS)" "$( [ "$AJAX_STATUS" = "403" ] && grep -q 'wm_nda_required' <<<"$AJAX_BODY" && echo 1 || echo 0 )"

# ---------------------------------------------------------------- sign through the plugin's own REST route
read -r NONCE TERM_ID <<<"$(python3 -c '
import re, sys
html = sys.stdin.read()
n = re.search(r"data-nonce=\"([^\"]+)\"", html)
t = re.search(r"data-term-id=\"(\d+)\"", html)
print((n.group(1) if n else ""), (t.group(1) if t else "0"))' <<<"$GATE_HTML")"
check "gate page exposes nonce and term id" "$( [ -n "$NONCE" ] && [ "$TERM_ID" != "0" ] && echo 1 || echo 0 )"

ACCEPT="$(http_body -X POST -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" \
	--data "{\"term_id\":$TERM_ID}" "$BASE/wp-json/wm-nda/v1/accept")"
check "POST /wm-nda/v1/accept → success" "$( grep -q '"success":true' <<<"$ACCEPT" && echo 1 || echo 0 )"

# ---------------------------------------------------------------- signed: gate forwards, admin and frontend open
LOC="$(http_location "$BASE$GATE_PATH&redirect_to=$BASE/wp-admin/profile.php")"
check "signed user on the gate page → redirect_to ($LOC)" "$( [ "$LOC" = "$BASE/wp-admin/profile.php" ] && echo 1 || echo 0 )"

LOC="$(http_location "$BASE$GATE_PATH&redirect_to=https://evil.example.org/phish")"
check "foreign redirect_to → admin root ($LOC)" "$( [[ "$LOC" == "$BASE/wp-admin/"* ]] && [[ "$LOC" != *"evil.example.org"* ]] && echo 1 || echo 0 )"

check "wp-admin/profile.php → 200 after signing" "$( [ "$(http_status "$BASE/wp-admin/profile.php")" = "200" ] && echo 1 || echo 0 )"
check "frontend / → 200 after signing" "$( [ "$(http_status "$BASE/")" = "200" ] && echo 1 || echo 0 )"
AJAX_BODY="$(http_body -X POST --data 'action=heartbeat' "$BASE/wp-admin/admin-ajax.php")"
check "admin-ajax.php no longer answers wm_nda_required" "$( grep -q 'wm_nda_required' <<<"$AJAX_BODY" && echo 0 || echo 1 )"

echo "=== $FAIL check(s) failed ==="
[ "$FAIL" -eq 0 ]
