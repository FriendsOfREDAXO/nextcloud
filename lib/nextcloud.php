<?php

namespace FriendsOfRedaxo\NextCloud;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;
use FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudClient;

/**
 * Duenner Feature-Layer ueber cloudconnects NextcloudClient (seit 2.0.0,
 * siehe CHANGELOG.md) -- diese Klasse haelt selbst keine Zugangsdaten und
 * macht keine eigenen HTTP-Requests mehr (kein cURL/PROPFIND/OCS-Unterbau
 * mehr wie vor 2.0.0). Alle Transport-Grundbausteine (WebDAV/OCS, Auth,
 * SSL) delegiert sie an $this->client; hier verbleibt nur addon-eigene
 * Feature-Logik ohne HTTP-Bezug (Medienpool-Import/-Upload-Orchestrierung,
 * ZIP-Bau, Pfad-Validierung, Tag-Anwendung als rex_media-Update).
 *
 * Mehrkonten-faehig: jede Instanz ist an genau eine, per Konstruktor
 * uebergebene cloudconnect-Verbindung gebunden (kein globaler Fallback
 * mehr) -- Aufrufer (rex_api_nextcloud, rex_var_nextcloud_share,
 * nextcloud_backup) reichen die jeweils gewuenschte connection_id durch.
 */
class NextCloud
{
    private NextcloudClient $client;

    public function __construct(string $connectionId)
    {
        $connection = ConnectionStore::get($connectionId);
        if (null === $connection || 'nextcloud' !== ($connection['type'] ?? '')) {
            throw new \rex_exception('Unknown or invalid Nextcloud connection: ' . $connectionId);
        }

        $this->client = new NextcloudClient($connectionId);
    }

    /**
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null, fileid: string|null, mimetype: string|null}>
     */
    public function listFiles(string $path = '/'): array
    {
        return $this->client->listFiles($path);
    }

    public function getImageContent(string $path): string
    {
        try {
            return $this->client->getContent($path);
        } catch (\Exception $e) {
            throw new \rex_exception('Failed to get image: ' . $e->getMessage());
        }
    }

    /**
     * Nextclouds eigene Vorschau-/Thumbnail-API (nicht das volle Original wie
     * getImageContent()) -- fuer Bild-/PDF-/Video-Vorschau im Browser sowie
     * die MediaPlace-Cloud-Provider-Integration von cloudconnect. Braucht
     * die Nextcloud-interne fileid (siehe 'fileid'-Feld in listFiles()/
     * searchFilesRecursive()), nicht den WebDAV-Pfad. Liefert null statt
     * einer Exception, wenn keine Vorschau erzeugt werden kann -- Aufrufer
     * faellt dann auf das Datei-Icon zurueck.
     *
     * @return array{content: string, contentType: string}|null
     */
    public function getPreviewContent(string $fileId, int $width = 300, int $height = 300): ?array
    {
        return $this->client->getPreviewContent($fileId, $width, $height);
    }

    /**
     * Sucht serverseitig nach Datei- und Ordnernamen unterhalb eines Startpfads.
     *
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null, fileid: string|null, mimetype: string|null}>
     */
    public function searchFilesRecursive(string $basePath = '/', string $query = ''): array
    {
        $normalizedQuery = trim($query);
        if ('' === $normalizedQuery) {
            return $this->listFiles($basePath);
        }

        $this->assertSafeDisplayPath($basePath);

        return $this->client->searchFilesRecursive($basePath, $normalizedQuery);
    }

    public function importToMediapool(string $path, int $categoryId = 0)
    {
        try {
            \rex_logger::factory()->log('debug', 'NextCloud Import', [
                'original_path' => $path,
            ]);

            $content = $this->client->getContent($path);

            // Dekodiere den Dateinamen fuer die temporaere Datei
            $filename = rawurldecode(basename($path));

            $tmpName = \rex_string::normalize($filename);
            $tmpfile = \rex_path::cache('nextcloud_' . $tmpName);

            if (false === file_put_contents($tmpfile, $content)) {
                throw new \rex_exception('Could not save temporary file');
            }

            $data = [];
            $data['file'] = [
                'name' => $filename,
                'path' => $tmpfile,
                'tmp_name' => $tmpfile,
            ];
            $data['category_id'] = $categoryId;
            $data['title'] = pathinfo($filename, PATHINFO_FILENAME);

            $result = \rex_media_service::addMedia($data, true);

            @unlink($tmpfile);

            return $result;
        } catch (\Exception $e) {
            \rex_logger::factory()->log('error', 'NextCloud Import Error', [
                'error' => $e->getMessage(),
                'path' => $path,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Laedt eine bestehende Datei aus dem REDAXO-Medienpool in einen Nextcloud-Ordner hoch.
     *
     * @return array<string, string>
     */
    public function uploadFromMediapool(string $mediaFilename, string $targetPath = '/'): array
    {
        $mediaFilename = trim($mediaFilename);
        if ('' === $mediaFilename) {
            throw new \rex_exception('Kein Dateiname für den Upload übergeben.');
        }

        $this->assertSafeDisplayPath($targetPath);

        $media = \rex_media::get($mediaFilename);
        if (!$media) {
            throw new \rex_exception('Die angegebene Medienpool-Datei existiert nicht.');
        }

        $localPath = \rex_path::media($mediaFilename);
        if (!is_file($localPath)) {
            throw new \rex_exception('Die Medienpool-Datei konnte lokal nicht gefunden werden.');
        }

        $content = \rex_file::get($localPath);

        $normalizedTargetPath = '/' . trim(rawurldecode($targetPath), '/');
        if ('//' === $normalizedTargetPath) {
            $normalizedTargetPath = '/';
        }

        $remoteFilePath = '/' === $normalizedTargetPath
            ? '/' . $mediaFilename
            : $normalizedTargetPath . '/' . $mediaFilename;

        $this->client->putContent($remoteFilePath, (string) $content);

        return [
            'filename' => $mediaFilename,
            'target_path' => $normalizedTargetPath,
            'remote_path' => $remoteFilePath,
        ];
    }

    /**
     * Loescht eine Datei in Nextcloud anhand des displayPath.
     *
     * @return array<string, string>
     */
    public function deleteFile(string $displayPath): array
    {
        $this->assertSafeDisplayPath($displayPath);

        $normalizedPath = '/' . trim(rawurldecode($displayPath), '/');
        if ('/' === $normalizedPath || '//' === $normalizedPath) {
            throw new \rex_exception('Das Wurzelverzeichnis kann nicht gelöscht werden.');
        }

        try {
            $this->client->deleteFile($normalizedPath);
        } catch (\rex_exception $e) {
            // NextcloudClient::request() wirft bei Nicht-2xx-Status
            // 'Nextcloud server returned HTTP <status> for ...' -- Format seit
            // dem Umzug nach cloudconnect anders als frueher ('status code: ...'),
            // die 403-spezifische Fehlermeldung entsprechend angepasst.
            if (str_contains($e->getMessage(), 'HTTP 403')) {
                throw new \rex_exception('Löschen von Nextcloud verweigert (403). Bitte Rechte auf Datei/Ordner und mögliche Sperren prüfen.');
            }
            throw $e;
        }

        return [
            'path' => $normalizedPath,
        ];
    }

    /**
     * Erstellt ein ZIP-Archiv aus einer Liste von Nextcloud-Pfaden.
     *
     * @param list<string> $displayPaths
     *
     * @return array{zip_path: string, filename: string}
     */
    public function createZipFromPaths(array $displayPaths, string $baseFilename = 'nextcloud-download'): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \rex_exception('ZipArchive ist nicht verfügbar.');
        }

        $zipPath = \rex_path::cache('nextcloud_download_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip');
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \rex_exception('ZIP-Datei konnte nicht erstellt werden.');
        }

        $addedFiles = 0;

        foreach ($displayPaths as $displayPath) {
            $displayPath = trim((string) $displayPath);
            if ('' === $displayPath || '/' === $displayPath) {
                continue;
            }

            $this->assertSafeDisplayPath($displayPath);

            $normalizedPath = '/' . trim(rawurldecode($displayPath), '/');
            if ('/' === $normalizedPath || '//' === $normalizedPath) {
                continue;
            }

            if ($this->isFolderPath($normalizedPath)) {
                $entryPrefix = trim((string) basename($normalizedPath), '/');
                if ('' === $entryPrefix) {
                    $entryPrefix = 'ordner';
                }
                $zip->addEmptyDir($entryPrefix);
                ++$addedFiles;
                $addedFiles += $this->addFolderToZip($zip, $normalizedPath, $entryPrefix);
            } else {
                $content = $this->getImageContent($normalizedPath);
                $entryName = ltrim($normalizedPath, '/');
                if ('' === $entryName) {
                    $entryName = (string) basename($normalizedPath);
                }
                $zip->addFromString($entryName, $content);
                ++$addedFiles;
            }
        }

        $zip->close();

        if (0 === $addedFiles) {
            \rex_file::delete($zipPath);
            throw new \rex_exception('Es wurden keine Dateien für das ZIP-Archiv gefunden.');
        }

        return [
            'zip_path' => $zipPath,
            'filename' => $baseFilename . '_' . date('Ymd_His') . '.zip',
        ];
    }

    private function addFolderToZip(\ZipArchive $zip, string $folderPath, string $entryPrefix): int
    {
        $addedFiles = 0;
        $children = $this->listFiles($folderPath);

        foreach ($children as $child) {
            $childPath = (string) ($child['path'] ?? '');
            $childName = (string) ($child['name'] ?? '');
            $childType = (string) ($child['type'] ?? 'file');

            if ('' === $childPath || '' === $childName) {
                continue;
            }

            if ('folder' === $childType) {
                $childDir = trim($entryPrefix . '/' . $childName, '/');
                $zip->addEmptyDir($childDir);
                $addedFiles += $this->addFolderToZip($zip, $childPath, $childDir);
                continue;
            }

            $content = $this->getImageContent($childPath);
            $entryName = trim($entryPrefix . '/' . $childName, '/');
            $zip->addFromString($entryName, $content);
            ++$addedFiles;
        }

        return $addedFiles;
    }

    private function isFolderPath(string $displayPath): bool
    {
        $parentPath = dirname($displayPath);
        if ('.' === $parentPath || '\\' === $parentPath) {
            $parentPath = '/';
        }
        $parentPath = '/' . trim($parentPath, '/');
        if ('//' === $parentPath) {
            $parentPath = '/';
        }

        $name = (string) basename($displayPath);
        foreach ($this->listFiles($parentPath) as $entry) {
            if ((string) ($entry['name'] ?? '') !== $name) {
                continue;
            }
            return 'folder' === (string) ($entry['type'] ?? 'file');
        }

        return false;
    }

    /**
     * Blockiert Pfad-Traversal im displayPath.
     */
    private function assertSafeDisplayPath(string $displayPath): void
    {
        $decodedPath = rawurldecode($displayPath);
        $segments = explode('/', trim($decodedPath, '/'));
        foreach ($segments as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \rex_exception('Ungültiger Zielpfad.');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Share Links (Nextcloud OCS Share API)
    // -------------------------------------------------------------------------

    /**
     * Erstellt einen öffentlichen Share-Link für eine Datei in der Nextcloud.
     * Reines Passthrough an den Client -- Ergebnis-Caching (rex_config
     * share_cache_*) bleibt Aufgabe des Aufrufers (rex_var_nextcloud_share.php).
     *
     * @return array{url: string, token: string, id: int, expiration: string|null}
     */
    public function createShareLink(string $displayPath, ?string $expireDate = null, int $permissions = 1): array
    {
        return $this->client->createShareLink($displayPath, $expireDate, $permissions);
    }

    // -------------------------------------------------------------------------
    // Dateimetadaten (Tags über WebDAV PROPFIND)
    // -------------------------------------------------------------------------

    /**
     * Holt Nextcloud-Tags und die interne Datei-ID für einen Dateipfad.
     *
     * @return array{fileid: string|null, tags: list<string>}
     */
    public function getFileTags(string $path): array
    {
        return $this->client->getFileTags($path);
    }

    /**
     * Wendet Nextcloud-Tags als REDAXO-Mediametadaten an (nach dem Import).
     *
     * @param list<string> $tags Nextcloud-Tags
     */
    public function applyTagsToMedia(string $filename, array $tags, string $fieldName): void
    {
        if ([] === $tags || '' === $fieldName) {
            return;
        }

        // Pruefen ob die Spalte tatsaechlich existiert
        $sql = \rex_sql::factory();
        $columns = array_column(
            $sql->getArray('SHOW COLUMNS FROM `' . \rex::getTablePrefix() . 'media`'),
            'Field',
        );

        if (!in_array($fieldName, $columns, true)) {
            \rex_logger::factory()->log('warning', 'NextCloud: Zielspalte für Tags nicht gefunden', [
                'field' => $fieldName,
            ]);
            return;
        }

        $update = \rex_sql::factory();
        $update->setTable(\rex::getTablePrefix() . 'media');
        $update->setWhere(['filename' => $filename]);
        $update->setValue($fieldName, implode(', ', $tags));
        $update->setValue('updatedate', date(\rex_sql::FORMAT_DATETIME));
        $update->setValue('updateuser', \rex::getUser() ? \rex::getUser()->getLogin() : 'nextcloud');
        $update->update();
    }
}
