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
                            <label class="sr-only">' . \rex_i18n::msg('nextcloud_js_select') . '</label>
                        </th>
                        <th style="width: 40px">
                            <label class="sr-only">' . \rex_i18n::msg('nextcloud_js_type') . '</label>
                        </th>
                        <th>' . \rex_i18n::msg('nextcloud_filename') . '</th>
                        <th style="width: 150px">' . \rex_i18n::msg('nextcloud_filesize') . '</th>
                        <th style="width: 150px">' . \rex_i18n::msg('nextcloud_modified') . '</th>
                        <th style="width: 100px">
                            <label class="sr-only">' . \rex_i18n::msg('nextcloud_js_actions') . '</label>
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

// Add translations for JavaScript
echo '<script>
window.nextcloudTranslations = {
    error: "' . \rex_i18n::msg('nextcloud_js_error') . '",
    importingFiles: "' . \rex_i18n::msg('nextcloud_js_importing_files') . '",
    importingFile: "' . \rex_i18n::msg('nextcloud_js_importing_file') . '",
    unknownError: "' . \rex_i18n::msg('nextcloud_js_unknown_error') . '",
    importCompleted: "' . \rex_i18n::msg('nextcloud_js_import_completed') . '",
    filesImportedSuccess: "' . \rex_i18n::msg('nextcloud_js_files_imported_success') . '",
    errors: "' . \rex_i18n::msg('nextcloud_js_errors') . '",
    allFilesImported: "' . \rex_i18n::msg('nextcloud_js_all_files_imported') . '",
    close: "' . \rex_i18n::msg('nextcloud_js_close') . '",
    import: "' . \rex_i18n::msg('nextcloud_js_import') . '",
    fileImportedSuccess: "' . \rex_i18n::msg('nextcloud_js_file_imported_success') . '",
    importError: "' . \rex_i18n::msg('nextcloud_js_import_error') . '",
    importCount: "' . \rex_i18n::msg('nextcloud_js_import_count') . '"
};
</script>';
