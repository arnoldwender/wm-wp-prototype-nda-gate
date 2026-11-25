# Datenverarbeitung — WM Prototype NDA Gate

> Beschreibt, welche Daten das Plugin verarbeitet und wie. Gemessen am Code (2026-09-16). Eine rechtliche Bewertung enthält diese Datei nicht: welche Rechtsgrundlage ein Einsatz hat, welche Informationspflichten daraus folgen und ob er im Einzelfall zulässig ist, entscheidet der Betreiber der Website.

---

## 1. Welche Daten je Bestätigung gespeichert werden

In `wp_wm_nda_acceptances`, in der Datenbank der eigenen Website:

* User-ID, E-Mail-Adresse und Anzeigename des angemeldeten Kontos
* User-Agent des Browsers
* Zeitpunkt (UTC)
* SHA-256 des Vertragstextes, dem zugestimmt wurde
* HMAC-SHA256 der IP-Adresse mit täglich wechselndem Salt
* verkettete Signatur (`prev_hash`, `signature_hash`)
* **Click-Wrap-Nachweis** (`interaction_json`), sofern der Browser einen geliefert hat: Eingabegerät (Maus, Stift, Touch, Tastatur), Bestätigung per Zeigegerät oder Tastatur, Verweildauer im Dialog, Dauer zwischen Häkchen und Bestätigung, durchgeblätterter Anteil, Zahl der Zeiger-Messpunkte und zurückgelegter Weg in Pixeln, höchster Druckwert, Breite und Höhe des Sichtfensters, Zeitzonenversatz des Geräts

Dazu die Benutzer-Metadaten `wm_nda_last_accepted_term_id` und `wm_nda_last_accepted_at`.

Außerhalb der Tabelle:

* **Audit-Ereignisse** (verweigerte Anmeldung, gespeicherte Bestätigung) mit User-ID, Anmeldename bzw. Versions- und Signaturdaten und dem IP-HMAC, an WM Activity Sentinel, wenn es aktiv ist, sonst in das PHP-Fehlerprotokoll des Servers.
* **Anfragebegrenzung:** für eine Minute ein Transient, dessen Name die ersten 32 Zeichen des IP-HMAC enthält.

Das Plugin sendet keine Daten an Dritte.

### Bildschirm-Wasserzeichen

Ist `wm_nda_watermark` eingeschaltet, trägt jede ausgelieferte Seite ein Raster, das die Konto-ID und die Minute der Auslieferung in den Pixeln des Bildschirms kodiert. **Gespeichert wird dafür nichts** — es wird je Anfrage berechnet. Aus einem Screenshot, auf dem das Raster liegt, lässt sich die Konto-ID zurücklesen, der die Seite ausgeliefert wurde; das gilt für jedes Bildschirmfoto, das Beschäftigte oder Kunden anfertigen. Ob und unter welchen Bedingungen das eingesetzt werden darf, auch gegenüber Beschäftigten, beurteilt das Plugin nicht.

### Was der Click-Wrap-Nachweis ist und was nicht

Er besteht aus zehn ganzen Zahlen und zwei Aufzählungen. Zeigerkoordinaten und Zeitreihen werden **nicht** erhoben: ein Bewegungsprofil wäre Verhaltensbiometrie, und kein Zweck dieses Plugins braucht es. Sichtfenster und Zeitzonenversatz beschreiben allerdings das benutzte Gerät und tragen als Merkmale zu dessen Wiedererkennbarkeit bei.

Er belegt, dass der Dialog bedient wurde — nicht, von wem. Wer bestätigt hat, sagt das angemeldete Konto, nicht dieser Nachweis. Die Angaben stammen vom Browser; nachträglich ändern lassen sie sich nicht, ohne die Signaturkette zu brechen, ob sie beim Senden zutrafen, prüft das Plugin nicht.

---

## 2. IP-Adressen

$$\text{IP\_HMAC} = \text{HMAC-SHA256}(\text{IP}, \text{Datum} + \text{NONCE\_SALT})$$

(ohne `NONCE_SALT`: `wp_salt( 'nonce' )`). Innerhalb eines Kalendertages ergibt dieselbe IP-Adresse denselben Wert; wer den Salt kennt, kann Kandidaten-Adressen prüfen. Es handelt sich um Pseudonymisierung, nicht um Anonymisierung.

---

## 3. Export und Löschung mit den WordPress-Werkzeugen

* **Export:** WordPress › Werkzeuge › Personenbezogene Daten exportieren liefert die Bestätigungen zu einer E-Mail-Adresse — zur beim Signieren gespeicherten Adresse und zum Konto, das die Adresse heute trägt.
* **Löschung:** WordPress › Werkzeuge › Personenbezogene Daten löschen ersetzt Name, E-Mail und User-Agent derselben Bestätigungen und löscht die beiden Benutzer-Metadaten des Kontos. User-ID, Signatur, Zeitpunkt, Fassung, IP-HMAC und der Click-Wrap-Nachweis bleiben, weil sie Teil der verketteten Signatur sind; WordPress zeigt sie als aufbewahrte Daten an. Die User-ID verweist weiter auf das Konto, Sichtfenster und Zeitzonenversatz beschreiben weiter das benutzte Gerät.
* **Aufbewahrung und Deinstallation:** Beim Löschen eines WordPress-Benutzers bleibt das Register unverändert; Audit-Ereignisse in Sentinel oder im Fehlerprotokoll erfassen weder Export noch Löschung. Der tägliche Lauf `WM_NDA_Retention` pseudonymisiert Name, E-Mail und User-Agent von Bestätigungen, die älter als `wm_nda_retention_days` sind (Standard 90 Tage; unter 30 Tagen bleibt alles unverändert), und löscht keine Zeilen. Die Deinstallation bewahrt Tabellen, Optionen und Benutzer-Metadaten standardmäßig auf und löscht sie nur bei aktivierter Option `wm_nda_delete_data_on_uninstall`; die Audit-Ereignisse berührt sie nicht.
