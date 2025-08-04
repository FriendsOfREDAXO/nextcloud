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
                        <th style="width: 100px">
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
</style>';

// Fragment erstellen und ausgeben
$fragment = new \rex_fragment();
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
