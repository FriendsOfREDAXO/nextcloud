# NextCloud AddOn für REDAXO

## WORK in progress

🚨 Noch nicht für den produktiven Einsatz

Ein praktisches AddOn zur Integration einer NextCloud-Instanz in REDAXO. Es ermöglicht den direkten Import von Dateien aus der NextCloud in den REDAXO-Medienpool.

## Features

- Durchsuchen der NextCloud-Dateien direkt in REDAXO
- Vorschau von Bildern vor dem Import
- Einfacher Import per Klick in den Medienpool
- Kategorisierung der importierten Dateien
- Unterstützung verschiedener Dateitypen

## Installation 

1. Das AddOn über den REDAXO Installer herunterladen
2. Installation durchführen
3. In den Einstellungen die NextCloud-Verbindung konfigurieren:
   - NextCloud-URL eingeben (z.B. `https://cloud.example.com`)
   - Benutzername festlegen
   - App-Passwort aus den NextCloud-Einstellungen eintragen

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
3. Bilder können vor dem Import vorgeschaut werden
4. Zielkategorie im Medienpool auswählen
5. Dateien per Klick importieren

## Unterstützte Dateitypen

- Bilder: jpg, jpeg, png, gif, svg, webp
- Dokumente: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, md, rtf
- Archive: zip, rar, 7z, tar, gz, bz2
- Audio: mp3, wav, ogg, m4a, flac, aac
- Video: mp4, avi, mkv, mov, webm, flv, wmv

## Systemvoraussetzungen

- REDAXO 5.13.0 oder höher
- PHP 7.4 oder höher
- HTTPS-fähige NextCloud-Installation

## Lizenz des AddOns

MIT-Lizenz, siehe LICENSE

## Lizenz Nextcloud 

https://github.com/nextcloud/server/tree/master/LICENSES

## Author

KLXM Crossmedia GmbH, Thomas Skerbis

## Support & Bugs

Fehler bitte auf GitHub melden: https://github.com/klxm/nextcloud

## Changelog

### Version 0.0.1
- Initiale Version
- Grundlegende Funktionalität zum Durchsuchen und Importieren
- Bildvorschau
- Kategorieauswahl
- Verschiedene Dateitypen-Unterstützung
