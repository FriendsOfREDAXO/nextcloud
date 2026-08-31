<?php
namespace FriendsOfRedaxo\NextCloud;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;

// "gibt es mindestens einen aktiven Nextcloud-Account in cloudconnect?"
// ersetzt die alte, addon-eigene rex_config('nextcloud','baseurl'...)-Pruefung
// (siehe Teil B im Umbau-Plan) -- dieses Addon speichert seit 2.0.0 selbst
// keine Zugangsdaten mehr.
$connections = [];
foreach (ConnectionStore::getActiveByType('nextcloud') as $id => $connection) {
    if (!ConnectionStore::isComplete($connection)) {
        continue;
    }
    $connections[$id] = (string) ($connection['label'] ?? $id);
}

if ([] === $connections) {
    $content = '<div class="nc-notice nc-notice-warning">'
        . \rex_i18n::msg('nextcloud_no_account_configured')
        . ' <a href="' . \rex_url::backendPage('cloudconnect/settings') . '">'
        . \rex_i18n::msg('nextcloud_accounts_manage_link') . '</a>'
        . '</div>';

    $fragment = new \rex_fragment();
    $fragment->setVar('body', '<div id="nc-app"><div class="nc-card">' . $content . '</div></div>', false);
    echo $fragment->parse('core/page/section.php');
    return;
}

// Medienpool-Kategorien fuer den Import-Zielordner
$catsSel = new \rex_media_category_select();
$catsSel->setStyle('class="form-control"');
$catsSel->setName('category_id');
$catsSel->setId('rex-mediapool-category');
$catsSel->setSize(1);
$catsSel->setAttribute('class', 'form-control');

$user = \rex::getUser();
$canUploadFromMediapool = null !== $user && ($user->isAdmin() || $user->hasPerm('nextcloud[upload_mediapool]'));

$uploadModalTrigger = '';
$uploadModal = '';
if ($canUploadFromMediapool) {
    // Der Medienliste-Widget-HTML-Block kommt server-seitig gerendert von
    // rex_var_medialist (inkl. eigenem Such-/Auswahl-Dialog-JS) -- deshalb
    // bleibt dieses Modal, anders als Vorschau/Share (rein clientseitig in
    // nextcloud.js gebaut), statisch in main.php, nur strukturell im
    // MediaPlace-Panel-Look neu gestaltet.
    $medialistWidget = \rex_var_medialist::getWidget('nextcloud-mediapool-upload-list', 'nextcloud_mediapool_files', '', []);

    $uploadModalTrigger = '<button class="nc-btn" id="btnOpenMediapoolUploadModal" type="button">'
        . '<i class="rex-icon fa-cloud-upload"></i> ' . \rex_i18n::msg('nextcloud_open_upload_modal')
        . '</button>';

    $uploadModal = '
    <div class="modal fade nc-modal" id="nextcloud-mediapool-upload-modal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title"><i class="rex-icon fa-cloud-upload"></i> ' . \rex_i18n::msg('nextcloud_upload_modal_title') . '</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="nc-hint" style="margin-bottom:12px;">' . \rex_i18n::msg('nextcloud_upload_modal_notice') . '</p>
                    ' . $medialistWidget . '
                    <p class="nc-hint" style="margin-top:10px;">' . \rex_i18n::msg('nextcloud_upload_multiple_from_mediapool_notice') . '</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="nc-btn" data-dismiss="modal">' . \rex_i18n::msg('close') . '</button>
                    <button type="button" class="nc-btn nc-btn-primary" id="btnUploadMedialistToNextcloud">
                        <i class="rex-icon fa-cloud-upload"></i> ' . \rex_i18n::msg('nextcloud_upload_multiple_to_current_folder') . '
                    </button>
                </div>
            </div>
        </div>
    </div>';
}

$content = '
<div id="nc-app">
    <div class="nc-card">
        <div class="nc-accounts" id="nc-accounts"></div>

        <div class="nc-header">
            <div class="nc-header-title"><i class="rex-icon fa-cloud"></i> ' . \rex_i18n::msg('nextcloud') . '</div>
            <div class="nc-header-actions" id="nc-toolbar-actions">
                ' . $uploadModalTrigger . '
                <button class="nc-btn" id="btnRefresh" type="button" title="' . \rex_i18n::msg('nextcloud_refresh') . '">
                    <i class="rex-icon fa-refresh"></i> ' . \rex_i18n::msg('nextcloud_refresh') . '
                </button>
                <button class="nc-btn" id="btnHome" type="button" title="Home">
                    <i class="rex-icon fa-home"></i>
                </button>
            </div>
        </div>

        <div class="nc-toolbar">
            <div class="nc-toolbar-row">
                <div class="nc-category-select">' . $catsSel->get() . '</div>
                <div class="nc-breadcrumb" id="nc-breadcrumb"></div>
            </div>
            <div class="nc-toolbar-row">
                <div class="nc-search">
                    <div class="nc-search-input-wrap">
                        <i class="rex-icon fa-search"></i>
                        <input type="text" id="nextcloud-search-input" class="nc-search-input" placeholder="' . \rex_i18n::msg('nextcloud_search_placeholder') . '">
                    </div>
                    <button class="nc-btn" type="button" id="nextcloud-search-clear">' . \rex_i18n::msg('nextcloud_search_clear') . '</button>
                    <span id="nextcloud-search-count" class="nc-search-count"></span>
                </div>
            </div>
        </div>

        <div class="nc-list" id="fileList"></div>
    </div>
</div>
' . $uploadModal;

$fragment = new \rex_fragment();
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
