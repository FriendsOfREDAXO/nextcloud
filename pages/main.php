<?php
namespace FriendsOfRedaxo\NextCloud;

use rex_i18n; 

// Prüfe Konfiguration
if (!\rex_config::get('nextcloud', 'baseurl') || !\rex_config::get('nextcloud', 'username') || !\rex_config::get('nextcloud', 'password')) {
    echo \rex_view::warning(rex_i18n::msg('nextcloud_config_missing'));
    return;
}

// Medienpool Kategorien laden
$cats_sel = new \rex_media_category_select();
$cats_sel->setStyle('class="form-control"');
$cats_sel->setName('category_id');
$cats_sel->setId('rex-mediapool-category');
$cats_sel->setSize(1);
$cats_sel->setAttribute('class', 'form-control');

$user = \rex::getUser();
$canUploadFromMediapool = $user !== null && ($user->isAdmin() || $user->hasPerm('nextcloud[upload_mediapool]'));

$mediapoolUploadPanel = '';
if ($canUploadFromMediapool) {
    $medialistWidget = \rex_var_medialist::getWidget('nextcloud-mediapool-upload-list', 'nextcloud_mediapool_files', '', []);

    $mediapoolUploadPanel = '
        <div class="col-sm-8">
            <div class="panel panel-default">
                <header class="panel-heading">
                    <div class="panel-title">' . \rex_i18n::msg('nextcloud_upload_from_mediapool_title') . '</div>
                </header>
                <div class="panel-body">
                    <div class="row" style="display:flex; align-items:center; gap:10px;">
                        <div class="col-sm-8">
                            <p class="help-block" style="margin:0;">' . \rex_i18n::msg('nextcloud_upload_modal_notice') . '</p>
                        </div>
                        <div class="col-sm-4">
                            <button class="btn btn-primary btn-block" id="btnOpenMediapoolUploadModal" type="button">
                                <i class="rex-icon fa-list"></i> ' . \rex_i18n::msg('nextcloud_open_upload_modal') . '
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="nextcloud-mediapool-upload-modal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <h4 class="modal-title">' . \rex_i18n::msg('nextcloud_upload_modal_title') . '</h4>
                        </div>
                        <div class="modal-body">
                            ' . $medialistWidget . '
                            <p class="help-block" style="margin-top:10px; margin-bottom:0;">' . \rex_i18n::msg('nextcloud_upload_multiple_from_mediapool_notice') . '</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">' . \rex_i18n::msg('close') . '</button>
                            <button type="button" class="btn btn-primary" id="btnUploadMedialistToNextcloud">
                                <i class="rex-icon fa-cloud-upload"></i> ' . \rex_i18n::msg('nextcloud_upload_multiple_to_current_folder') . '
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
}

// Hauptcontainer
$content = '
<div class="nextcloud-container">
    <div class="row">
        <div class="col-sm-4">
            <div class="panel panel-default">
                <header class="panel-heading">
                    <div class="panel-title">' . \rex_i18n::msg('nextcloud_target_category') . '</div>
                </header>
                <div class="panel-body">
                    ' . $cats_sel->get() . '
                </div>
            </div>
        </div>
        ' . $mediapoolUploadPanel . '
    </div>
    
    <div class="panel panel-default">
        <header class="panel-heading">
            <div class="panel-title">
                <i class="rex-icon fa-cloud"></i> NextCloud
                <div class="pull-right btn-group">
                    <button class="btn btn-default btn-xs" id="btnRefresh">
                        <i class="rex-icon fa-refresh"></i> ' . \rex_i18n::msg('nextcloud_refresh') . '
                    </button>
                    <button class="btn btn-default btn-xs" id="btnHome">
                        <i class="rex-icon fa-home"></i>
                    </button>
                </div>
            </div>
        </header>
        <div class="panel-body">
            <div id="pathBreadcrumb"></div>
            <div class="nextcloud-search" style="margin: 12px 0;">
                <div class="input-group input-group-sm">
                    <span class="input-group-addon"><i class="rex-icon fa-search"></i></span>
                    <input type="text" id="nextcloud-search-input" class="form-control" placeholder="' . \rex_i18n::msg('nextcloud_search_placeholder') . '">
                    <span class="input-group-btn">
                        <button class="btn btn-default" type="button" id="nextcloud-search-clear">' . \rex_i18n::msg('nextcloud_search_clear') . '</button>
                    </span>
                </div>
                <div id="nextcloud-search-count" class="text-muted" style="font-size: 12px; margin-top: 6px;"></div>
            </div>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 30px">
                            <label class="sr-only">Auswählen</label>
                        </th>
                        <th style="width: 40px">
                            <label class="sr-only">Typ</label>
                        </th>
                        <th>' . \rex_i18n::msg('nextcloud_filename') . '</th>
                        <th style="width: 150px">' . \rex_i18n::msg('nextcloud_filesize') . '</th>
                        <th style="width: 150px">' . \rex_i18n::msg('nextcloud_modified') . '</th>
                        <th style="width: 190px">
                            <label class="sr-only">Aktionen</label>
                        </th>
                    </tr>
                </thead>
                <tbody id="fileList"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
.nextcloud-container .progress {
    margin: 20px 0;
}
.file-select {
    cursor: pointer;
}
.nextcloud-container .table {
    table-layout: fixed;
}
.nextcloud-actions-cell {
    width: 190px;
    white-space: nowrap;
    text-align: right;
}
.nextcloud-actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
}
</style>';

// Fragment erstellen und ausgeben
$fragment = new \rex_fragment();
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
