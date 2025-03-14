<?php
namespace Klxm\Nextcloud;


if (\rex_addon::get('cronjob')->isAvailable()) {
    \rex_cronjob_manager::registerType(\rex_cronjob_redaxo_backup::class);
}

// Nur im Backend ausführen
if (\rex::isBackend() && \rex::getUser()) {

    // Assets nur auf der NextCloud-Seite einbinden
    if (\rex_be_controller::getCurrentPage() == 'nextcloud/main') {
        \rex_view::addJsFile($this->getAssetsUrl('nextcloud.js'));
    }

    // AJAX Handler für NextCloud API
    if (\rex_request('nextcloud_api', 'bool', false)) {
        try {
            $action = \rex_request('action', 'string');
            $path = \rex_request('path', 'string', '/');
            $categoryId = \rex_request('category_id', 'integer', 0);

            $api = new NextCloud();
            
            switch ($action) {
                case 'list':
                    $files = $api->listFiles($path);
                    \rex_response::sendJson(['success' => true, 'data' => $files]);
                    break;

                case 'preview':
                    // Für Bildvorschau senden wir direkt das Bild
                    rex_response::cleanOutputBuffers(); // OutputBuffer leeren
                    $content = $api->getImageContent($path);
                    $filename = basename($path);
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    // Content-Type setzen
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp'
                    ];
                    $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
                    header('Content-Type: ' . $contentType);
                    echo $content;
                    exit;

                case 'import':
                    $result = $api->importToMediapool($path, $categoryId);
                    \rex_response::sendJson(['success' => true, 'data' => $result]);
                    break;

                default:
                    throw new \rex_exception('Invalid action');
            }
        } catch (\Exception $e) {
            \rex_logger::factory()->log('error', 'NextCloud AddOn Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            \rex_response::sendJson(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
