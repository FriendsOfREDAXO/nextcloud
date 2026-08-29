<?php
namespace FriendsOfRedaxo\NextCloud;


if (\rex_addon::get('cronjob')->isAvailable()) {
    \rex_cronjob_manager::registerType(\rex_cronjob_redaxo_backup::class);
}

// MediaPlace-Cloud-Provider-Integration (siehe lib/MediaplaceProvider.php):
// klinkt sich als eigener Baum in die MediaPlace-Sidebar ein (Browsen,
// Suchen, Import in den lokalen Medienpool -- kein Sync). Eigenes,
// dediziertes Recht statt des breiteren nextcloud[] (das zusaetzlich
// Upload/Loeschen/Download auf der eigenstaendigen Nextcloud-Seite
// freischaltet) -- ein User kann so gezielt nur "Browsen & Importieren via
// MediaPlace" bekommen. Unconditional registrierbar (wie bei mediaplace's
// eigenen Erweiterungspunkten): wird ohnehin nur von MediaPlace's eigenem,
// backend-gated Code gefeuert.
if (\rex_addon::get('mediaplace')->isAvailable()) {
    \rex_extension::register('MEDIAPLACE_STORAGE_PROVIDERS', static function (\rex_extension_point $ep) {
        $providers = $ep->getSubject();
        $providers['nextcloud'] = [
            'label' => \rex_i18n::msg('nextcloud_mediaplace_provider_label'),
            'icon' => 'fa-solid fa-cloud',
            'perm' => 'nextcloud[mediaplace_browse]',
            'class' => MediaplaceProvider::class,
        ];

        return $providers;
    });
}

// Nur im Backend ausführen
if (\rex::isBackend() && \rex::getUser()) {

    // Assets nur auf der NextCloud-Seite einbinden
    if (\rex_be_controller::getCurrentPage() == 'nextcloud/main') {
        $user = \rex::getUser();
        $addon = \rex_addon::get('nextcloud');
        $assetUrl = $this->getAssetsUrl('nextcloud.js');
        $assetFile = \rex_path::addon('nextcloud', 'assets/nextcloud.js');
        $assetVersion = (string) $addon->getVersion();
        if (is_file($assetFile)) {
            $mtime = @filemtime($assetFile);
            if (false !== $mtime) {
                $assetVersion .= '-' . (string) $mtime;
            }
        }
        $assetUrl .= '?v=' . rawurlencode($assetVersion);
        \rex_view::addJsFile($assetUrl);
        \rex_view::setJsProperty('nextcloudSharingEnabled', '1' === (string) \rex_config::get('nextcloud', 'enable_sharing', '1'));
        \rex_view::setJsProperty('nextcloudUploadFromMediapoolEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[upload_mediapool]')));
        \rex_view::setJsProperty('nextcloudDeleteEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[delete]')));
        \rex_view::setJsProperty('nextcloudDownloadEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[download]')));
        \rex_view::setJsProperty('nextcloudSearchNoResults', \rex_i18n::msg('nextcloud_search_no_results'));
        \rex_view::setJsProperty('nextcloudSearchResultSuffix', \rex_i18n::msg('nextcloud_search_results_suffix'));
    }
}
