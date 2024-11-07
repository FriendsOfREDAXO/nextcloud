<?php
namespace Klxm\Nextcloud;

/**
 * Class NextCloud
 * @package Klxm\Nextcloud
 */
class NextCloud {
    private string $baseUrl;
    private string $username;
    private string $password;

    /**
     * NextCloud constructor.
     * @throws \rex_exception
     */
    public function __construct() {
        $this->baseUrl = \rex_config::get('nextcloud', 'baseurl');
        $this->username = \rex_config::get('nextcloud', 'username');
        $this->password = \rex_config::get('nextcloud', 'password');

        if (!$this->baseUrl || !$this->username || !$this->password) {
            throw new \rex_exception('NextCloud configuration missing');
        }

        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * Get image content from NextCloud
     * 
     * @param string $path
     * @return string
     * @throws \rex_exception
     */
    public function getImageContent(string $path): string {
        try {
            $webdavPath = '/remote.php/dav/files/' . urlencode($this->username) . $path;
            return $this->request($webdavPath, 'GET');
        } catch (\Exception $e) {
            throw new \rex_exception("Failed to get image: " . $e->getMessage());
        }
    }

    /**
     * Make a request to NextCloud
     * 
     * @param string $path
     * @param string $method
     * @param string|null $data
     * @return string
     * @throws \rex_exception
     */
    private function request(string $path, string $method = 'GET', ?string $data = null): string {
        $url = $this->baseUrl . $path;

        // \rex_logger::factory()->log('debug', 'NextCloud Request', [
        //     'url' => $url,
        //     'method' => $method
        // ]);

        $ch = curl_init();

        $headers = [];

        if ($method === 'PROPFIND') {
            $headers[] = 'Content-Type: application/xml';
            $headers[] = 'Depth: 1';
            $data = '<?xml version="1.0" encoding="utf-8" ?>
                     <d:propfind xmlns:d="DAV:">
                         <d:prop>
                             <d:getlastmodified />
                             <d:getcontentlength />
                             <d:resourcetype />
                             <d:getetag />
                         </d:prop>
                     </d:propfind>';
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ":" . $this->password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true
        ];

        if ($data) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            // \rex_logger::factory()->log('error', 'NextCloud cURL Error', [
            //     'error' => $error,
            //     'code' => curl_errno($ch),
            //     'url' => $url
            // ]);
            curl_close($ch);
            throw new \rex_exception("cURL Error: " . $error);
        }

        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400) {
            return $response;
        }

        throw new \rex_exception("API request failed with status code: " . $httpCode);
    }

    /**
     * Normalize a file path
     * 
     * @param string $path
     * @return string
     */
    private function normalizePath(string $path): string {
        $path = '/' . trim($path, '/');
        $path = str_replace('//', '/', $path);
        return $path === '//' ? '/' : $path;
    }

    /**
     * List files in a directory
     * 
     * @param string $path
     * @return array
     * @throws \rex_exception
     */
    public function listFiles(string $path = '/'): array {
        try {
            $path = $this->normalizePath($path);
            $url = '/remote.php/dav/files/' . urlencode($this->username) . $path;

            // \rex_logger::factory()->log('debug', 'NextCloud ListFiles', [
            //     'path' => $path,
            //     'url' => $url
            // ]);

            $response = $this->request($url, 'PROPFIND');

            // Remove invalid characters from XML
            $response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $response);

            // Set libxml options
            $previousLibXmlUseErrors = libxml_use_internal_errors(true);

            try {
                $xml = new \SimpleXMLElement($response);
            } catch (\Exception $e) {
                // \rex_logger::factory()->log('error', 'XML Parse Error', [
                //     'error' => $e->getMessage(),
                //     'response' => substr($response, 0, 1000)
                // ]);
                throw new \rex_exception('Failed to parse server response');
            } finally {
                libxml_use_internal_errors($previousLibXmlUseErrors);
            }

            $xml->registerXPathNamespace('d', 'DAV:');

            $files = [];
            foreach ($xml->xpath('//d:response') as $response) {
                $href = (string)$response->xpath('d:href')[0];
                $displayname = basename($href);

                // Skip current directory
                if ($displayname === '' || urldecode($href) === $url) {
                    continue;
                }

                $props = $response->xpath('d:propstat/d:prop')[0];
                $isDirectory = !empty($props->xpath('d:resourcetype/d:collection'));

                $size = '';
                if (!$isDirectory && !empty($props->xpath('d:getcontentlength'))) {
                    $size = $this->formatSize((int)$props->xpath('d:getcontentlength')[0]);
                }

                $lastMod = '';
                if (!empty($props->xpath('d:getlastmodified'))) {
                    $lastMod = date('Y-m-d H:i', strtotime((string)$props->xpath('d:getlastmodified')[0]));
                }

                $relativePath = str_replace('/remote.php/dav/files/' . $this->username, '', urldecode($href));
                $relativePath = $this->normalizePath($relativePath);

                $fileType = $isDirectory ? 'folder' : $this->getFileType($displayname);
                $fileData = [
                    'name' => urldecode($displayname),
                    'path' => $relativePath,
                    'type' => $fileType,
                    'size' => $size,
                    'modified' => $lastMod
                ];

                // Add preview for images
                if (!$isDirectory && $fileType === 'image') {
                    try {
                        $previewUrl = $this->getImagePreview($relativePath);
                        if ($previewUrl) {
                            $fileData['preview'] = $previewUrl;
                        }
                    } catch (\Exception $e) {
                        // \rex_logger::factory()->log('warning', 'Failed to get preview', [
                        //     'error' => $e->getMessage(),
                        //     'file' => $relativePath
                        // ]);
                    }
                }

                $files[] = $fileData;
            }

            return $files;

        } catch (\Exception $e) {
            // \rex_logger::factory()->log('error', 'NextCloud ListFiles Error', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);
            throw $e;
        }
    }

    /**
     * Get image preview URL
     * 
     * @param string $path
     * @return string
     */
    private function getImagePreview(string $path): string {
        try {
            // Use NextCloud Preview API
            $previewPath = '/remote.php/dav/files/' . urlencode($this->username) . $path;
            $previewUrl = $this->baseUrl . $previewPath;

            // Return direct URL to preview
            return $previewUrl;

        } catch (\Exception $e) {
            // \rex_logger::factory()->log('error', 'Preview generation failed', [
            //     'error' => $e->getMessage(),
            //     'file' => $path
            // ]);
            return '';
        }
    }

    /**
     * Import file to media pool
     * 
     * @param string $path
     * @param int $categoryId
     * @return array
     * @throws \rex_exception
     */
    public function importToMediapool(string $path, int $categoryId = 0): array {
        try {
            $path = $this->normalizePath($path);
            $url = '/remote.php/dav/files/' . urlencode($this->username) . $path;

            $content = $this->request($url, 'GET');

            $filename = basename($path);
            $tmpfile = \rex_path::cache('nextcloud_' . $filename);

            if (file_put_contents($tmpfile, $content) === false) {
                throw new \rex_exception('Could not save temporary file');
            }

            $data = [];
            $data['file'] = [
                'name' => $filename,
                'path' => $tmpfile,
                'tmp_name' => $tmpfile
            ];
            $data['category_id'] = $categoryId;
            $data['title'] = pathinfo($filename, PATHINFO_FILENAME);

            $result = \rex_media_service::addMedia($data, true);

            @unlink($tmpfile);

            return $result;

        } catch (\Exception $e) {
            throw new \rex_exception("Failed to import file: " . $e->getMessage());
        }
    }

    /**
     * Get file type based on extension
     * 
     * @param string $filename
     * @return string
     */
    private function getFileType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $documentTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf'];
        $archiveTypes = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        $audioTypes = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
        $videoTypes = ['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv'];

        if (in_array($ext, $imageTypes)) return 'image';
        if (in_array($ext, $documentTypes)) return 'document';
        if (in_array($ext, $archiveTypes)) return 'archive';
        if (in_array($ext, $audioTypes)) return 'audio';
        if (in_array($ext, $videoTypes)) return 'video';
        return 'file';
    }

    /**
     * Format file size
     * 
     * @param int $bytes
     * @return string
     */
    private function formatSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}