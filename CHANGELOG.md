# Changelog

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
