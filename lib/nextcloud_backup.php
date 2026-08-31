<?php

namespace FriendsOfRedaxo\NextCloud;

/**
 * REDAXO Backup Cronjob
 *
 * Erstellt Backups von Datenbank und Dateisystem und speichert sie in einer
 * Nextcloud-Instanz. Seit nextcloud 2.0.0 kein eigener WebDAV-Client mehr --
 * Upload/Retention laufen ueber cloudconnects
 * \FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudClient (siehe
 * lib/nextcloud.php fuer denselben Umbau in der Browser-Feature-Klasse).
 *
 * Mehrkonten-faehig wie der Rest des Addons: REDAXO-Cronjobs unterstuetzen
 * bereits mehrere Instanzen desselben Typs mit jeweils eigener Config --
 * die Ziel-Nextcloud wird pro Cronjob-Instanz ueber ein Auswahlfeld
 * (connection_id, siehe getParamFields()) aus den in cloudconnect
 * konfigurierten Nextcloud-Accounts gewaehlt, nicht mehr global in
 * rex_config gespeichert. Zwei Cronjob-Eintraege = Backup zu zwei
 * verschiedenen Accounts, ohne Code-Duplizierung.
 */
class rex_cronjob_redaxo_backup extends \rex_cronjob
{
    public function execute()
    {
        $success = true;
        $message = [];

        $connectionId = (string) $this->getParam('connection_id');
        $nextcloudPath = trim((string) $this->getParam('nextcloud_path'), '/');
        $maxBackups = (int) $this->getParam('max_backups');
        $backupDb = '1' === $this->getParam('backup_db');
        $backupFiles = '1' === $this->getParam('backup_files');

        if (!$backupDb && !$backupFiles) {
            return [false, \rex_i18n::msg('nextcloud_backup_no_type_selected')];
        }

        if (!function_exists('curl_init')) {
            return [false, \rex_i18n::msg('nextcloud_backup_curl_not_available')];
        }

        if ('' === $connectionId) {
            return [false, \rex_i18n::msg('nextcloud_backup_config_missing')];
        }

        $connection = \FriendsOfRedaxo\CloudConnect\ConnectionStore::get($connectionId);
        if (null === $connection || 'nextcloud' !== ($connection['type'] ?? '') || !\FriendsOfRedaxo\CloudConnect\ConnectionStore::isComplete($connection)) {
            return [false, \rex_i18n::msg('nextcloud_backup_config_missing')];
        }

        try {
            $client = new \FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudClient($connectionId);
        } catch (\Exception $e) {
            return [false, \rex_i18n::msg('nextcloud_backup_connection_error') . ': ' . $e->getMessage()];
        }

        // Basis-Verzeichnis fuer temporaere Backups
        $backupBaseDir = \rex_path::base('backup');
        if (!file_exists($backupBaseDir)) {
            mkdir($backupBaseDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');

        $rootDir = '/' . $nextcloudPath;
        $dbDir = '/' . $nextcloudPath . '/db';
        $filesDir = '/' . $nextcloudPath . '/files';

        // NextcloudClient bietet bewusst kein MKCOL (Datei-Upload aus der
        // Browser-Seite zielt immer auf einen bereits existierenden,
        // gerade durchsuchten Ordner) -- der Backup-Cronjob muss seine
        // Zielstruktur beim allerersten Lauf aber selbst anlegen koennen.
        // Statt dafuer einen zweiten vollstaendigen WebDAV-Client zu
        // bauen, reicht ein minimaler PROPFIND/MKCOL-Helper direkt hier,
        // der nur die dafuer noetigen rohen Verbindungsdaten aus
        // cloudconnect liest (siehe ensureDirectoryExists() unten).
        try {
            $this->ensureDirectoryExists($connection['config'], $rootDir);
            $this->ensureDirectoryExists($connection['config'], $dbDir);
            $this->ensureDirectoryExists($connection['config'], $filesDir);
        } catch (\Exception $e) {
            return [false, \rex_i18n::msg('nextcloud_backup_dir_create_error', $nextcloudPath, $e->getMessage())];
        }

        $dbFile = null;
        $filesFile = null;

        // Datenbank-Backup erstellen
        if ($backupDb) {
            try {
                $dbFile = $backupBaseDir . '/redaxo_db_' . $timestamp . '.sql.gz';

                $db = \rex::getProperty('db');
                $dbHost = $db[1]['host'];
                $dbName = $db[1]['name'];
                $dbUser = $db[1]['login'];
                $dbPassword = $db[1]['password'];

                $command = sprintf(
                    'mysqldump -h %s -u %s -p%s %s | gzip > %s',
                    escapeshellarg($dbHost),
                    escapeshellarg($dbUser),
                    escapeshellarg($dbPassword),
                    escapeshellarg($dbName),
                    escapeshellarg($dbFile),
                );

                exec($command, $output, $returnVar);

                if (0 !== $returnVar) {
                    $success = false;
                    $message[] = \rex_i18n::msg('nextcloud_backup_db_error') . ': ' . implode("\n", $output);
                } else {
                    $message[] = \rex_i18n::msg('nextcloud_backup_db_success') . ': ' . basename($dbFile);

                    try {
                        $client->putContent($dbDir . '/' . basename($dbFile), (string) \rex_file::get($dbFile));
                        $message[] = \rex_i18n::msg('nextcloud_backup_db_upload_success');
                    } catch (\Exception $e) {
                        $success = false;
                        $message[] = \rex_i18n::msg('nextcloud_backup_db_upload_error') . ': ' . $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $success = false;
                $message[] = \rex_i18n::msg('nextcloud_backup_db_exception') . ': ' . $e->getMessage();
            }
        }

        // Dateisystem-Backup erstellen
        if ($backupFiles) {
            try {
                $filesDirLocal = \rex_path::base();
                $filesFile = $backupBaseDir . '/redaxo_files_' . $timestamp . '.tar.gz';

                $excludeDirs = [
                    'backup',
                    'cache',
                    'redaxo/cache',
                    'redaxo/data/cache',
                    'media/cache',
                ];

                $excludeParams = '';
                foreach ($excludeDirs as $dir) {
                    $excludeParams .= ' --exclude=' . escapeshellarg($dir);
                }

                $command = sprintf(
                    'tar -czf %s -C %s %s .',
                    escapeshellarg($filesFile),
                    escapeshellarg($filesDirLocal),
                    $excludeParams,
                );

                exec($command, $output, $returnVar);

                if (0 !== $returnVar) {
                    $success = false;
                    $message[] = \rex_i18n::msg('nextcloud_backup_files_error') . ': ' . implode("\n", $output);
                } else {
                    $message[] = \rex_i18n::msg('nextcloud_backup_files_success') . ': ' . basename($filesFile);

                    try {
                        $client->putContent($filesDir . '/' . basename($filesFile), (string) \rex_file::get($filesFile));
                        $message[] = \rex_i18n::msg('nextcloud_backup_files_upload_success');
                    } catch (\Exception $e) {
                        $success = false;
                        $message[] = \rex_i18n::msg('nextcloud_backup_files_upload_error') . ': ' . $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $success = false;
                $message[] = \rex_i18n::msg('nextcloud_backup_files_exception') . ': ' . $e->getMessage();
            }
        }

        // Alte Backups bereinigen
        if ($maxBackups > 0) {
            try {
                $this->cleanupOldBackups($client, $dbDir, $maxBackups);
                $this->cleanupOldBackups($client, $filesDir, $maxBackups);
                $message[] = \rex_i18n::msg('nextcloud_backup_cleanup_success', $maxBackups);
            } catch (\Exception $e) {
                $success = false;
                $message[] = \rex_i18n::msg('nextcloud_backup_cleanup_error') . ': ' . $e->getMessage();
            }
        }

        // Lokale Backup-Dateien loeschen
        if ($backupDb && null !== $dbFile) {
            @unlink($dbFile);
        }
        if ($backupFiles && null !== $filesFile) {
            @unlink($filesFile);
        }

        return [$success, implode("\n", $message)];
    }

    /**
     * Entfernt alte Backups aus $directory und behaelt nur die $maxKeep
     * neuesten -- Listing/Loeschen laufen ueber NextcloudClient (kein
     * eigenes XML-Parsing mehr noetig, listFiles() liefert bereits
     * strukturierte Eintraege inkl. 'modified').
     */
    private function cleanupOldBackups(\FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudClient $client, string $directory, int $maxKeep): void
    {
        $entries = $client->listFiles($directory);

        $files = [];
        foreach ($entries as $entry) {
            if ('file' !== ($entry['type'] ?? '')) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if (!preg_match('/^redaxo_(db|files)_.*\.(sql\.gz|tar\.gz)$/', $name)) {
                continue;
            }
            $path = (string) ($entry['path'] ?? '');
            if ('' === $path) {
                continue;
            }
            $modified = (string) ($entry['modified'] ?? '');
            $timestamp = '' !== $modified ? strtotime($modified) : 0;
            $files[$path] = false !== $timestamp ? $timestamp : 0;
        }

        // Nach Datum sortieren (neueste zuerst)
        arsort($files);

        $count = 0;
        foreach (array_keys($files) as $path) {
            ++$count;
            if ($count > $maxKeep) {
                $client->deleteFile($path);
            }
        }
    }

    /**
     * Minimaler PROPFIND(Depth 0)/MKCOL-Helper, NUR fuer die
     * Verzeichnis-Existenzpruefung/-Anlage beim Backup -- bewusst nicht
     * Teil von NextcloudClient (siehe Kommentar in execute() oben). Baut
     * dieselbe WebDAV-URL-Form wie NextcloudClient::buildWebDavUrl()
     * (Root-Ordner-Praefix + je Segment rawurlencode), liest die
     * Rohverbindungsdaten aber direkt aus der cloudconnect-Config, da
     * NextcloudClient dafuer keine oeffentlichen Felder/Methoden anbietet.
     *
     * @param array<string, mixed> $config
     */
    private function ensureDirectoryExists(array $config, string $path): void
    {
        $baseUrl = rtrim((string) ($config['url'] ?? ''), '/');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $rootFolder = '/' . trim((string) ($config['root'] ?? ''), '/');
        if ('/' === $rootFolder) {
            $rootFolder = '';
        }
        $sslVerify = (bool) ($config['ssl_verify'] ?? true);

        $normalizedPath = '/' === $path ? '' : '/' . trim($path, '/');
        $fullPath = $rootFolder . $normalizedPath;
        $segments = array_filter(explode('/', $fullPath), static fn (string $segment): bool => '' !== $segment);
        $encodedPath = implode('/', array_map('rawurlencode', $segments));
        $url = $baseUrl . '/remote.php/dav/files/' . rawurlencode($username) . ('' !== $encodedPath ? '/' . $encodedPath : '');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER => ['Depth: 0'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (0 !== $errno) {
            throw new \Exception('Connection error: ' . $error);
        }

        // Existiert bereits (207 Multi-Status ist der Normalfall) -- nichts zu tun.
        if (404 !== $status) {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_CUSTOMREQUEST => 'MKCOL',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        $mkStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mkErrno = curl_errno($ch);
        $mkError = curl_error($ch);
        curl_close($ch);

        if (0 !== $mkErrno) {
            throw new \Exception('Connection error: ' . $mkError);
        }
        if ($mkStatus >= 400) {
            throw new \Exception('MKCOL failed (HTTP ' . $mkStatus . ')');
        }
    }

    public function getTypeName()
    {
        return \rex_i18n::msg('nextcloud_backup_type_name');
    }

    public function getParamFields()
    {
        $connections = [];
        foreach (\FriendsOfRedaxo\CloudConnect\ConnectionStore::getByType('nextcloud') as $id => $connection) {
            $label = (string) ($connection['label'] ?? $id);
            if (!($connection['active'] ?? false)) {
                $label .= ' (' . \rex_i18n::msg('nextcloud_backup_connection_inactive') . ')';
            }
            $connections[$id] = $label;
        }

        return [
            [
                'label' => \rex_i18n::msg('nextcloud_backup_connection'),
                'name' => 'connection_id',
                'type' => 'select',
                'options' => $connections,
                'notice' => \rex_i18n::msg('nextcloud_backup_connection_notice'),
            ],
            [
                'label' => \rex_i18n::msg('nextcloud_backup_path'),
                'name' => 'nextcloud_path',
                'type' => 'text',
                'notice' => \rex_i18n::msg('nextcloud_backup_path_notice'),
            ],
            [
                'label' => \rex_i18n::msg('nextcloud_backup_max_count'),
                'name' => 'max_backups',
                'type' => 'text',
                'default' => '5',
            ],
            [
                'label' => \rex_i18n::msg('nextcloud_backup_database'),
                'name' => 'backup_db',
                'type' => 'select',
                'options' => ['0' => \rex_i18n::msg('no'), '1' => \rex_i18n::msg('yes')],
                'default' => '1',
            ],
            [
                'label' => \rex_i18n::msg('nextcloud_backup_files'),
                'name' => 'backup_files',
                'type' => 'select',
                'options' => ['0' => \rex_i18n::msg('no'), '1' => \rex_i18n::msg('yes')],
                'default' => '1',
            ],
        ];
    }
}
