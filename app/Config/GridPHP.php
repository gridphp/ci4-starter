<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * GridPHP Configuration
 *
 * Reads from CI4's standard database.default config (.env / Database.php).
 * Falls back to the bundled SQLite sample database if not configured.
 */
class GridPHP extends BaseConfig
{
    /** Asset URL path relative to public root. */
    public string $assetPath = 'gridphp/assets';

    /** GridPHP driver type mapped from CI4 DBDriver. */
    private const DRIVER_MAP = [
        'MySQLi'  => 'mysqli',
        'Postgre' => 'postgres',
        'SQLSRV'  => 'mssqlnative',
        'OCI8'    => 'oci8',
        'SQLite3' => 'sqlite3',
    ];

    /**
     * Returns the db_conf array expected by the GridPHP jqgrid constructor.
     */
    public function dbconf(): array
    {
        $default  = config('Database')->default;
        $driver   = $default['DBDriver'] ?? 'MySQLi';
        $hostname = $default['hostname'] ?? '';
        $database = $default['database'] ?? '';

        // If not configured, fall back to the bundled SQLite sample database
        $configured = (! empty($hostname) && $hostname !== 'localhost' && ! empty($database))
                   || ($driver === 'SQLite3' && ! empty($database));

        if (! $configured) {
            return [
                'type'     => 'sqlite3',
                'server'   => ROOTPATH . 'vendor/gridphp/gridphp-community/demos/sample-db/database.db',
                'user'     => '',
                'password' => '',
                'database' => '',
            ];
        }

        return [
            'type'     => self::DRIVER_MAP[$driver] ?? strtolower($driver),
            'server'   => ($driver === 'SQLite3') ? $database : $hostname,
            'user'     => $default['username'] ?? '',
            'password' => $default['password'] ?? '',
            'database' => ($driver === 'SQLite3') ? '' : $database,
        ];
    }
}
