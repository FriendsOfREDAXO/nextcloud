<?php
namespace Klxm\Nextcloud;

use rex_addon;
use rex_be_controller;
use rex_view;

$addon = rex_addon::get('nextcloud');
echo rex_view::title($addon->i18n('title'));
rex_be_controller::includeCurrentPageSubPath();
