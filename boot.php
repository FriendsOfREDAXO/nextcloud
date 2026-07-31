<?php
namespace FriendsOfRedaxo\NextCloud;


if (\rex_addon::get('cronjob')->isAvailable()) {
    \rex_cronjob_manager::registerType(\rex_cronjob_redaxo_backup::class);
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
