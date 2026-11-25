# Barrierefreiheit — WM Prototype NDA Gate

> Was Login-Checkbox, Signatur-Modal und Backend für Tastatur, Screenreader und Kontrast tun, gemessen am Code (2026-09-15). Eine Prüfung nach WCAG 2.2 AA liegt nicht vor; die Angaben unten ersetzen sie nicht.

---

## 1. Kontraste

* **Login-Checkbox und Signatur-Modal** (`nda-login.css`, `nda-gate-modal.css`) definieren ihre Farben als Tokens am eigenen Wurzelelement. `tests/test-suite.php` misst daraus 17 Textpaare gegen 4.5:1 und zwei Fokus- bzw. Rahmenfarben gegen 3:1. Werte vom 2026-09-15: Vertragstext `#1e293b` auf Weiß 14.63:1; Überschriften `#0f172a` auf Weiß 17.85:1; Hinweistexte und Prüfsummen-Beschriftung `#475569` auf `#f1f5f9` 6.92:1; Schaltfläche „Akzeptieren“ Weiß auf `#0f172a` 17.85:1; Erfolgsmeldung `#15803d` auf `#f0fdf4` 4.79:1; Fehlermeldung `#b91c1c` auf `#fef2f2` 5.91:1; Link auf der Anmeldeseite `#2271b1` auf `#f0f6fc` 4.75:1; Fokus-Indikator `outline: 2px solid #2271b1` (5.17:1 auf Weiß) mit `outline-offset: 2px`. Vorher lagen die Erfolgsmeldung (`#16a34a`, 3.15:1), die Prüfsummen-Beschriftung (`#64748b`, 4.34:1) und das Fehlerrot (`#dc2626`, 4.41:1) darunter. Die Karte, die beim Öffnen den Fokus erhält, zeigt einen `:focus-visible`-Rahmen.
* **Backend:** rendert aus den Tokens der WM-Suite-Familie (`assets/css/wm-admin-tokens.css`). Zehn feste Tokenpaare erreichen mindestens 4.5:1. Zwei Komponenten der Familie liegen darunter (Leseaudit 2026-09-15): der Untertitel des Dashboard-Widgets mit 4.23:1 und die Zustandsangabe im Familien-Badge mit 4.27:1; die Familien-Stylesheets gehören dem Hub und werden dort korrigiert. Die Code-Beispiele im Handbuch färben Kommentare und Variablen nicht mehr mit `--wm-text-muted` (4.36:1) und `--wm-warning` (4.44:1), sondern mit `--wm-text-secondary` und `--wm-success`. Der grüne Handbuch-Link in der Plugin-Liste (`#059669`, 3.77:1) hat keine eigene Farbe mehr.

---

## 2. Zielgrößen

* Die Checkbox auf `wp-login.php` ist 24 × 24 px groß; der ganze Beschriftungstext ist klickbar (`<label for="wm_nda_accept">`).

---

## 3. Tastatur

* Das Signatur-Modal hält den Fokus: `Tab` und `Umschalt+Tab` springen innerhalb des Modals (`assets/js/nda-gate-modal.js`). Die Liste der Ziele enthält nur Elemente, die gerade fokussierbar sind; bis 2026-09-15 stand die deaktivierte Schaltfläche „Akzeptieren“ am Ende, und `Tab` verließ das Modal. Nach der Korrektur nicht erneut im Browser geprüft.
* Auf der Anmeldeseite schließt `Escape` das Modal und gibt den Fokus an die Schaltfläche „[Vollständigen NDA-Text lesen]“ zurück; auf der Signaturseite lässt es sich nicht schließen, nur bestätigen oder abmelden.
* `Leertaste` aktiviert die Checkboxen; die Schaltfläche „Akzeptieren“ bleibt deaktiviert (`disabled`, `aria-disabled`), bis die Checkbox im Modal angehakt ist.

---

## 4. Screenreader

* Das Modal verwendet `role="dialog"`, `aria-modal="true"`, `aria-labelledby="wm-nda-title"` und `aria-describedby="wm-nda-subtitle"`; der Vertragstext ist eine beschriftete, per Tastatur scrollbare Region. Die Dashicons im Modal sind `aria-hidden`.
* Die Checkbox im Modal trägt `required` und `aria-required="true"`. Die Checkbox auf `wp-login.php` ist seit 2026-09-14 nicht mehr `required`, weil Benutzer mit bestehender Signatur sie nicht brauchen; sie verweist per `aria-describedby` auf den Hinweis darunter.
* Rückmeldungen beim Signieren werden über `aria-live="polite"` ausgegeben; ihre Texte kommen aus dem Übersetzungskatalog.
* Die Modal-Vorschau im Cockpit ist `aria-hidden` und aus der Tab-Reihenfolge genommen.
