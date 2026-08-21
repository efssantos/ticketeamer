<?php
/**
 * GLPI Ticketeamer
 *
 * @license GPL-3.0-or-later
 */

define('PLUGIN_TICKETEAMER_VERSION', '1.0.0');
define('PLUGIN_TICKETEAMER_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_TICKETEAMER_MAX_GLPI_VERSION', '11.99.99');

define('PLUGIN_TICKETEAMER_NAMESPACE', 'GlpiPlugin\\Ticketeamer');

define('PLUGIN_TICKETEAMER_TABLE', 'glpi_plugin_ticketeamer_queue');

// Lightweight PSR-4 autoloader so the plugin works immediately after extraction.
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Ticketeamer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

function plugin_init_ticketeamer(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['item_add']['ticketeamer'] = [
        'Ticket' => [
            \GlpiPlugin\Ticketeamer\Hook\TicketHook::class,
            'onTicketAdded',
        ],
    ];

    $PLUGIN_HOOKS['config_page']['ticketeamer'] = 'front/config.form.php';

    $PLUGIN_HOOKS['csrf_compliant']['ticketeamer'] = true;
}

function plugin_ticketeamer_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_TICKETEAMER_MIN_GLPI_VERSION, '<')
        || version_compare(GLPI_VERSION, PLUGIN_TICKETEAMER_MAX_GLPI_VERSION, '>=')) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            Plugin::messageIncompatible('core', PLUGIN_TICKETEAMER_MIN_GLPI_VERSION, PLUGIN_TICKETEAMER_MAX_GLPI_VERSION);
        }
        return false;
    }

    if (!extension_loaded('curl')) {
        if (method_exists('Plugin', 'messageMissingRequirement')) {
            Plugin::messageMissingRequirement('PHP cURL extension');
        }
        return false;
    }

    if (!extension_loaded('openssl')) {
        if (method_exists('Plugin', 'messageMissingRequirement')) {
            Plugin::messageMissingRequirement('PHP OpenSSL extension');
        }
        return false;
    }

    return true;
}

function plugin_ticketeamer_check_config($verbose = false): bool
{
    $config = \GlpiPlugin\Ticketeamer\Config::all();
    $configured = !empty($config['tenant_id'])
        && !empty($config['client_id'])
        && !empty($config['refresh_token'])
        && !empty(getenv('GLPI_TEAMS_BRIDGE_CLIENT_SECRET'))
        && !empty(getenv('GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY'));

    if (!$configured && $verbose) {
        echo __s('Microsoft Ticketeamer is not configured.', 'ticketeamer');
    }

    return true;
}

function plugin_version_ticketeamer(): array
{
    return [
        'name'           => 'Ticketeamer',
        'version'        => PLUGIN_TICKETEAMER_VERSION,
        'author'         => 'Internal Infrastructure Team',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/',
        'minGlpiVersion' => PLUGIN_TICKETEAMER_MIN_GLPI_VERSION,
        'maxGlpiVersion' => PLUGIN_TICKETEAMER_MAX_GLPI_VERSION,
    ];
}
