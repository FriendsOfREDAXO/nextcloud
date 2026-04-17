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
                    if (!rex_config::get('nextcloud', 'enable_sharing', true)) {
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
