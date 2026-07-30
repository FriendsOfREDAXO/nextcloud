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
        \rex_view::addJsFile($this->getAssetsUrl('nextcloud.js'));
        \rex_view::setJsProperty('nextcloudSharingEnabled', '1' === (string) \rex_config::get('nextcloud', 'enable_sharing', '1'));
        \rex_view::setJsProperty('nextcloudUploadFromMediapoolEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[upload_mediapool]')));
        \rex_view::setJsProperty('nextcloudDeleteEnabled', $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[delete]')));
    }
}
