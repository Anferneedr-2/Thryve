<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\Audit;
use App\Updates\BackupService;
use App\Updates\Maintenance;
use App\Updates\UpdateException;
use App\Updates\UpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Super Admin only (route guard). Updates, rollback and backups. */
class UpdateController extends Controller
{
    public function __construct(private UpdateManager $updates, private BackupService $backups) {}

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (UpdateException $e) {
            return Api::error($e->codeName, $e->getMessage(), $e->status);
        }
    }

    public function status(): JsonResponse
    {
        return Api::ok($this->updates->status());
    }

    public function stage(Request $request): JsonResponse
    {
        $request->validate([
            'package' => ['required', 'file', 'max:262144'],
            'signature' => ['required', 'file', 'max:16'],
        ]);

        return $this->guard(function () use ($request) {
            $state = $this->updates->stage($request->file('package')->getRealPath(), $request->file('signature')->getRealPath());
            Audit::log('system.update_staged', $request->user(), null, ['version' => $state['version'], 'files' => $state['file_count']]);

            return Api::ok($this->updates->status());
        });
    }

    public function approve(Request $request): JsonResponse
    {
        $d = $request->validate(['confirm' => ['required', 'string', 'max:20']]);

        return $this->guard(function () use ($request, $d) {
            $token = $this->updates->approve($d['confirm']);
            Audit::log('system.update_approved', $request->user(), null, ['version' => $d['confirm']]);
            // This browser may keep using the site while everyone else sees the maintenance page.
            $cookie = Cookie::make(Maintenance::COOKIE, $token, 0, '/', null, $request->isSecure(), true, false, 'lax');

            return Api::ok($this->updates->status())->withCookie($cookie);
        });
    }

    /** Code was deployed and the database is behind it: run the database-only update (backup first, rollback possible). */
    public function approveDatabase(Request $request): JsonResponse
    {
        $d = $request->validate(['confirm' => ['required', 'string', 'max:20']]);

        return $this->guard(function () use ($request, $d) {
            $token = $this->updates->approveDatabase($d['confirm']);
            Audit::log('system.database_update_approved', $request->user(), null, ['changes' => count($this->updates->state()['pending_migrations'] ?? [])]);
            $cookie = Cookie::make(Maintenance::COOKIE, $token, 0, '/', null, $request->isSecure(), true, false, 'lax');

            return Api::ok($this->updates->status())->withCookie($cookie);
        });
    }

    public function advance(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $before = $this->updates->state();
            $state = $this->updates->advance();
            if (in_array($state['status'], ['failed', 'done'], true) || (($before['status'] ?? '') === 'running' && $state['status'] === 'staged')) {
                try {
                    Audit::log('system.update_'.$state['status'], $request->user(), null, ['version' => $state['version'], 'step' => $state['failed_step'] ?? null]);
                } catch (\Throwable) {
                }
            }

            return Api::ok($this->updates->status());
        });
    }

    public function rollback(Request $request): JsonResponse
    {
        $d = $request->validate(['confirm' => ['required', 'string', 'max:20']]);

        return $this->guard(function () use ($request, $d) {
            $this->updates->rollback($d['confirm'], $request->user());

            return Api::ok($this->updates->status());
        });
    }

    public function discard(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $this->updates->discard();
            Audit::log('system.update_discarded', $request->user());

            return Api::ok($this->updates->status());
        });
    }

    // ---- backups -------------------------------------------------------------------------------

    private function presentBackup(array $m): array
    {
        return [
            'id' => $m['id'], 'label' => $m['label'], 'kind' => $m['kind'], 'created_at' => $m['created_at'], 'app_version' => $m['app_version'],
            'db_bytes' => $m['db_bytes'], 'files_bytes' => $m['files_bytes'], 'tables' => count($m['tables']), 'offsite' => $m['offsite'],
        ];
    }

    public function backups(): JsonResponse
    {
        return Api::ok(array_map(fn ($m) => $this->presentBackup($m), $this->backups->all()));
    }

    public function backupNow(Request $request): JsonResponse
    {
        if (app(Maintenance::class)->active()) {
            return Api::error('busy', 'An update is running. Try again when it has finished.', 409);
        }
        $meta = $this->backups->create('Manual backup', 'scheduled', [], ['storage/app/private/files', 'storage/app/private/branding']);
        if ($problems = $this->backups->verify($meta)) {
            return Api::error('backup_failed', 'The backup did not pass its check: '.implode(' ', $problems), 500);
        }
        $this->backups->copyOffsite($meta);
        Audit::log('system.backup_created', $request->user(), null, ['id' => $meta['id']]);

        return Api::ok($this->presentBackup($this->backups->find($meta['id'])), status: 201);
    }

    public function verifyBackup(string $id): JsonResponse
    {
        $meta = $this->backups->find($id);
        abort_unless($meta, 404);
        $problems = $this->backups->verify($meta);

        return Api::ok(['ok' => $problems === [], 'problems' => $problems]);
    }
}
