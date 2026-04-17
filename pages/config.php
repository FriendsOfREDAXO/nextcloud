<?php
namespace FriendsOfRedaxo\NextCloud;

$content = '';

if (\rex_post('config-submit', 'boolean')) {
    $this->setConfig(\rex_post('config', [
        ['baseurl', 'string'],
        ['username', 'string'],
        ['password', 'string'],
        ['rootfolder', 'string'],
        ['tags_field', 'string'],
        ['enable_sharing', 'boolean'],
    ]));
    
    echo \rex_view::success(\rex_i18n::msg('nextcloud_config_saved'));
}

$content .= '<div class="rex-form">';
$content .= '<form action="' . \rex_url::currentBackendPage() . '" method="post">';

$formElements = [];

// Basis-URL
$n = [];
$n['label'] = '<label for="nextcloud-baseurl">' . \rex_i18n::msg('nextcloud_baseurl') . '</label>';
$n['field'] = '<input type="url" id="nextcloud-baseurl" name="config[baseurl]" value="' . $this->getConfig('baseurl') . '" class="form-control"/>';
$formElements[] = $n;

// Benutzername
$n = [];
$n['label'] = '<label for="nextcloud-username">' . \rex_i18n::msg('nextcloud_username') . '</label>';
$n['field'] = '<input type="text" id="nextcloud-username" name="config[username]" value="' . $this->getConfig('username') . '" class="form-control"/>';
$formElements[] = $n;

// App-Passwort
$n = [];
$n['label'] = '<label for="nextcloud-password">' . \rex_i18n::msg('nextcloud_password') . '</label>';
$n['field'] = '<input type="password" id="nextcloud-password" name="config[password]" value="' . $this->getConfig('password') . '" class="form-control"/>';
$n['notice'] = \rex_i18n::msg('nextcloud_password_notice');
$formElements[] = $n;

// Root-Ordner
$n = [];
$n['label'] = '<label for="nextcloud-rootfolder">' . \rex_i18n::msg('nextcloud_rootfolder') . '</label>';
$n['field'] = '<input type="text" id="nextcloud-rootfolder" name="config[rootfolder]" value="' . \rex_escape($this->getConfig('rootfolder', '')) . '" class="form-control" placeholder="/"/>';
$n['notice'] = \rex_i18n::msg('nextcloud_rootfolder_notice');
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/form.php');

// --- Share-Links ---
$content .= '<hr>';
$content .= '<h3>' . \rex_i18n::msg('nextcloud_sharing_title') . '</h3>';

$formElements = [];
$n = [];
$n['label'] = '<label for="nextcloud-enable-sharing">' . \rex_i18n::msg('nextcloud_enable_sharing') . '</label>';
$n['field'] = '<input type="checkbox" id="nextcloud-enable-sharing" name="config[enable_sharing]" value="1"' . ($this->getConfig('enable_sharing', true) ? ' checked' : '') . '>';
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
if ($this->getConfig('enable_sharing', true)) {
$content .= '<div class="alert alert-info" style="margin-top:15px;">';
$content .= '<strong>' . \rex_i18n::msg('nextcloud_var_hint_title') . '</strong><br>';
$content .= \rex_i18n::msg('nextcloud_var_hint_text');
$content .= '<pre style="margin:8px 0 0; font-size:12px;">REX_NEXTCLOUD_SHARE[path=&quot;/Ordner/datei.pdf&quot;]' . "\n" . 'REX_NEXTCLOUD_SHARE[path=&quot;/Ordner/datei.pdf&quot; expiry=&quot;2027-12-31&quot;]</pre>';
$content .= '</div>';
}

// Submit
$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="config-submit" value="1">' . \rex_i18n::msg('save') . '</button>';
$formElements[] = $n;

$fragment = new \rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/submit.php');

$content .= '</form>';
$content .= '</div>';

// Ausgabe Fragment
$fragment = new \rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', \rex_i18n::msg('nextcloud_configuration'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
