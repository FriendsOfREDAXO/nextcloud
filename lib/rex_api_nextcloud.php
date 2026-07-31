<?php

use FriendsOfRedaxo\NextCloud\NextCloud;

/**
 * AJAX-Handler für das NextCloud AddOn.
 *
 * Aufruf: index.php?rex-api-call=nextcloud&action=<action>&...
 */
class rex_api_nextcloud extends rex_api_function
{
    /** Nur im Backend erreichbar */
    protected $published = false;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $action     = rex_request('action', 'string');
        $path       = rex_request('path', 'string', '/');
        $categoryId = rex_request('category_id', 'integer', 0);

        $user = rex::getUser();
        if (!$user || (!$user->isAdmin() && !$user->hasPerm('nextcloud[]'))) {
            rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung für das Nextcloud-Addon.']);
            exit;
        }

        try {
            $api = new NextCloud();

            switch ($action) {
                case 'list':
                    $files = $api->listFiles($path);
                    rex_response::sendJson(['success' => true, 'data' => $files]);
                    exit;

                case 'search':
                    $query = rex_request('query', 'string', '');
                    if (trim($query) === '') {
                        $files = $api->listFiles($path);
                    } else {
                        $files = $api->searchFilesRecursive($path, $query);
                    }
                    rex_response::sendJson(['success' => true, 'data' => $files]);
                    exit;

                case 'preview':
                    $content   = $api->getImageContent($path);
                    $extension = strtolower(pathinfo(basename($path), PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png',
                        'gif'  => 'image/gif',
                        'webp' => 'image/webp',
                    ];
                    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
                    echo $content;
                    exit;

                case 'pdf_preview':
                    $content  = $api->getImageContent($path);
                    $filename = basename($path);
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . $filename . '"');
                    echo $content;
                    exit;

                case 'video_preview':
                    $content   = $api->getImageContent($path);
                    $extension = strtolower(pathinfo(basename($path), PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'mp4'  => 'video/mp4',
                        'm4v'  => 'video/mp4',
                        'webm' => 'video/webm',
                        'ogv'  => 'video/ogg',
                        'ogg'  => 'video/ogg',
                        'mov'  => 'video/quicktime',
                        'avi'  => 'video/x-msvideo',
                        'mkv'  => 'video/x-matroska',
                    ];
                    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
                    header('Content-Disposition: inline; filename="' . basename($path) . '"');
                    echo $content;
                    exit;

                case 'import':
                    $result    = $api->importToMediapool($path, $categoryId);
                    $tagsField = rex_config::get('nextcloud', 'tags_field', '');
                    if ($tagsField && isset($result['filename'])) {
                        $meta = $api->getFileTags($path);
                        if (!empty($meta['tags'])) {
                            $api->applyTagsToMedia($result['filename'], $meta['tags'], $tagsField);
                            $result['tags_applied'] = $meta['tags'];
                        }
                    }
                    rex_response::sendJson(['success' => true, 'data' => $result]);
                    exit;

                case 'share':
                    if ('1' !== (string) rex_config::get('nextcloud', 'enable_sharing', '1')) {
                        rex_response::sendJson(['success' => false, 'error' => 'Share-Links sind in den AddOn-Einstellungen deaktiviert.']);
                        exit;
                    }
                    $expiry    = rex_request('expiry', 'string', '');
                    $shareData = $api->createShareLink($path, $expiry ?: null);
                    rex_response::sendJson(['success' => true, 'data' => $shareData]);
                    exit;

                case 'get_tags':
                    $meta = $api->getFileTags($path);
                    rex_response::sendJson(['success' => true, 'data' => $meta]);
                    exit;

                case 'upload_mediapool':
                    if (!$user->isAdmin() && !$user->hasPerm('nextcloud[upload_mediapool]')) {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung für den Upload aus dem Medienpool.']);
                        exit;
                    }

                    $mediaFilename = rex_request('media_filename', 'string', '');
                    $targetPath = rex_request('target_path', 'string', '/');

                    if ($mediaFilename === '') {
                        rex_response::sendJson(['success' => false, 'error' => 'Es wurde keine Medienpool-Datei ausgewählt.']);
                        exit;
                    }

                    $media = rex_media::get($mediaFilename);
                    if (!$media) {
                        rex_response::sendJson(['success' => false, 'error' => 'Die angegebene Medienpool-Datei existiert nicht.']);
                        exit;
                    }

                    $mediaPerm = $user->getComplexPerm('media');
                    if (!$mediaPerm->hasCategoryPerm((int) $media->getCategoryId())) {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung für diese Medienpool-Kategorie.']);
                        exit;
                    }

                    $result = $api->uploadFromMediapool($mediaFilename, $targetPath);
                    rex_response::sendJson(['success' => true, 'data' => $result]);
                    exit;

                case 'delete':
                    if (!$user->isAdmin() && !$user->hasPerm('nextcloud[delete]')) {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung zum Löschen von Nextcloud-Dateien.']);
                        exit;
                    }

                    if ($path === '' || $path === '/') {
                        rex_response::sendJson(['success' => false, 'error' => 'Ungültiger Dateipfad.']);
                        exit;
                    }

                    $result = $api->deleteFile($path);
                    rex_response::sendJson(['success' => true, 'data' => $result]);
                    exit;

                case 'download':
                    if (!$user->isAdmin() && !$user->hasPerm('nextcloud[download]')) {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung zum Herunterladen von Nextcloud-Dateien.']);
                        exit;
                    }

                    if ($path === '' || $path === '/') {
                        rex_response::sendJson(['success' => false, 'error' => 'Ungültiger Dateipfad.']);
                        exit;
                    }

                    $itemType = rex_request('item_type', 'string', 'file');
                    if ($itemType === 'folder') {
                        $zipResult = $api->createZipFromPaths([$path], 'nextcloud-ordner');
                        $this->sendLocalFileDownload($zipResult['zip_path'], $zipResult['filename'], 'application/zip');
                    }

                    $content = $api->getImageContent($path);
                    $filename = (string) basename(rawurldecode($path));
                    $this->sendContentDownload($content, $filename, $this->detectMimeTypeFromFilename($filename));

                case 'download_zip':
                    if (!$user->isAdmin() && !$user->hasPerm('nextcloud[download]')) {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Berechtigung zum Herunterladen von Nextcloud-Dateien.']);
                        exit;
                    }

                    $pathsJson = rex_request('paths_json', 'string', '');
                    if ($pathsJson === '') {
                        rex_response::sendJson(['success' => false, 'error' => 'Keine Pfade für den ZIP-Download übergeben.']);
                        exit;
                    }

                    $paths = json_decode($pathsJson, true);
                    if (!is_array($paths) || count($paths) === 0) {
                        rex_response::sendJson(['success' => false, 'error' => 'Ungültige Pfadliste für den ZIP-Download.']);
                        exit;
                    }

                    $zipResult = $api->createZipFromPaths($paths, 'nextcloud-auswahl');
                    $this->sendLocalFileDownload($zipResult['zip_path'], $zipResult['filename'], 'application/zip');

                default:
                    rex_response::sendJson(['success' => false, 'error' => 'Invalid action']);
                    exit;
            }
        } catch (Exception $e) {
            rex_logger::factory()->log('error', 'NextCloud AddOn Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            rex_response::sendJson(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        // Wird nie erreicht – alle Switch-Zweige rufen exit auf
        // @phpstan-ignore deadCode.unreachable
        return new rex_api_result(true);
    }

    /**
     * CSRF-Schutz für diese AJAX-Endpunkte deaktiviert –
     * Zugriffsschutz erfolgt über die Backend-Session-Prüfung (published = false).
     */
    protected function requiresCsrfProtection(): bool
    {
        return false;
    }

    private function sendContentDownload(string $content, string $filename, string $contentType): void
    {
        $safeName = $this->sanitizeDownloadFilename($filename);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    private function sendLocalFileDownload(string $filePath, string $downloadFilename, string $contentType): void
    {
        if (!is_file($filePath)) {
            rex_response::sendJson(['success' => false, 'error' => 'Download-Datei wurde nicht gefunden.']);
            exit;
        }

        $safeName = $this->sanitizeDownloadFilename($downloadFilename);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        rex_file::delete($filePath);
        exit;
    }

    private function detectMimeTypeFromFilename(string $filename): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
        ];

        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    private function sanitizeDownloadFilename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return 'download.bin';
        }

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download.bin';
    }
}
