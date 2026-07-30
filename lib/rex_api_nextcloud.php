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
}
