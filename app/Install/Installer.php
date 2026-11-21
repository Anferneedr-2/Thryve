<?php

namespace App\Install;

use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;
use Throwable;

class Installer
{
    public const EXTENSIONS = ['bcmath', 'ctype', 'curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml', 'zip'];

    public function installed(): bool
    {
        return is_file(config('thryve.install_marker'));
    }

    /** @return array{requirements: array, permissions: array, ready: bool} */
    public function checks(): array
    {
        $requirements = [[
            'key' => 'php',
            'label' => 'PHP '.config('thryve.min_php').' or newer',
            'ok' => version_compare(PHP_VERSION, config('thryve.min_php'), '>='),
            'detail' => 'Running PHP '.PHP_VERSION,
        ]];
        foreach (self::EXTENSIONS as $ext) {
            $ok = extension_loaded($ext);
            $requirements[] = ['key' => 'ext_'.$ext, 'label' => 'PHP extension: '.$ext, 'ok' => $ok, 'detail' => $ok ? 'Enabled' : 'Enable this extension in cPanel > Select PHP Version'];
        }

        $env = config('thryve.env_path');
        $permissions = [
            $this->writable('Environment file', is_file($env) ? $env : dirname($env), 'Make the file (or its folder) writable'),
            $this->writable('storage/', storage_path(), 'Give the web user write access to storage/'),
            $this->writable('bootstrap/cache/', base_path('bootstrap/cache'), 'Give the web user write access to bootstrap/cache/'),
        ];

        $ready = collect($requirements)->every(fn ($r) => $r['ok']) && collect($permissions)->every(fn ($p) => $p['ok']);

        return ['requirements' => $requirements, 'permissions' => $permissions, 'ready' => $ready, 'min_password_length' => config('thryve.min_password_length')];
    }

    private function writable(string $label, string $path, string $hint): array
    {
        $ok = is_writable($path);

        return ['key' => 'path_'.md5($label), 'label' => $label, 'ok' => $ok, 'detail' => $ok ? 'Writable' : $hint];
    }

    /** @return array{ok: bool, message: string, version?: string} */
    public function testDatabase(array $db): array
    {
        try {
            $pdo = $this->pdo($db);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

            return ['ok' => true, 'message' => 'Connected to the database.', 'version' => $version];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => $this->databaseMessage($e, $db)];
        }
    }

    private function pdo(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['database']);

        return new PDO($dsn, $db['username'], $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    private function databaseMessage(PDOException $e, array $db): string
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());

        return match (true) {
            $code === 1045 => 'The database username or password was not accepted.',
            $code === 1049 => 'That database does not exist. Create it first (cPanel > MySQL Databases) and add the user to it.',
            $code === 1044 => 'The database user is not allowed to use that database. Add the user to the database with all privileges.',
            in_array($code, [2002, 2003, 2005, 2006], true) => "Could not reach the database server at {$db['host']}:{$db['port']}. Check the host and port.",
            default => 'Could not connect to the database (error '.($code ?: 'unknown').').',
        };
    }

    /** @return array{ok: bool, reachable: bool, status: int|null, message: string} */
    public function testHermes(string $url, ?string $key): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        $local = app()->environment('local', 'testing');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['ok' => false, 'reachable' => false, 'status' => null, 'message' => 'Enter a full address starting with https://'];
        }
        if ($scheme !== 'https' && ! $local) {
            return ['ok' => false, 'reachable' => false, 'status' => null, 'message' => 'Hermes must be reached over https://'];
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! $local && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ok' => false, 'reachable' => false, 'status' => null, 'message' => 'That address does not resolve to a public server.'];
        }

        try {
            $request = Http::timeout(5)->withoutRedirecting();
            if ($key) {
                $request = $request->withToken($key);
            }
            $status = $request->get($url)->status();
        } catch (Throwable) {
            return ['ok' => false, 'reachable' => false, 'status' => null, 'message' => 'Could not reach that address.'];
        }

        // Reachability only: the Hermes request contract is not verified yet (plan D02).
        return ['ok' => true, 'reachable' => true, 'status' => $status, 'message' => "The server answered (HTTP {$status}). The Hermes contract is verified later."];
    }

    /**
     * Run the installation. Safe to retry: .env keys are replaced, migrations and role seeding are idempotent,
     * an existing Super Admin is kept, and the marker is written last.
     *
     * @return list<array{key: string, label: string, status: string}>
     */
    public function install(array $input): array
    {
        if ($this->installed()) {
            throw new InstallException('This application is already installed.');
        }

        $steps = [];
        $done = function (string $key, string $label, string $status = 'done') use (&$steps) {
            $steps[] = ['key' => $key, 'label' => $label, 'status' => $status];
        };

        $db = $input['database'];
        $test = $this->testDatabase($db);
        if (! $test['ok']) {
            throw new InstallException($test['message'], 'database');
        }
        $done('database', 'Database connection verified');

        try {
            $values = [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'DB_CONNECTION' => 'mariadb',
                'DB_HOST' => $db['host'],
                'DB_PORT' => (string) $db['port'],
                'DB_DATABASE' => $db['database'],
                'DB_USERNAME' => $db['username'],
                'DB_PASSWORD' => $db['password'] ?? '',
                'HERMES_URL' => $input['hermes']['url'] ?? '',
                'HERMES_API_KEY' => $input['hermes']['key'] ?? '',
            ];
            if (! $this->envHasAppKey()) {
                $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
            }
            EnvFile::set(config('thryve.env_path'), $values, base_path('.env.example'));
        } catch (Throwable $e) {
            report($e);
            throw new InstallException('Could not save the settings file. Make the .env file writable and try again.', 'environment');
        }
        $done('environment', 'Settings saved');

        $this->useDatabase($db);

        try {
            Artisan::call('migrate', ['--force' => true]);
            $done('migrate', 'Database tables created');
            (new RoleSeeder)->run();
            $done('seed', 'Default roles created');
        } catch (Throwable $e) {
            report($e);
            throw new InstallException('Creating the database tables failed. The database user needs permission to create tables. Details were written to storage/logs.', 'migrate');
        }

        try {
            $admin = $this->createSuperAdmin($input['admin']);
            $done('admin', $admin['created'] ? 'Super Admin account created' : 'Existing Super Admin kept', $admin['created'] ? 'done' : 'skipped');
            AuditEntry::create([
                'actor_id' => $admin['user']->id,
                'actor_username' => $admin['user']->username,
                'action' => 'system.installed',
                'metadata' => ['php' => PHP_VERSION],
            ]);
        } catch (Throwable $e) {
            report($e);
            throw new InstallException('Creating the Super Admin account failed. Details were written to storage/logs.', 'admin');
        }

        try {
            $marker = config('thryve.install_marker');
            if (! is_dir(dirname($marker))) {
                mkdir(dirname($marker), 0775, true);
            }
            file_put_contents($marker, json_encode(['installed_at' => now()->toIso8601String()]), LOCK_EX);
        } catch (Throwable $e) {
            report($e);
            throw new InstallException('Could not record that installation finished. Make storage/ writable and try again.', 'finish');
        }
        $done('finish', 'Installation complete');

        return $steps;
    }

    private function envHasAppKey(): bool
    {
        // Only the file being written counts: a key supplied by some other source must still end up in it.
        $path = config('thryve.env_path');

        return is_file($path) && preg_match('/^APP_KEY=\S+/m', (string) file_get_contents($path)) === 1;
    }

    private function useDatabase(array $db): void
    {
        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => $db['host'],
            'database.connections.mariadb.port' => $db['port'],
            'database.connections.mariadb.database' => $db['database'],
            'database.connections.mariadb.username' => $db['username'],
            'database.connections.mariadb.password' => $db['password'] ?? '',
        ]);
        DB::purge('mariadb');
    }

    /** @return array{created: bool, user: User} */
    private function createSuperAdmin(array $a): array
    {
        $roleId = Role::where('slug', Role::SUPER_ADMIN)->value('id');
        $existing = User::where('role_id', $roleId)->first();
        if ($existing) {
            return ['created' => false, 'user' => $existing];
        }

        $user = new User(['username' => $a['username'], 'name' => $a['name'], 'email' => $a['email'] ?: null, 'password' => $a['password']]);
        $user->role_id = $roleId;
        $user->is_active = true;
        $user->save();

        return ['created' => true, 'user' => $user];
    }
}
