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

/**
 * Cronjob-Klasse wurde in den Addon-Namespace verschoben
 * (rex_cronjob_redaxo_backup -> FriendsOfRedaxo\NextCloud\rex_cronjob_redaxo_backup,
 * siehe lib/nextcloud_backup.php und github.com/FriendsOfREDAXO/nextcloud/issues/9)
 * -- bereits konfigurierte Cronjob-Instanzen verweisen in der DB noch auf den
 * alten, globalen Klassennamen und wuerden sonst als "unbekannter Typ"
 * erscheinen. Eigener Marker, unabhaengig von der Verbindungs-Migration oben
 * (kann zeitlich getrennt ausgeliefert/aktualisiert werden).
 */
if (!rex_config::get('nextcloud', 'migrated_cronjob_namespace', false)) {
    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('cronjob') . ' SET type = ? WHERE type = ?',
        ['FriendsOfRedaxo\\NextCloud\\rex_cronjob_redaxo_backup', 'rex_cronjob_redaxo_backup'],
    );

    rex_config::set('nextcloud', 'migrated_cronjob_namespace', true);
}
