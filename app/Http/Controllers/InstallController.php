<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Install\InstallException;
use App\Install\Installer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstallController extends Controller
{
    public function __construct(private Installer $installer) {}

    public function checks(): JsonResponse
    {
        return Api::ok($this->installer->checks());
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $data = $request->validate($this->databaseRules());

        return Api::ok($this->installer->testDatabase($data['database']));
    }

    public function testHermes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hermes.url' => ['required', 'string', 'max:500'],
            'hermes.key' => ['nullable', 'string', 'max:500'],
        ]);

        return Api::ok($this->installer->testHermes($data['hermes']['url'], $data['hermes']['key'] ?? null));
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate($this->databaseRules() + [
            'hermes.url' => ['nullable', 'url', 'max:500'],
            'hermes.key' => ['nullable', 'string', 'max:500'],
            'admin.username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['nullable', 'email', 'max:255'],
            'admin.password' => ['required', 'string', 'min:'.config('thryve.min_password_length'), 'max:255', 'confirmed'],
        ]);

        if (! $this->installer->checks()['ready']) {
            return Api::error('requirements_failed', 'This server does not meet the installation requirements.', 422);
        }

        try {
            return Api::ok(['steps' => $this->installer->install($data)]);
        } catch (InstallException $e) {
            return Api::error('install_failed', $e->getMessage(), 422, array_filter(['step' => $e->step]));
        }
    }

    private function databaseRules(): array
    {
        return [
            'database.host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:_]+$/'],
            'database.port' => ['required', 'integer', 'between:1,65535'],
            'database.database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$\-]+$/'],
            'database.username' => ['required', 'string', 'max:64'],
            'database.password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
