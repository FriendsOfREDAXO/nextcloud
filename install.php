<?php

/**
 * install.php laeuft sowohl beim Erst-Install als auch bei jedem Update
 * (dieser REDAXO-Core ruft ein separates update.php nie auf --
 * rex_package::FILE_UPDATE wird von keiner Core-Stelle ausgewertet, siehe
 * gleicher Befund/gleiche Loesung in cloudconnect/install.php).
 *
 * Einmalige, idempotente Migration von der alten "eine einzige, global
 * konfigurierte Nextcloud-Verbindung"-Struktur (feste rex_config-Schluessel
 * baseurl/username/password/rootfolder) in eine neue cloudconnect-
 * Verbindung (seit 2.0.0 hat dieses Addon selbst keine Zugangsdaten mehr,
 * siehe lib/nextcloud.php). Migrierter Account ist danach einer von
 * potenziell mehreren, gleichberechtigten Accounts -- keine dauerhafte
 * "connection_id" wird hier gemerkt, nur ein Marker, dass migriert wurde.
 */
if (!rex_config::get('nextcloud', 'migrated_to_cloudconnect', false)) {
    $legacyBaseUrl = (string) rex_config::get('nextcloud', 'baseurl', '');

    if ('' !== $legacyBaseUrl) {
        \FriendsOfRedaxo\CloudConnect\ConnectionStore::create('nextcloud', 'Nextcloud (migriert)', true, [
            'url' => $legacyBaseUrl,
            'username' => (string) rex_config::get('nextcloud', 'username', ''),
            'password' => (string) rex_config::get('nextcloud', 'password', ''),
            'root' => (string) rex_config::get('nextcloud', 'rootfolder', ''),
            'ssl_verify' => (bool) rex_config::get('nextcloud', 'ssl_verify', true),
        ]);
    }

    rex_config::set('nextcloud', 'migrated_to_cloudconnect', true);
}
