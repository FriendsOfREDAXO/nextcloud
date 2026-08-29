<?php

namespace FriendsOfRedaxo\NextCloud;

use FriendsOfRedaxo\Mediaplace\StorageProviderInterface;

/**
 * Duenner Adapter, der die bestehende NextCloud-Klasse hinter MediaPlace's
 * StorageProviderInterface (siehe mediaplace/lib/StorageProviderInterface.php)
 * anbietet -- rein lesendes Browsen/Suchen + Import in den lokalen
 * Medienpool, kein Sync. Registriert ueber den Erweiterungspunkt
 * MEDIAPLACE_STORAGE_PROVIDERS, siehe boot.php.
 *
 * Baut auf bereits vorhandener Logik auf, dupliziert nichts: listEntries()
 * ruft NextCloud::listFiles()/searchFilesRecursive() auf, importToMediaPool()
 * ruft NextCloud::importToMediapool() auf.
 */
class MediaplaceProvider implements StorageProviderInterface
{
    private NextCloud $client;

    public function __construct()
    {
        $this->client = new NextCloud();
    }

    public function listEntries(string $path, ?string $search = null): array
    {
        $path = '' !== $path ? $path : '/';
        $entries = null !== $search && '' !== trim($search)
            ? $this->client->searchFilesRecursive($path, $search)
            : $this->client->listFiles($path);

        // Bewusst kein "static function" hier: isPreviewableMimeType() unten
        // ist eine Instanzmethode (braucht $this).
        return array_map(function (array $entry): array {
            $isFolder = 'folder' === $entry['type'];
            $name = (string) $entry['name'];

            return [
                'path' => (string) $entry['path'],
                'name' => $name,
                'type' => $isFolder ? 'folder' : 'file',
                'filesize' => $isFolder ? null : (int) ($entry['size_bytes'] ?? 0),
                // Extension statt Nextclouds grober Kategorie (image/pdf/...) --
                // deckungsgleich damit, wie MediaPlace lokale Dateien anhand
                // der Endung einordnet (isImage()/isVideo() in mediapool3-helpers.js).
                'filetype' => $isFolder ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified' => '' !== (string) ($entry['modified'] ?? '') ? (string) $entry['modified'] : null,
                'hasThumbnail' => !$isFolder && null !== ($entry['fileid'] ?? null) && $this->isPreviewableMimeType((string) ($entry['mimetype'] ?? '')),
            ];
        }, $entries);
    }

    public function hasSearch(): bool
    {
        return true;
    }

    public function getThumbnail(string $path): ?array
    {
        // Die fileid ist im Listing-Ergebnis enthalten, nicht im Pfad selbst --
        // ein gezielter (Depth 0) Lookup fuer genau diese eine Datei ist
        // guenstiger als die ganze Ordnerliste erneut zu holen.
        $tags = $this->client->getFileTags($path);
        $fileId = $tags['fileid'] ?? null;
        if (null === $fileId || '' === $fileId) {
            return null;
        }

        return $this->client->getPreviewContent((string) $fileId, 300, 300);
    }

    public function importToMediaPool(string $path, int $categoryId): string
    {
        $result = $this->client->importToMediapool($path, $categoryId);

        if (empty($result['ok']) || empty($result['filename'])) {
            throw new \rex_exception((string) ($result['msg'] ?? 'Import fehlgeschlagen'));
        }

        return (string) $result['filename'];
    }

    /**
     * Nextclouds Preview-API erzeugt nur fuer eine begrenzte Menge an
     * MIME-Typen tatsaechlich ein Bild (Fotos, teils PDFs/Office-Dokumente je
     * nach Server-Plugins) -- fuer alles andere kaeme ohnehin ein Fehlerbild
     * oder 404 zurueck. Bewusst konservativ (nur Bild-MIME-Typen), damit
     * hasThumbnail nicht faelschlich "ja" fuer Typen meldet, die serverseitig
     * unzuverlaessig sind; getThumbnail() faengt einen Fehlschlag ohnehin ab
     * (liefert null -> Datei-Icon-Fallback), das ist hier nur eine
     * Kosten-Optimierung (kein unnoetiger Preview-Request fuer z.B. .zip).
     */
    private function isPreviewableMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }
}
