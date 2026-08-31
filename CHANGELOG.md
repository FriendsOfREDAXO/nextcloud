# Changelog

## [2.0.1] – 2026-08-31

### Geändert

- Backup-Cronjob-Klasse `rex_cronjob_redaxo_backup` in den Addon-Namespace verschoben (`FriendsOfRedaxo\NextCloud\rex_cronjob_redaxo_backup`), passend zum Rest der Klassen dieses Addons ([#9](https://github.com/FriendsOfREDAXO/nextcloud/issues/9)). Bereits konfigurierte Cronjob-Instanzen werden beim Update automatisch auf den neuen Klassennamen migriert (`install.php`), keine manuelle Anpassung nötig.

## [2.0.0] – 2026-08-31

### Breaking Changes

- Neue Pflicht-Abhängigkeit `cloudconnect >=0.3.1`. Dieses Addon speichert selbst keine Zugangsdaten mehr und macht keine eigenen WebDAV-/OCS-Requests mehr – `lib/nextcloud.php` ist jetzt ein dünner Feature-Layer über `cloudconnect`s `NextcloudClient` (Transport/Auth/SSL). Bestehende Zugangsdaten (`rex_config('nextcloud', 'baseurl'|'username'|'password'|'rootfolder')`) werden beim ersten Aufruf nach dem Update automatisch, einmalig und idempotent in eine neue cloudconnect-Verbindung migriert (`install.php`) – keine manuelle Neueinrichtung nötig.
- Eigene MediaPlace-Cloud-Provider-Integration entfernt (`lib/MediaplaceProvider.php`, `MEDIAPLACE_STORAGE_PROVIDERS`-Registrierung, Recht `nextcloud[mediaplace_browse]`). Behebt den doppelten "Nextcloud"-Eintrag in MediaPlace's Cloud-Speicher-Sidebar – cloudconnect registriert bereits einen eigenen Provider für dieselben Verbindungen.
- `nextcloud/lib/nextcloud.php`s Feld-Rückgabe von `listFiles()`/`searchFilesRecursive()` hat sich geändert: statt kategorisiertem `type` (`image`/`pdf`/`document`/…), vorformatierten `size`-Strings und fertig formatiertem `modified` liefern beide jetzt MediaPlace-kompatibel `type: 'file'|'folder'`, rohes `filesize` (Bytes) und unformatiertes `modified` – Formatierung/Icon-Auswahl passieren clientseitig in `nextcloud.js`. Betrifft nur eigenen Code, der direkt gegen diese PHP-Klasse programmiert (nicht die Backend-Seite selbst, die mit umgebaut wurde).
- `NextCloud::__construct()` verlangt jetzt eine explizite `connection_id` (kein zero-arg-Konstruktor, kein globaler Fallback mehr).

### Neu

- Mehrkonten-fähig: beliebig viele, in cloudconnect konfigurierte Nextcloud-Accounts stehen gleichzeitig zur Verfügung. Neuer Konto-Umschalter im Backend (Browser-Seite, ZIP-Download, Import, Upload, Löschen, Share-Links – jede Aktion sendet ihre `connection_id` explizit), Backup-Cronjob wählt seinen Ziel-Account pro Instanz aus.
- `REX_NEXTCLOUD_SHARE` unterstützt einen neuen, optionalen `connection`-Parameter (Account-Id); ohne Angabe wird weiterhin der erste aktive Account verwendet (rückwärtskompatibel zu bestehenden Modul-Ausgaben). Share-Link-Cache ist jetzt pro Account UND Pfad getrennt.
- Vollständiger optischer Umbau der Backend-Seite: eigene `nextcloud.css` im MediaPlace-Farb-/Typografie-Look (REDAXO-Palette, Light/Dark), Datei-/Ordnerliste als Zeilenlayout statt `<table>`, alle drei Modals (Vorschau, Upload aus Medienpool, Share-Link) strukturell neu gebaut.
- Backup-Cronjob (`rex_cronjob_redaxo_backup`) nutzt jetzt `cloudconnect`s `NextcloudClient` statt einer eigenen, zweiten WebDAV-Implementierung.
- `pages/config.php` zeigt eine schreibgeschützte Liste der konfigurierten Accounts mit Link zu cloudconnect statt eigener Zugangsdaten-Felder.
- Neue Galerie-Ansicht (Masonry) als Alternative zur Zeilenliste, umschaltbar über einen neuen Ansicht-Umschalter neben der Suche (Auswahl bleibt pro Browser in `localStorage` gemerkt). Kachel-Vorschaubilder für Bilder/Videos, Icon-Kacheln für PDFs/Dokumente/Ordner, volle Aktionen (Importieren/Herunterladen/Share-Link/Löschen) über eine Hover-Werkzeugleiste je Kachel.

### Behoben

- Dateigröße war in der Liste durchgängig 0 B, Änderungsdatum und MIME-Typ leer – lag an einem Parsing-Bug in `cloudconnect`s `NextcloudClient` (behoben in dessen Version 0.3.1, siehe dortiges Changelog), betraf durch die neue Client-Abhängigkeit auch dieses Addon.
- Der Schließen-Button (X) in den drei Modals konnte bei langen Dateinamen im Titel aus dem sichtbaren Bereich gedrückt werden (Flexbox-Kind ohne `min-width: 0`) – Titel schrumpft/umbricht jetzt korrekt, X bleibt fix am rechten Rand.

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
