# Security Policy — WM Prototype NDA Gate

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.3.x` (`1.3.4`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-prototype-nda-gate/security/advisories/new](https://github.com/arnoldwender/wm-wp-prototype-nda-gate/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to include

1. Reproduction steps or a proof of concept.
2. The affected component: login check, admin or frontend gate, REST routes under `wm-nda/v1`, the register and its verification, exports, privacy tools, WP-CLI or the hub adapter.
3. The impact you observed (for example an acceptance recorded without valid credentials, a register change that still verifies, access to wp-admin without an acceptance).

### Attack surface

- **Public:** the login form, `GET /wm-nda/v1/verify` (own status, 60 per minute per IP), `GET /wm-nda/v1/active-terms` (60 per minute per IP).
- **Logged-in users:** `POST /wm-nda/v1/accept` (10 per minute per IP), the gate page.
- **Administrators:** admin screens, exports, `/register-integrity`, demo data and factory reset.
- **Stored data:** two tables (account data, IP hash, signatures) and the plugin's options, among them the sealed register tip. Anyone with `AUTH_KEY` can rewrite the chain and the tip consistently. Without it, changed, removed or inserted rows and a rewritten or missing tip are detected; an older sealed tip that was copied beforehand and put back is not.

Reports are acknowledged as soon as they are read; there is no contractual response time.
