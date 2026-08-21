<?php
namespace GlpiPlugin\Ticketeamer;

final class Config
{
    public const CONTEXT = 'plugin:Ticketeamer';

    public static function defaults(): array
    {
        return [
            'tenant_id' => '',
            'client_id' => '',
            'redirect_uri' => '',
            'refresh_token' => '',
            'enabled' => 1,
            'notify_requester' => 0,
            'message_prefix' => 'Novo chamado GLPI',
            'retry_limit' => 5,
        ];
    }

    public static function initializeDefaults(): void
    {
        $current = \Config::getConfigurationValues(self::CONTEXT);
        $values = array_diff_key(self::defaults(), $current);
        if ($values !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $values);
        }
    }

    public static function all(): array
    {
        self::initializeDefaults();
        return array_merge(self::defaults(), \Config::getConfigurationValues(self::CONTEXT));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $config = self::all();
        return $config[$key] ?? $default;
    }

    public static function save(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
    }
}
