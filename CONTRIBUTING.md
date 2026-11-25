# Contributing to WM Prototype NDA Gate

Thank you for your interest in contributing to **WM Prototype NDA Gate**. As part of the **WM Suite** WordPress plugin family, this repository keeps its documentation measured against its code.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-prototype-nda-gate/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ Strict Typing:** Every shipped PHP file declares `declare(strict_types=1);` (the suite checks it).
2. **Zero-CDN Architecture:** No external resources (CDNs, Google Fonts, external telemetry) may be loaded at runtime.
3. **Register invariants:** every acceptance is signed with HMAC-SHA256 over its predecessor and verified with `hash_equals()`; every write takes the register lock and ends with `WM_NDA_Database::seal_tip()`. A change to the signed payload needs a migration and a test that verification still catches altered, removed, inserted and truncated rows and a rewritten tip. Never delete or re-sign rows.
4. **Authenticated acceptance only:** an acceptance is recorded only after WordPress verified the credentials.
5. **CWE-1236:** tabular exports pass every cell through `WM_NDA_Exporter::sanitize_csv_cell()`.
6. **No legal verdicts** in UI, CLI, exports or docs.
7. **Tests:** a fix comes with an assertion in `tests/test-suite.php` that fails without it and an entry in `tests/mutations.php`.

---

## Development Workflow

```bash
# 1. Clone the repository
git clone https://github.com/arnoldwender/wm-wp-prototype-nda-gate.git
cd wm-prototype-nda-gate

# 2. Run the standalone suites and the mutation check
php tests/test-suite.php
bash tests/run-mutations.sh

# 3. Against a local WordPress
wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/wp-runtime-nda.php --user=1   # writes nothing
tests/runtime/verify-gate-http.sh                                                         # creates and deletes a probe user
```

---

## Commit Conventions

- Use the format `[Feature] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.
- Pull requests that change behaviour include assertions in `tests/test-suite.php`, and update `README.md`, `DEVELOPERS.md` and `docs/` when what the plugin ships changes.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
