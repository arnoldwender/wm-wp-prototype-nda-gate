# Sicherheitsübersicht — WM Prototype NDA Gate

> Angriffsvektoren und was der Code dagegen tut, gemessen nach einem Leseaudit. Keine externe Prüfung. Frühere Fassungen nannten einen eigenen `X-Frame-Options`-Header oder lückenlose Erkennung bei gelöschter Kettenspitze; die tatsächlichen Grenzen stehen unten.

---

## 1. Angriffsvektoren

| Angriffsvektor | Einschätzung | Maßnahme im Code |
| :--- | :--- | :--- |
| **Bypass via Direkt-POST** | Kritisch | `authenticate`-Filter (Priorität 99) prüft `wm_nda_accept=1` serverseitig nach der Passwortprüfung und vor der Cookie-Ausstellung. |
| **Signatur ohne gültiges Passwort** | Hoch | Bis 2026-09-14 lief die Prüfung auf `wp_authenticate_user`, vor dem Passwortvergleich: Benutzername plus angehakte Checkbox genügten, um eine Signatur im Namen des Benutzers zu speichern. Jetzt erreicht nur eine erfolgreiche Anmeldung die Signatur (Testgruppe 5). |
| **Zugriff auf wp-admin ohne Signatur** | Hoch | Weiterleitung bei `admin_init` (Priorität 1), bevor eine Admin-Seite aufgebaut wird; `admin-ajax.php` antwortet 403. Die Signaturseite selbst überdeckt Menü und Hinweise nur. |
| **Alle Prüfstellen offen nach einem Versionswechsel** | Hoch | Bis 2026-09-15 schaltete `bump_version()` erst alle Fassungen ab und speicherte dann; eine zu lange Versionsnummer ließ keine aktive Fassung zurück, und niemand wurde mehr gefragt. Jetzt wird zuerst gespeichert und dann umgeschaltet (Testgruppe 10, D1). |
| **REST-Zugriff ohne Signatur** | Hoch | `rest_pre_dispatch` antwortet angemeldeten Benutzern ohne Signatur mit `403 rest_forbidden` (Ausnahmen per Filter `wm_nda_whitelisted_routes`). Anonyme Anfragen sind von diesem Filter nicht betroffen. |
| **Nachträgliche Änderung des Registers** | Hoch | Verkettete HMAC-SHA256-Signaturen mit `AUTH_KEY` und eine mit demselben Schlüssel versiegelte Kettenspitze; `verify_register()` meldet geänderte, gelöschte, eingeschleuste und abgeschnittene Zeilen sowie eine fehlende, unversiegelte oder umgeschriebene Spitze. Zugang gibt nur eine Zeile mit gültiger Signatur. Nicht erkannt: eine vorher kopierte ältere Spitze, die zurückgespielt wird; wer den Schlüssel kennt, kann Kette und Spitze konsistent neu schreiben (Testgruppe 10, D2). |
| **Platzhalter als Schlüssel** | Hoch | Der Text aus wp-config-sample.php zählt nicht als `AUTH_KEY`; bis 2026-09-15 konnte damit jeder eine gültige Signatur erzeugen (D5). |
| **Gleichzeitige Zustimmungen** | Mittel | Schreibvorgänge laufen unter einer MySQL-Namenssperre; ohne Sperre wird nichts geschrieben. Bis 2026-09-15 verzweigten zwei gleichzeitige Zustimmungen die Kette dauerhaft (D4). Auf SQLite gibt es diese Sperre nicht. |
| **Weiterleitungsschleife über Query-Argumente** | Mittel | Die angefragte Adresse wird kodiert in `redirect_to` übergeben; bis 2026-09-15 ersetzte ein `&page=` in ihr das Seitenargument der Signaturseite (D3). |
| **Hub meldet ein gebrochenes Register als gesund** | Mittel | Bis 2026-09-14 las der Adapter das Ergebnis-Array als `true`; jetzt `['valid']`, und seit 2026-09-15 `ERROR` ohne Tabellen, `DEGRADED` bei abgeschalteten Prüfstellen (Testgruppen 9 und 10). |
| **Erfundene oder gelöschte Zustimmungen über Demodaten** | Mittel | Demodaten tragen `user_id` 0, werden nur daran erkannt und nicht in ein Register mit echten Signaturen importiert (Testgruppen 9 und 10, D7). |
| **Abfrage fremder Signaturstatus** | Mittel | `/verify` beantwortet andere `user_id` nur für Administratoren. |
| **CSV Formula Injection (CWE-1236)** | Mittel | `sanitize_csv_cell()` setzt vor `=`, `+`, `-`, `@`, Tab und CR ein Apostroph. |
| **SQL Injection (CWE-89)** | Hoch | Werte über `$wpdb->prepare()`; Tabellennamen kommen aus `$wpdb->prefix`, in den Listen-, Sperr- und Migrationsabfragen über `%i`. |
| **CSRF (CWE-352)** | Hoch | `check_admin_referer()` auf allen Formularen und Exporten; REST mit Cookie-Nonce. |
| **Clickjacking (CWE-1021)** | Mittel | Das Plugin setzt keine eigenen Header; WordPress sendet auf `wp-login.php` und in `wp-admin` selbst `X-Frame-Options: SAMEORIGIN`. |
| **Massenhafte Anfragen an REST-Routen** | Niedrig | 10 Anfragen pro Minute je IP auf `/accept`, 60 Anfragen pro Minute je IP auf `/verify` und `/active-terms` (Filter `wm_nda_rest_rate_limit`, Transient mit tagesgesalzenem IP-HMAC). |

---

## 2. CWE-1236 Neutralisierung

Angreifer versuchen, über Formelfelder in CSV-Dateien Befehle einzuschleusen (z. B. `=CMD|' /C calc'!A0` oder `@SUM(...)`).

Im `WM_NDA_Exporter`:
```php
public static function sanitize_csv_cell( string $value ): string {
    $dangerous_prefixes = [ '=', '+', '-', '@', "\t", "\r" ];
    $first_char = mb_substr( $value, 0, 1 );

    if ( in_array( $first_char, $dangerous_prefixes, true ) ) {
        return "'" . $value;
    }

    return $value;
}
```
Tabellenkalkulationen (Excel, LibreOffice) lesen den Inhalt damit als Text.

---

## 3. Rate Limiting auf `/accept`, `/verify` und `/active-terms`

* Maximal **10 Anfragen pro Minute** auf `/accept` sowie **60 Anfragen pro Minute** (Filter `wm_nda_rest_rate_limit`) auf `/verify` und `/active-terms` je IP-Adresse (Transient `wm_nda_rl_*` bzw. `wm_nda_rl_pub_*` plus die ersten 32 Zeichen des tagesgesalzenen IP-HMAC; Administratoren mit `manage_options` sind auf den öffentlichen Abfragerouten ausgenommen).
* Darüber antwortet die API mit `HTTP 429`.
