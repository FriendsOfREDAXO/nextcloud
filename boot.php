<?php
namespace FriendsOfRedaxo\NextCloud;

// Feingranulare Rechte -- package.yml's "permissions:"-Block (frueher hier
// als einzige "Registrierung" gedacht) ist KEIN von REDAXO-Core verarbeiteter
// YAML-Schluessel (rex_package/rex_addon kennen ihn nicht), reine tote YAML.
// Nur rex_perm::register() traegt ein Recht tatsaechlich in die
// Rollenverwaltung ein -- ohne das war z.B. "nextcloud[mediaplace_browse]"
// zwar ueberall im Code per hasPerm() abgefragt, aber fuer keine Rolle
// grantbar (Bugreport: Recht tauchte in der Rollenverwaltung gar nicht auf).
// "nextcloud[]" braucht hier keinen eigenen Aufruf -- das wird bereits als
// page-level perm (siehe package.yml) automatisch registriert.
\rex_perm::register('nextcloud[upload_mediapool]', \rex_i18n::msg('nextcloud_perm_upload_mediapool'));
\rex_perm::register('nextcloud[delete]', \rex_i18n::msg('nextcloud_perm_delete'));
\rex_perm::register('nextcloud[download]', \rex_i18n::msg('nextcloud_perm_download'));

if (\rex_addon::get('cronjob')->isAvailable()) {
    \rex_cronjob_manager::registerType(rex_cronjob_redaxo_backup::class);
}

// Seit 2.0.0 KEINE eigene MediaPlace-Cloud-Provider-Integration mehr --
// nextcloud ist jetzt Client von cloudconnect (siehe lib/nextcloud.php),
// und cloudconnect registriert bereits einen eigenen Nextcloud-Provider bei
// MediaPlace (lib/Nextcloud/NextcloudProvider.php). Zwei Registrierungen
// fuer dieselbe(n) Verbindung(en) erzeugten vorher einen sichtbaren
// Doppel-Eintrag in MediaPlace's Cloud-Speicher-Sidebar (Bugreport).

// Nur im Backend ausführen
if (\rex::isBackend() && \rex::getUser()) {

    // Assets nur auf der NextCloud-Seite einbinden
    if (\rex_be_controller::getCurrentPage() == 'nextcloud/main') {
        $user = \rex::getUser();
        $addon = \rex_addon::get('nextcloud');

        foreach (['nextcloud.css', 'nextcloud.js'] as $assetFileName) {
            $assetUrl = $this->getAssetsUrl($assetFileName);
            $assetFile = \rex_path::addon('nextcloud', 'assets/' . $assetFileName);
            $assetVersion = (string) $addon->getVersion();
            if (is_file($assetFile)) {
                $mtime = @filemtime($assetFile);
                if (false !== $mtime) {
                    $assetVersion .= '-' . (string) $mtime;
                }
            }
            $assetUrl .= '?v=' . rawurlencode($assetVersion);
            if (str_ends_with($assetFileName, '.css')) {
                \rex_view::addCssFile($assetUrl);
            } else {
                \rex_view::addJsFile($assetUrl);
            }
        }

        // Verfuegbare Nextcloud-Accounts (Mehrkonten-Umschalter, siehe
        // assets/nextcloud.js) -- live aus cloudconnect gelesen, keine
        // eigene Verbindungsverwaltung mehr in diesem Addon.
        $connections = [];
        foreach (\FriendsOfRedaxo\CloudConnect\ConnectionStore::getActiveByType('nextcloud') as $id => $connection) {
            if (!\FriendsOfRedaxo\CloudConnect\ConnectionStore::isComplete($connection)) {
                continue;
            }
            $connections[] = ['id' => $id, 'label' => (string) ($connection['label'] ?? $id)];
        }

        \rex_view::setJsProperty('nextcloudConnections', $connections);
        \rex_view::setJsProperty('nextcloudCloudconnectSettingsUrl', \rex_url::backendPage('cloudconnect/settings'));
        \rex_view::setJsProperty('nextcloudSharingEnabled', '1' === (string) \rex_config::get('nextcloud', 'enable_sharing', '1'));
        \rex_view::setJsProperty('nextcloudUploadFromMediapoolEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[upload_mediapool]')));
        \rex_view::setJsProperty('nextcloudDeleteEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[delete]')));
        \rex_view::setJsProperty('nextcloudDownloadEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[download]')));
        \rex_view::setJsProperty('nextcloudSearchNoResults', \rex_i18n::msg('nextcloud_search_no_results'));
        \rex_view::setJsProperty('nextcloudSearchResultSuffix', \rex_i18n::msg('nextcloud_search_results_suffix'));
    }
}
