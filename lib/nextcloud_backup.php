<?php
/**
 * REDAXO Backup Cronjob
 * 
 * Erstellt Backups von Datenbank und Dateisystem und speichert sie in einer Nextcloud-Instanz über WebDAV.
 * 
 * Diese Datei gehört in: /var/www/html/redaxo/src/addons/cronjob/lib/types/redaxo_backup.php
 * 
 * Abhängigkeiten: 
 * - sabre/dav library (installierbar via Composer)
 */

class rex_cronjob_redaxo_backup extends rex_cronjob
{
    public function execute()
    {
        $success = true;
        $message = [];

        // Parameter abrufen
        $nextcloud_url = $this->getParam('nextcloud_url');
        $nextcloud_username = $this->getParam('nextcloud_username');
        $nextcloud_password = $this->getParam('nextcloud_password');
        $nextcloud_path = $this->getParam('nextcloud_path');
        $max_backups = (int) $this->getParam('max_backups');
        $backup_db = $this->getParam('backup_db') === '1';
        $backup_files = $this->getParam('backup_files') === '1';
        
        // Prüfen ob mindestens eine Backup-Art ausgewählt wurde
        if (!$backup_db && !$backup_files) {
            return [false, rex_i18n::msg('nextcloud_backup_no_type_selected')];
        }

        // Prüfen ob alle Nextcloud-Zugangsdaten angegeben wurden
        if (empty($nextcloud_url) || empty($nextcloud_username) || empty($nextcloud_password)) {
            return [false, rex_i18n::msg('nextcloud_config_missing')];
        }

        // Basis-Verzeichnis für temporäre Backups
        $backup_base_dir = rex_path::base('backup');
        
        // Stellen Sie sicher, dass das Backup-Verzeichnis existiert
        if (!file_exists($backup_base_dir)) {
            mkdir($backup_base_dir, 0755, true);
        }

        // Timestamp für die Benennung der Backups
        $timestamp = date('Y-m-d_H-i-s');
        
        // WebDAV-Client initialisieren
        $webdav_client = $this->initWebDavClient($nextcloud_url, $nextcloud_username, $nextcloud_password);
        
        if ($webdav_client === false) {
            return [false, rex_i18n::msg('nextcloud_backup_connection_error')];
        }
        
        // Stellen Sie sicher, dass die Zielverzeichnisse in der Nextcloud existieren
        $this->ensureWebDavDirectoryExists($webdav_client, $nextcloud_path);
        $this->ensureWebDavDirectoryExists($webdav_client, $nextcloud_path . '/db');
        $this->ensureWebDavDirectoryExists($webdav_client, $nextcloud_path . '/files');
        
        // Datenbank-Backup erstellen
        if ($backup_db) {
            try {
                $db_file = $backup_base_dir . '/redaxo_db_' . $timestamp . '.sql.gz';
                
                // Datenbank-Konfiguration auslesen
                $db = rex::getProperty('db');
                $db_host = $db[1]['host'];
                $db_name = $db[1]['name'];
                $db_user = $db[1]['login'];
                $db_password = $db[1]['password'];
                
                // mysqldump-Befehl ausführen
                $command = sprintf(
                    'mysqldump -h %s -u %s -p%s %s | gzip > %s',
                    escapeshellarg($db_host),
                    escapeshellarg($db_user),
                    escapeshellarg($db_password),
                    escapeshellarg($db_name),
                    escapeshellarg($db_file)
                );
                
                exec($command, $output, $return_var);
                
                if ($return_var !== 0) {
                    $success = false;
                    $message[] = rex_i18n::msg('nextcloud_backup_db_error') . ': ' . implode("\n", $output);
                } else {
                    $message[] = rex_i18n::msg('nextcloud_backup_db_success') . ': ' . basename($db_file);
                    
                    // In Nextcloud hochladen
                    $upload_result = $this->uploadToNextcloud(
                        $webdav_client, 
                        $db_file, 
                        $nextcloud_path . '/db/' . basename($db_file)
                    );
                    
                    if ($upload_result) {
                        $message[] = rex_i18n::msg('nextcloud_backup_db_upload_success');
                    } else {
                        $success = false;
                        $message[] = rex_i18n::msg('nextcloud_backup_db_upload_error');
                    }
                }
            } catch (Exception $e) {
                $success = false;
                $message[] = rex_i18n::msg('nextcloud_backup_db_exception') . ': ' . $e->getMessage();
            }
        }
        
        // Dateisystem-Backup erstellen
        if ($backup_files) {
            try {
                $files_dir = rex_path::base();
                $files_file = $backup_base_dir . '/redaxo_files_' . $timestamp . '.tar.gz';
                
                // Verzeichnisse, die wir nicht im Backup benötigen
                $exclude_dirs = [
                    'backup',
                    'cache',
                    'redaxo/cache',
                    'redaxo/data/cache',
                    'media/cache'
                ];
                
                $exclude_params = '';
                foreach ($exclude_dirs as $dir) {
                    $exclude_params .= ' --exclude=' . escapeshellarg($dir);
                }
                
                // tar-Befehl ausführen
                $command = sprintf(
                    'tar -czf %s -C %s %s .',
                    escapeshellarg($files_file),
                    escapeshellarg($files_dir),
                    $exclude_params
                );
                
                exec($command, $output, $return_var);
                
                if ($return_var !== 0) {
                    $success = false;
                    $message[] = rex_i18n::msg('nextcloud_backup_files_error') . ': ' . implode("\n", $output);
                } else {
                    $message[] = rex_i18n::msg('nextcloud_backup_files_success') . ': ' . basename($files_file);
                    
                    // In Nextcloud hochladen
                    $upload_result = $this->uploadToNextcloud(
                        $webdav_client, 
                        $files_file, 
                        $nextcloud_path . '/files/' . basename($files_file)
                    );
                    
                    if ($upload_result) {
                        $message[] = rex_i18n::msg('nextcloud_backup_files_upload_success');
                    } else {
                        $success = false;
                        $message[] = rex_i18n::msg('nextcloud_backup_files_upload_error');
                    }
                }
            } catch (Exception $e) {
                $success = false;
                $message[] = rex_i18n::msg('nextcloud_backup_files_exception') . ': ' . $e->getMessage();
            }
        }
        
        // Alte Backups bereinigen
        if ($max_backups > 0) {
            try {
                $this->cleanupOldBackups($webdav_client, $nextcloud_path . '/db', $max_backups);
                $this->cleanupOldBackups($webdav_client, $nextcloud_path . '/files', $max_backups);
                $message[] = rex_i18n::msg('nextcloud_backup_cleanup_success', $max_backups);
            } catch (Exception $e) {
                $success = false;
                $message[] = rex_i18n::msg('nextcloud_backup_cleanup_error') . ': ' . $e->getMessage();
            }
        }
        
        // Lokale Backup-Dateien löschen
        if ($backup_db) {
            @unlink($db_file);
        }
        if ($backup_files) {
            @unlink($files_file);
        }
        
        return [$success, implode("\n", $message)];
    }
    
    /**
     * Initialisiert den WebDAV-Client für Nextcloud
     */
    private function initWebDavClient($url, $username, $password)
    {
        // Überprüfen, ob cURL verfügbar ist
        if (!function_exists('curl_init')) {
            throw new Exception(rex_i18n::msg('nextcloud_backup_curl_not_available'));
        }
        
        // Stellen Sie sicher, dass die URL mit einem Slash endet
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }
        
        // Fügen Sie den remote.php/dav/files/ Pfad hinzu, wenn er nicht vorhanden ist
        if (strpos($url, 'remote.php/dav/files/') === false) {
            $url .= 'remote.php/dav/files/' . urlencode($username) . '/';
        }
        
        // Initialisieren des cURL-Handle
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Eventuell auf true setzen in Produktionsumgebungen
        
        // Überprüfen, ob die Verbindung hergestellt werden kann
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 0']);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($status === 0) {
            throw new Exception(rex_i18n::msg('nextcloud_backup_connection_error') . ': ' . curl_error($ch));
        }
        
        if ($status >= 400) {
            throw new Exception(rex_i18n::msg('nextcloud_backup_auth_failed', $status));
        }
        
        // cURL-Handle zurücksetzen
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
        
        return ['curl' => $ch, 'base_url' => $url];
    }
    
    /**
     * Stellt sicher, dass ein Verzeichnis in Nextcloud existiert
     */
    private function ensureWebDavDirectoryExists($client, $path)
    {
        $ch = $client['curl'];
        $url = $client['base_url'] . $this->normalizePath($path);
        
        // Prüfen ob das Verzeichnis existiert
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 0']);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Wenn das Verzeichnis nicht existiert, erstellen wir es
        if ($status === 404) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'MKCOL');
            curl_setopt($ch, CURLOPT_HTTPHEADER, []);
            
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($status >= 400) {
                throw new Exception(rex_i18n::msg('nextcloud_backup_dir_create_error', $path, $status));
            }
        }
        
        // cURL-Handle zurücksetzen
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
    }
    
    /**
     * Lädt eine Datei in die Nextcloud hoch
     */
    private function uploadToNextcloud($client, $local_file, $remote_path)
    {
        $ch = $client['curl'];
        $url = $client['base_url'] . $this->normalizePath($remote_path);
        
        // Datei zum Hochladen öffnen
        $file_handle = fopen($local_file, 'r');
        
        if ($file_handle === false) {
            throw new Exception(rex_i18n::msg('nextcloud_backup_local_file_open_error', $local_file));
        }
        
        // cURL für den Upload konfigurieren
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($local_file));
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Datei-Handle schließen
        fclose($file_handle);
        
        // cURL-Handle zurücksetzen
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_UPLOAD, false);
        curl_setopt($ch, CURLOPT_INFILE, null);
        
        return ($status >= 200 && $status < 300);
    }
    
    /**
     * Entfernt alte Backups und behält nur die neuesten
     */
    private function cleanupOldBackups($client, $directory, $max_keep)
    {
        $ch = $client['curl'];
        $url = $client['base_url'] . $this->normalizePath($directory);
        
        // Verzeichnisinhalt auflisten
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 1']);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($status >= 400) {
            throw new Exception(rex_i18n::msg('nextcloud_backup_dir_list_error', $directory, $status));
        }
        
        // XML-Antwort parsen
        $xml = new SimpleXMLElement($response);
        $xml->registerXPathNamespace('d', 'DAV:');
        
        // Alle Dateien finden
        $files = [];
        foreach ($xml->xpath('//d:response') as $response) {
            $href = (string)$response->xpath('./d:href')[0];
            $filename = basename($href);
            
            // Nur Backup-Dateien berücksichtigen
            if (preg_match('/^redaxo_(db|files)_.*\.(sql\.gz|tar\.gz)$/', $filename)) {
                $lastmodified = strtotime((string)$response->xpath('.//d:getlastmodified')[0]);
                $files[$href] = $lastmodified;
            }
        }
        
        // Nach Datum sortieren (neueste zuerst)
        arsort($files);
        
        // Die ältesten Dateien löschen
        $count = 0;
        foreach ($files as $href => $lastmodified) {
            $count++;
            
            if ($count > $max_keep) {
                // Datei löschen
                curl_setopt($ch, CURLOPT_URL, $client['base_url'] . substr($href, 1));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, []);
                
                $delete_response = curl_exec($ch);
                $delete_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($delete_status >= 400) {
                    throw new Exception(rex_i18n::msg('nextcloud_backup_file_delete_error', basename($href), $delete_status));
                }
            }
        }
        
        // cURL-Handle zurücksetzen
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
    }
    
    /**
     * Normalisiert einen Pfad für WebDAV
     */
    private function normalizePath($path)
    {
        // Entfernen Sie führende und nachfolgende Slashes
        $path = trim($path, '/');
        
        // URL-kodieren Sie jeden Pfadbestandteil einzeln
        $parts = explode('/', $path);
        $encoded_parts = array_map('rawurlencode', $parts);
        
        return implode('/', $encoded_parts);
    }
    
    public function getTypeName()
    {
        return rex_i18n::msg('nextcloud_backup_type_name');
    }
    
    public function getParamFields()
    {
        return [
            [
                'label' => rex_i18n::msg('nextcloud_baseurl'),
                'name' => 'nextcloud_url',
                'type' => 'text',
                'notice' => rex_i18n::msg('nextcloud_backup_url_notice')
            ],
            [
                'label' => rex_i18n::msg('nextcloud_username'),
                'name' => 'nextcloud_username',
                'type' => 'text'
            ],
            [
                'label' => rex_i18n::msg('nextcloud_password'),
                'name' => 'nextcloud_password',
                'type' => 'text',
                'attributes' => ['type' => 'password'],
                'notice' => rex_i18n::msg('nextcloud_password_notice')
            ],
            [
                'label' => rex_i18n::msg('nextcloud_backup_path'),
                'name' => 'nextcloud_path',
                'type' => 'text',
                'notice' => rex_i18n::msg('nextcloud_backup_path_notice')
            ],
            [
                'label' => rex_i18n::msg('nextcloud_backup_max_count'),
                'name' => 'max_backups',
                'type' => 'text',
                'default' => '5'
            ],
            [
                'label' => rex_i18n::msg('nextcloud_backup_database'),
                'name' => 'backup_db',
                'type' => 'select',
                'options' => ['0' => rex_i18n::msg('no'), '1' => rex_i18n::msg('yes')],
                'default' => '1'
            ],
            [
                'label' => rex_i18n::msg('nextcloud_backup_files'),
                'name' => 'backup_files',
                'type' => 'select',
                'options' => ['0' => rex_i18n::msg('no'), '1' => rex_i18n::msg('yes')],
                'default' => '1'
            ]
        ];
    }
}
