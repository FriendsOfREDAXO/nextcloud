<?php

/**
 * Update script for nextcloud addon
 * 
 * Migrates cronjob entries from old class name to namespaced class name
 */

// Update cronjob class references from global namespace to namespaced version
$sql = rex_sql::factory();
$sql->setQuery("
    UPDATE " . rex::getTable('cronjob') . "
    SET type = 'FriendsOfRedaxo\\\\NextCloud\\\\rex_cronjob_redaxo_backup'
    WHERE type = 'rex_cronjob_redaxo_backup'
");

// Log the migration
if ($sql->getRows() > 0) {
    rex_logger::factory()->log('info', 'NextCloud Addon: Migrated ' . $sql->getRows() . ' cronjob entries to namespaced class');
}
