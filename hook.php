<?php
/**
 * GLPI Ticketeamer installation lifecycle.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Ticketeamer\Config;
use GlpiPlugin\Ticketeamer\Queue;
use GlpiPlugin\Ticketeamer\QueueTask;

function plugin_ticketeamer_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_TICKETEAMER_VERSION);
    $table = PLUGIN_TICKETEAMER_TABLE;

    if (!$DB->tableExists($table)) {
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $pk = DBConnection::getDefaultPrimaryKeySignOption();

        // Initial DDL is intentionally isolated to installation. Runtime queries use GLPI's DB abstraction.
        $query = "CREATE TABLE `$table` (
            `id` int $pk NOT NULL AUTO_INCREMENT,
            `tickets_id` int $pk NOT NULL DEFAULT 0,
            `users_id` int $pk NOT NULL DEFAULT 0,
            `recipient_email` varchar(255) COLLATE $collation NOT NULL,
            `status` varchar(20) COLLATE $collation NOT NULL DEFAULT 'pending',
            `attempts` int NOT NULL DEFAULT 0,
            `last_error` text COLLATE $collation NULL,
            `sent_at` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `recipient_email` (`recipient_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC;";

        if (!$DB->doQuery($query)) {
            return false;
        }
    }

    Config::initializeDefaults();

    CronTask::register(
        QueueTask::class,
        'Process',
        MINUTE_TIMESTAMP,
        [
            'comment' => 'Process pending Microsoft Teams notifications.',
            'mode' => CronTask::MODE_EXTERNAL,
            'param' => 25,
        ]
    );

    return true;
}

function plugin_ticketeamer_uninstall(): bool
{
    global $DB;

    $config = new Config();
    $values = array_keys(Config::all());
    $config->deleteConfigurationValues('plugin:Ticketeamer', $values);

    if ($DB->tableExists(PLUGIN_TICKETEAMER_TABLE)) {
        $DB->dropTable(PLUGIN_TICKETEAMER_TABLE);
    }

    return true;
}
