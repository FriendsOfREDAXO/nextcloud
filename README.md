# NextCloud AddOn für REDAXO

Ein praktisches AddOn zur Integration einer NextCloud-Instanz in REDAXO. Es ermöglicht den direkten Import von Dateien aus der NextCloud in den REDAXO-Medienpool.

## Features

- Durchsuchen der NextCloud-Dateien direkt in REDAXO
- **Konfigurierbare Root-Ordner**: Beschränkung auf bestimmte Verzeichnisse in der NextCloud
- Vorschau von Bildern vor dem Import (Modal-Fenster)
- **PDF-Vorschau**: PDFs können vor dem Import in einem neuen Fenster betrachtet werden
- Einfacher Import per Klick in den Medienpool
- Kategorisierung der importierten Dateien
- Unterstützung verschiedener Dateitypen
- Backup-Cron zu NextCloud

## Installation

**Seit Version 2.0.0** ist dieses AddOn ein Client von [`cloudconnect`](https://github.com/FriendsOfREDAXO/cloudconnect) und speichert selbst keine Zugangsdaten mehr – `cloudconnect` muss installiert sein (wird als Abhängigkeit automatisch mitinstalliert).

1. Das AddOn über den REDAXO Installer herunterladen (installiert `cloudconnect` mit, falls noch nicht vorhanden)
2. Installation durchführen
3. Nextcloud-Verbindung(en) in **CloudConnect → Einstellungen** anlegen (URL, Benutzername, App-Passwort, optional Root-Ordner) – beliebig viele gleichzeitig möglich, ein Konto-Umschalter im `nextcloud`-Backend erlaubt den Wechsel
4. In **NextCloud → Einstellungen** stehen nur noch die accountübergreifenden Feature-Einstellungen: Share-Links aktivieren/deaktivieren, Tag-Mapping-Zielfeld. Die schreibgeschützte Konto-Liste dort verlinkt direkt zu cloudconnect.

**Update von einer Version vor 2.0.0**: Bestehende Zugangsdaten werden beim ersten Seitenaufruf nach dem Update automatisch und einmalig als neue cloudconnect-Verbindung übernommen – keine manuelle Neueinrichtung nötig.

## Einrichtung in NextCloud

1. In NextCloud einloggen
2. Zu "Einstellungen" > "Sicherheit" navigieren
3. Im Bereich "App-Passwörter" ein neues Passwort generieren
4. Dieses Passwort im REDAXO AddOn eintragen

## Nutzung

Nach erfolgreicher Konfiguration:

1. Im REDAXO Backend zum Menüpunkt "NextCloud" navigieren
2. Dateien und Ordner durchsuchen:
   - Ordner durch Klick öffnen
   - Navigationspfad oben nutzen
   - "Home"-Button führt zum Hauptverzeichnis
3. Bilder können vor dem Import vorgeschaut werden (Modal-Fenster)
4. **PDFs können vor dem Import in einem neuen Fenster/Tab geöffnet werden**
5. Zielkategorie im Medienpool auswählen
6. Dateien per Klick importieren

## Unterstützte Dateitypen

- Bilder: jpg, jpeg, png, gif, svg, webp (mit Modal-Vorschau)
- **PDFs: pdf (mit Vorschau in neuem Fenster)**
- Dokumente: doc, docx, xls, xlsx, ppt, pptx, txt, md, rtf
- Archive: zip, rar, 7z, tar, gz, bz2
- Audio: mp3, wav, ogg, m4a, flac, aac
- Video: mp4, avi, mkv, mov, webm, flv, wmv

## NextCloud Backup-Cronjob

Mit diesem Addon ist ein spezieller Cronjob für automatische Backups verfügbar, der Datenbank- und Dateisystem-Backups automatisch in eine NextCloud-Instanz hochlädt.

### Voraussetzungen

- Ein in cloudconnect konfigurierter, aktiver Nextcloud-Account (siehe Installation oben)
- cURL-Unterstützung auf dem Server
- mysqldump und tar müssen auf dem Server verfügbar sein

### Einrichtung

1. Navigiere im REDAXO-Backend zu "Cronjobs" → "Cronjobs"
2. Klicke auf "Cronjob hinzufügen"
3. Wähle als Typ "REDAXO Backup (NextCloud)" aus
4. Konfiguriere die folgenden Einstellungen:

#### Konfiguration

| Einstellung | Beschreibung |
|-------------|--------------|
| Nextcloud-Account | In cloudconnect konfigurierter Ziel-Account (**seit 2.0.0**: Auswahl statt eigener URL/Benutzername/Passwort-Felder) |
| NextCloud Pfad | Das Zielverzeichnis in der NextCloud (z.B. backups/redaxo) |
| Maximale Anzahl Backups | Ältere Backups werden automatisch gelöscht, wenn diese Anzahl überschritten wird |
| Datenbank sichern | Legt fest, ob die Datenbank gesichert werden soll |
| Dateisystem sichern | Legt fest, ob die Dateien gesichert werden sollen |

5. Konfiguriere die Ausführungshäufigkeit des Cronjobs (täglich, wöchentlich, etc.)
6. Speichere den Cronjob

Mehrere Cronjob-Instanzen mit jeweils unterschiedlichem Ziel-Account sichern parallel zu mehreren Nextcloud-Instanzen.

### Backup-Struktur

Die Backups werden in der NextCloud in folgender Struktur gespeichert:

```
[Zielverzeichnis]/
  ├── db/
  │   ├── redaxo_db_2025-03-13_10-00-00.sql.gz
  │   └── ...
  └── files/
      ├── redaxo_files_2025-03-13_10-00-00.tar.gz
      └── ...
```

### Hinweise

- Die temporären Backup-Dateien werden im Verzeichnis `/backup` im REDAXO-Hauptverzeichnis erstellt und nach dem Upload wieder gelöscht
- Folgende Verzeichnisse werden beim Dateisystem-Backup ausgeschlossen:
  - `backup/`
  - `cache/`
  - `redaxo/cache/`
  - `redaxo/data/cache/`
  - `media/cache/`
- Der Cronjob kann manuell über die REDAXO-Backend-Oberfläche ausgeführt werden, um die Funktionalität zu testen

## Systemvoraussetzungen

- REDAXO 5.18.0 oder höher
- PHP 8.1 oder höher
- exec
- HTTPS-fähige NextCloud-Installation

## Lizenz des AddOns

MIT-Lizenz, siehe LICENSE

## Lizenz Nextcloud 

https://github.com/nextcloud/server/tree/master/LICENSES

## Author

[Thomas Skerbis, KLXM Crossmedia GmbH](https://github.com/klxm)  

## Support & Bugs

Fehler bitte auf GitHub melden: [https://github.com/klxm/nextcloud](https://github.com/FriendsOfREDAXO/nextcloud/)
