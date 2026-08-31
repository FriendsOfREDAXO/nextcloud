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
                    <button type="button" class="nc-btn" data-dismiss="modal">' . $this->i18n('close') . '</button>
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

        <div class="nc-toolbar">
            <div class="nc-toolbar-row nc-toolbar-row-main">
                <div class="nc-field nc-field-category" title="' . \rex_i18n::msg('nextcloud_target_category') . '">
                    <label for="rex-mediapool-category">' . \rex_i18n::msg('nextcloud_target_category') . '</label>
                    ' . $catsSel->get() . '
                </div>
                <div class="nc-field nc-field-search">
                    <input type="text" id="nextcloud-search-input" class="nc-search-input" placeholder=" ">
                    <label for="nextcloud-search-input">' . \rex_i18n::msg('nextcloud_search_placeholder_label') . '</label>
                    <button type="button" class="nc-field-clear" id="nextcloud-search-clear" title="' . \rex_i18n::msg('nextcloud_search_clear') . '"><i class="rex-icon fa-times"></i></button>
                    <span id="nextcloud-search-count" class="nc-search-count"></span>
                </div>
                <div class="nc-toolbar-actions" id="nc-toolbar-actions">
                    ' . $uploadModalTrigger . '
                    <button class="nc-btn nc-btn-icon" id="btnRefresh" type="button" title="' . \rex_i18n::msg('nextcloud_refresh') . '">
                        <i class="rex-icon fa-refresh"></i>
                    </button>
                </div>
                <div class="nc-view-toggle-wrap" id="nc-view-toggle">
                    <button type="button" class="nc-view-toggle-btn" title="' . \rex_i18n::msg('nextcloud_view_mode') . '"><i class="rex-icon fa-list"></i></button>
                    <div class="nc-view-toggle-menu">
                        <button type="button" class="nc-view-btn nc-view-active" data-view="list"><i class="rex-icon fa-list"></i> ' . \rex_i18n::msg('nextcloud_view_list') . '</button>
                        <button type="button" class="nc-view-btn" data-view="masonry"><i class="rex-icon fa-th-large"></i> ' . \rex_i18n::msg('nextcloud_view_masonry') . '</button>
                    </div>
                </div>
            </div>
            <div class="nc-toolbar-row nc-toolbar-row-breadcrumb">
                <div class="nc-breadcrumb" id="nc-breadcrumb"></div>
            </div>
        </div>

        <div class="nc-list" id="fileList"></div>
    </div>
</div>
' . $uploadModal;

$fragment = new \rex_fragment();
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
