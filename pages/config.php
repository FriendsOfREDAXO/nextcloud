<?php
namespace FriendsOfRedaxo\NextCloud;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;

$content = '';

if (\rex_post('config-submit', 'boolean')) {
    $this->setConfig(\rex_post('config', [
        ['tags_field', 'string'],
        ['enable_sharing', 'string'],
    ]));

    echo \rex_view::success(\rex_i18n::msg('nextcloud_config_saved'));
}

// --- Accounts (schreibgeschuetzt, verwaltet in cloudconnect) ---
$content .= '<div class="rex-form">';
$content .= '<p class="help-block">' . \rex_i18n::msg('nextcloud_accounts_notice', \rex_url::backendPage('cloudconnect/settings')) . '</p>';

$connections = ConnectionStore::getByType('nextcloud');
if ([] === $connections) {
    $content .= \rex_view::info(\rex_i18n::msg('nextcloud_accounts_none'));
} else {
    $rows = '';
    foreach ($connections as $connection) {
        $label = \rex_escape((string) ($connection['label'] ?? ''));
        $isActive = (bool) ($connection['active'] ?? false);
        $isComplete = ConnectionStore::isComplete($connection);

        $statusBadge = $isActive
            ? '<span class="label label-success">' . \rex_i18n::msg('nextcloud_accounts_active') . '</span>'
            : '<span class="label label-default">' . \rex_i18n::msg('nextcloud_accounts_inactive') . '</span>';
        if (!$isComplete) {
            $statusBadge .= ' <span class="label label-warning">' . \rex_i18n::msg('nextcloud_accounts_incomplete') . '</span>';
        }

        $rows .= '<tr><td>' . $label . '</td><td>' . $statusBadge . '</td></tr>';
    }

    $content .= '<table class="table table-hover"><thead><tr>'
        . '<th>' . \rex_i18n::msg('nextcloud_accounts_label') . '</th>'
        . '<th>' . \rex_i18n::msg('nextcloud_accounts_status') . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';
}

$content .= '<a class="btn btn-default" href="' . \rex_url::backendPage('cloudconnect/settings') . '">'
    . '<i class="rex-icon fa-cloud"></i> ' . \rex_i18n::msg('nextcloud_accounts_manage_link')
    . '</a>';
$content .= '</div>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', \rex_i18n::msg('nextcloud_accounts_title'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

// --- Formular: Share-Links + Metadaten-Mapping (accountuebergreifend) ---
$content = '<div class="rex-form">';
$content .= '<form action="' . \rex_url::currentBackendPage() . '" method="post">';

// --- Share-Links ---
$content .= '<h3>' . \rex_i18n::msg('nextcloud_sharing_title') . '</h3>';

$formElements = [];
$n = [];
$n['label'] = '<label for="nextcloud-enable-sharing">' . \rex_i18n::msg('nextcloud_enable_sharing') . '</label>';
$sharingEnabled = $this->getConfig('enable_sharing', '1');
$n['field'] = '<select id="nextcloud-enable-sharing" name="config[enable_sharing]" class="form-control selectpicker">';
$n['field'] .= '<option value="1"' . ('1' === $sharingEnabled ? ' selected' : '') . '>' . \rex_i18n::msg('nextcloud_enable_sharing_yes') . '</option>';
$n['field'] .= '<option value="0"' . ('0' === $sharingEnabled ? ' selected' : '') . '>' . \rex_i18n::msg('nextcloud_enable_sharing_no') . '</option>';
$n['field'] .= '</select>';
$n['notice'] = \rex_i18n::msg('nextcloud_enable_sharing_notice');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/form.php');

// --- Metadaten-Mapping ---
$content .= '<hr>';
$content .= '<h3>' . \rex_i18n::msg('nextcloud_meta_mapping_title') . '</h3>';
$content .= '<p class="help-block">' . \rex_i18n::msg('nextcloud_meta_mapping_notice') . '</p>';

$formElements = [];
$n = [];
$n['label'] = '<label for="nextcloud-tags-field">' . \rex_i18n::msg('nextcloud_tags_field') . '</label>';
$n['field'] = '<input type="text" id="nextcloud-tags-field" name="config[tags_field]" value="' . \rex_escape($this->getConfig('tags_field', '')) . '" class="form-control" placeholder="z.B. med_description"/>';
$n['notice'] = \rex_i18n::msg('nextcloud_tags_field_notice');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/form.php');

// REX_VAR Hinweis – nur anzeigen wenn Sharing aktiviert
if ('1' === $this->getConfig('enable_sharing', '1')) {
    $content .= '<div class="alert alert-info" style="margin-top:15px;">';
    $content .= '<strong>' . \rex_i18n::msg('nextcloud_var_hint_title') . '</strong><br>';
    $content .= \rex_i18n::msg('nextcloud_var_hint_text');
    $content .= '<pre style="margin:8px 0 0; font-size:12px;">REX_NEXTCLOUD_SHARE[path=&quot;/Ordner/datei.pdf&quot;]' . "\n"
        . 'REX_NEXTCLOUD_SHARE[path=&quot;/Ordner/datei.pdf&quot; expiry=&quot;2027-12-31&quot;]' . "\n"
        . 'REX_NEXTCLOUD_SHARE[path=&quot;/Ordner/datei.pdf&quot; connection=&quot;a1b2c3d4&quot;]</pre>';
    $content .= '</div>';
}

// Submit
$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="config-submit" value="1">' . $this->i18n('save') . '</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/submit.php');

$content .= '</form>';
$content .= '</div>';

$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', \rex_i18n::msg('nextcloud_configuration'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
