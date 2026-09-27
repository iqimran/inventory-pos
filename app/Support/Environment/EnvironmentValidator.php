<?php

namespace App\Support\Environment;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Validates that the runtime configuration is safe to run the application.
 */
class EnvironmentValidator
{
    public const MIN_MYSQL_VERSION = '8.0.0';

    /** Environments in which a non-MySQL database (e.g. in-memory SQLite) is acceptable. */
    private const NON_MYSQL_ENVIRONMENTS = ['testing'];

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(bool $checkDatabase = true): array
    {
        $errors = [];
        $warnings = [];
        $environment = (string) $this->config->get('app.env');
        $production = $environment === 'production';

        if (blank($this->config->get('app.key'))) {
            $errors[] = 'APP_KEY is not set. Run `php artisan key:generate`.';
        }

        if (blank($this->config->get('app.url'))) {
            $errors[] = 'APP_URL is not set.';
        }

        if ($production && $this->config->get('app.debug')) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }

        if ($production && ! str_starts_with((string) $this->config->get('app.url'), 'https://')) {
            $warnings[] = 'APP_URL should use https:// in production.';
        }

        $connection = (string) $this->config->get('database.default');
        $driver = (string) $this->config->get("database.connections.{$connection}.driver");

        if ($driver !== 'mysql' && ! in_array($environment, self::NON_MYSQL_ENVIRONMENTS, true)) {
            $errors[] = "DB_CONNECTION must use the mysql driver (current: {$driver}).";
        }

        if ($checkDatabase) {
            array_push($errors, ...$this->checkDatabase($connection, $driver));
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return list<string>
     */
    private function checkDatabase(string $connection, string $driver): array
    {
        try {
            $pdo = $this->db->connection($connection)->getPdo();
        } catch (Throwable $e) {
            return ["Unable to connect to the [{$connection}] database: {$e->getMessage()}"];
        }

        if ($driver !== 'mysql') {
            return [];
        }

        $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        if (str_contains(strtolower($version), 'mariadb')
            || version_compare($this->normaliseVersion($version), self::MIN_MYSQL_VERSION, '<')) {
            return ['MySQL '.self::MIN_MYSQL_VERSION."+ is required (server reports {$version})."];
        }

        return [];
    }

    private function normaliseVersion(string $version): string
    {
        return preg_match('/^\d+\.\d+\.\d+/', $version, $matches) ? $matches[0] : $version;
    }
}
