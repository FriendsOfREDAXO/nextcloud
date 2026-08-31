<?php

use FriendsOfRedaxo\CloudConnect\ConnectionStore;
use FriendsOfRedaxo\NextCloud\NextCloud;

/**
 * REX_NEXTCLOUD_SHARE – gibt einen öffentlichen Nextcloud-Share-Link zurück.
 *
 * Syntax im Modul/Template:
 *   REX_NEXTCLOUD_SHARE[path="/Dokumente/datei.pdf"]
 *   REX_NEXTCLOUD_SHARE[path="/Dokumente/datei.pdf" expiry="2027-12-31"]
 *   REX_NEXTCLOUD_SHARE[path="/Dokumente/datei.pdf" reset="1"]
 *   REX_NEXTCLOUD_SHARE[path="/Dokumente/datei.pdf" connection="a1b2c3d4"]
 *
 * "connection": Id eines in cloudconnect konfigurierten Nextcloud-Accounts
 * (siehe Einstellungsseite dort). Optional -- fehlt der Parameter, wird der
 * erste aktive Nextcloud-Account verwendet (deckt den bisherigen
 * Ein-Account-Fall ab, ohne bestehende Modul-Ausgaben anpassen zu müssen).
 *
 * Der Share-Link wird pro Account UND Pfad separat gecacht (rex_config) --
 * derselbe Pfad kann in unterschiedlichen Accounts existieren und braucht
 * dann auch unterschiedliche Cache-Einträge. Mit reset="1" wird der Cache
 * geleert und ein neuer Link erstellt.
 *
 * Anwendungsbeispiel im Modul-Output:
 *   <a href="REX_NEXTCLOUD_SHARE[path="/docs/flyer.pdf"]">Flyer herunterladen</a>
 */
class rex_var_nextcloud_share extends rex_var
{
    protected function getOutput(): string|false
    {
        $path = $this->getArg('path', '', true);

        if ('' === $path) {
            return false;
        }

        $expiry = $this->getArg('expiry', '', true);
        $reset  = $this->getArg('reset', '0', true);
        $connection = $this->getArg('connection', '', true);

        return 'rex_var_nextcloud_share::getShareUrl('
            . self::quote($path) . ', '
            . self::quote($expiry) . ', '
            . '(bool)' . self::quote($reset) . ', '
            . self::quote($connection)
            . ')';
    }

    /**
     * Gibt den Share-Link für einen Nextcloud-Pfad zurück.
     * Gecachte Links werden aus rex_config gelesen und nur bei Bedarf neu erstellt.
     *
     * @param string $path         Pfad relativ zum Root-Ordner des Accounts
     * @param string $expiry       Ablaufdatum (YYYY-MM-DD), leer = kein Ablauf
     * @param bool   $reset        true = vorhandenen Cache ignorieren, neuen Link erstellen
     * @param string $connectionId Id einer cloudconnect-Nextcloud-Verbindung, leer = erste aktive
     *
     * @return string Share-URL oder leerer String bei Fehler
     */
    public static function getShareUrl(string $path, string $expiry = '', bool $reset = false, string $connectionId = ''): string
    {
        if ('' === $path) {
            return '';
        }

        if ('1' !== (string) \rex_config::get('nextcloud', 'enable_sharing', '1')) {
            return '';
        }

        if ('' === $connectionId) {
            $firstKey = array_key_first(ConnectionStore::getActiveByType('nextcloud'));
            $connectionId = null !== $firstKey ? (string) $firstKey : '';
        }
        if ('' === $connectionId) {
            \rex_logger::factory()->log('error', 'REX_NEXTCLOUD_SHARE: kein Nextcloud-Account konfiguriert (siehe cloudconnect)', ['path' => $path]);
            return '';
        }

        $cacheKey = 'share_cache_' . $connectionId . '_' . md5($path);

        if (!$reset) {
            $cached = \rex_config::get('nextcloud', $cacheKey);
            if (is_string($cached) && '' !== $cached) {
                /** @var array{url: string, expires: string|null}|false $data */
                $data = json_decode($cached, true);
                if (is_array($data) && !empty($data['url'])) {
                    // Abgelaufene Links neu erstellen
                    if (!empty($data['expires']) && strtotime($data['expires']) < time()) {
                        $reset = true;
                    } else {
                        return $data['url'];
                    }
                }
            }
        }

        try {
            $api    = new NextCloud($connectionId);
            $result = $api->createShareLink($path, '' !== $expiry ? $expiry : null);

            if (empty($result['url'])) {
                return '';
            }

            // Im Cache speichern
            \rex_config::set('nextcloud', $cacheKey, json_encode([
                'url'     => $result['url'],
                'expires' => $result['expiration'] ?? ($expiry !== '' ? $expiry : null),
            ]));

            return $result['url'];
        } catch (\Exception $e) {
            \rex_logger::factory()->log('error', 'REX_NEXTCLOUD_SHARE: Share-Link konnte nicht erstellt werden', [
                'path'  => $path,
                'connection' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
