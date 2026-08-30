# Changelog

## [1.7.1] – 2026-08-31

### Behoben

- Die feingranularen Rechte (`nextcloud[upload_mediapool]`, `nextcloud[delete]`, `nextcloud[download]`, `nextcloud[mediaplace_browse]`) tauchten in der Rollenverwaltung gar nicht erst auf und waren dadurch für keine Rolle vergebbar. Ursache: der `permissions:`-Block in `package.yml` (dachte, er würde diese Rechte registrieren) ist kein von REDAXO-Core verarbeiteter YAML-Schlüssel – reine, folgenlose YAML. Jetzt echt über `rex_perm::register()` in `boot.php` eingetragen (gleiches Muster, das auch `nextcloud[]` selbst über den page-level `perm:` bereits funktionierend nutzte). Betraf insbesondere `nextcloud[mediaplace_browse]` – ohne dieses Recht konnte eine Rolle mit allgemeinem Nextcloud-Zugriff (`nextcloud[]`) den Cloud-Speicher innerhalb von MediaPlace nicht nutzen, obwohl kein Fehler sichtbar war (das Recht ließ sich schlicht nirgends zuweisen).

## [1.7.0] – 2026-08-29

### Neu

- Nextcloud lässt sich jetzt direkt aus dem `mediaplace`-Addon heraus browsen, durchsuchen und importieren – klinkt sich dort als eigener Baum in die Sidebar ein (kein Sync, reines Import-Werkzeug: einmal importiert ist es eine ganz normale lokale Mediendatei). Neue Klasse `MediaplaceProvider` implementiert MediaPlace's `StorageProviderInterface`, registriert über den Erweiterungspunkt `MEDIAPLACE_STORAGE_PROVIDERS`. Neues, dediziertes Rollenrecht `nextcloud[mediaplace_browse]` – getrennt vom bestehenden, breiteren `nextcloud[]` (das zusätzlich Zugriff auf die eigenständige Nextcloud-Verwaltungsseite inkl. Upload/Löschen/Download gibt), damit sich "Browsen & Importieren via MediaPlace" gezielt einzeln vergeben lässt.
- `listFiles()`/`searchFilesRecursive()` liefern jetzt zusätzlich die rohe Dateigröße in Bytes, den MIME-Typ und die Nextcloud-interne `fileid` pro Eintrag (bestehende Felder unverändert, rein additiv – bestehende Aufrufer bleiben kompatibel).
- Neue Methode `getPreviewContent()`: ruft Nextclouds eigene Vorschau-/Thumbnail-API ab (statt immer das volle Original zu laden), genutzt für die Grid-Vorschaubilder im MediaPlace-Baum.

## [1.6.1] – 2026-07-31

### Verbessert

- Rekursive Datei- und Ordnersuche auf die serverseitige WebDAV-Suche von Nextcloud umgestellt. Dadurch benötigt eine Suche nur noch einen Request und bleibt auch bei großen Verzeichnisstrukturen reaktionsfähig.

### Behoben

- Dauerhaften Zustand „Suche läuft…“ bei umfangreichen Verzeichnisstrukturen behoben.
- Beim Öffnen eines Ordners aus den Suchergebnissen wird die Suche nun zurückgesetzt und der tatsächliche Ordnerinhalt angezeigt.
- Home-Symbol im Navigationspfad verlinkt und doppelte Schrägstriche in der Pfadanzeige entfernt.

## [1.6.0] – 2026-07-30

### Neu

- Mehrfach-Löschen aus der Dateiliste über Checkbox-Auswahl und Sammelaktion in der Toolbar ergänzt.
- Löschaktion pro Datei bleibt verfügbar und wird durch das Recht `nextcloud[delete]` gesteuert.
- Einzel-Download für Dateien direkt aus der Nextcloud-Dateiliste ergänzt.
- Mehrfach-Download ausgewählter Dateien als ZIP über die Checkbox-Auswahl ergänzt.
- Ordner-Download als ZIP direkt aus der Dateiliste ergänzt.
- Neues Recht `nextcloud[download]` steuert Download-Aktionen.

### Verbessert

- Upload-Workflow im Backend auf Modal mit einer einzigen Medienliste fokussiert (kein zusätzliches Einzeldatei-Feld mehr).
- Rekursive Live-Suche für Datei- und Ordnernamen ergänzt (inklusive Unterordnern ab aktuellem Pfad).

### Behoben

- 403-Fehler beim Löschen durch konsistentere Pfadbehandlung zwischen Dateiliste und WebDAV-Aufruf entschärft.
- Klarere Fehlermeldung bei verweigertem Löschen in Nextcloud (z. B. fehlende Nextcloud-Rechte oder Dateisperren).

## [1.5.0] – 2026-07-30

### Neu

- Dateien aus dem REDAXO-Medienpool können direkt in den aktuell geöffneten Nextcloud-Ordner hochgeladen werden.
- Mehrfach-Upload aus dem REDAXO-Medienpool über eine Medienliste ergänzt.
- Neues Recht `nextcloud[upload_mediapool]` steuert den Upload aus dem Medienpool gezielt pro Benutzerrolle.
- Video-Dateien können in der Dateiliste direkt per Vorschau-Modal abgespielt werden.
- Nutzer mit dem Recht `nextcloud[delete]` können Dateien direkt aus der Nextcloud-Dateiliste löschen.
- Upload-UI für Medienpool-Dateien auf ein schlankes Modal umgestellt und auf eine einzige Medienliste reduziert.

### Sicherheit

- Neuer API-Endpoint für den Medienpool-Upload prüft Addon-Recht, Upload-Recht und Medienkategorie-Rechte des Benutzers.
- Zielpfad-Prüfung schützt vor Pfad-Traversal (`.` und `..`) beim Upload-Ziel.

## [1.4.0] – 2026-04-17

### Neu: Share-Links

- Öffentliche Nextcloud-Share-Links können direkt aus der Dateiliste im Backend erstellt werden (Share-Button pro Datei).
- Optionales Ablaufdatum für jeden Share-Link.
- Share-Links sind optional und über die AddOn-Einstellungen aktivierbar/deaktivierbar.
- Neue REX_VAR `REX_NEXTCLOUD_SHARE[path="..." expiry="..."]` – Share-Links können direkt in Modul-Outputs und Templates eingebettet werden.
- Share-Links werden gecacht (`rex_config`) und bei Ablauf automatisch erneuert. Cache kann mit `reset="1"` zurückgesetzt werden.

### Neu: Nextcloud-Tags als Mediametadaten

- Beim Dateiimport in den Medienpool werden Nextcloud-Tags automatisch als Mediametadaten übernommen.
- Das Zielfeld (z. B. `med_description`) ist frei konfigurierbar und muss als Spalte in `rex_media` existieren (z. B. über das metainfo-AddOn).
- Die Funktion ist optional – bleibt das Zielfeld in den Einstellungen leer, werden Tags ignoriert.

### Refactoring

- AJAX-Handler von `boot.php` in eine eigene `rex_api_function`-Klasse (`rex_api_nextcloud`) ausgelagert. API-Endpunkte werden nun korrekt über `?rex-api-call=nextcloud` aufgerufen.
- Bugfix: `http_build_query()` verwendete den PHP-ini-Wert `arg_separator.output = &amp;` als Trennzeichen, was dazu führte, dass Nextcloud beim Erstellen von Share-Links nur den ersten Parameter (`path`) erhielt und mit „Unbekannter Freigabetyp" antwortete. Behoben durch expliziten Separator `'&'`.

## [1.3.0] und früher

Siehe Git-History.
