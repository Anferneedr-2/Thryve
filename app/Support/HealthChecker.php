<?php

namespace App\Support;

use App\Mail\MailConfigurator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Super Admin health view. Each check returns a status (ok | warn | fail | unknown) and a short, safe sentence.
 * Never include credentials, connection strings or raw exception text.
 */
class HealthChecker
{
    /** @return list<array{key: string, label: string, status: string, detail: string, checked_at: string}> */
    public function run(): array
    {
        return [
            $this->check('database', 'Database', fn () => $this->database()),
            $this->check('hermes', 'Hermes (AI service)', fn () => $this->hermes()),
            $this->check('agents', 'AI agents', fn () => $this->agentStatus()),
            $this->check('email', 'Email (SMTP)', fn () => $this->email()),
            $this->check('scheduler', 'Scheduler (cron)', fn () => $this->scheduler()),
            $this->check('queue', 'Background jobs', fn () => $this->queue()),
            $this->check('storage', 'File storage', fn () => $this->storage()),
            $this->check('backups', 'Backups', fn () => $this->backups()),
        ];
    }

    private function check(string $key, string $label, callable $fn): array
    {
        try {
            [$status, $detail] = $fn();
        } catch (\Throwable $e) {
            report($e);
            [$status, $detail] = ['fail', 'The check could not be completed.'];
        }

        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'checked_at' => now()->toIso8601String()];
    }

    private function database(): array
    {
        DB::selectOne('select 1');

        return ['ok', 'Connected.'];
    }

    private function hermes(): array
    {
        $url = config('services.hermes.url');
        if (! $url) {
            return ['unknown', 'Not connected yet.'];
        }
        try {
            $status = Http::timeout(5)->withoutRedirecting()->get($url)->status();
        } catch (\Throwable) {
            return ['fail', 'The server did not answer.'];
        }

        // Reachability only; the Hermes contract is verified in a later release (plan D02).
        return ['ok', "The server answered (HTTP {$status})."];
    }

    /** From the last manual test of each enabled agent (nothing is called here). */
    private function agentStatus(): array
    {
        $all = \App\Models\Agent::active()->where('is_enabled', true)->get();
        if ($all->isEmpty()) {
            return ['unknown', 'No AI agent is connected yet.'];
        }
        $bad = $all->filter(fn ($a) => in_array($a->status, ['key_rejected', 'profile_missing', 'unreachable', 'wrong_address', 'revoked'], true));
        if ($bad->count() === $all->count()) {
            return ['fail', 'No agent passed its last test.'];
        }
        if ($bad->isNotEmpty()) {
            return ['warn', "{$bad->count()} of {$all->count()} agents failed their last test."];
        }
        $untested = $all->where('status', 'never_tested')->count();

        return $untested === $all->count() ? ['unknown', 'Agents have not been tested yet.'] : ['ok', ($all->count() - $untested).' of '.$all->count().' agent(s) passed their last test.'];
    }

    private function email(): array
    {
        if (! MailConfigurator::enabled()) {
            return ['unknown', 'Email is switched off or not set up.'];
        }
        $last = Cache::get('smtp:last_test');
        if (! $last) {
            return ['warn', 'Set up, but no test email has been sent yet.'];
        }

        return $last['ok'] ? ['ok', 'The last test email was sent.'] : ['fail', 'The last test email failed: '.MailConfigurator::describe($last['category'])];
    }

    private function scheduler(): array
    {
        $last = Cache::get('scheduler:last_run');
        if (! $last) {
            return ['fail', 'The scheduled job has never run. Add the cron entry on the server.'];
        }
        $age = now()->getTimestamp() - CarbonImmutable::parse($last)->getTimestamp();

        return $age <= 180 ? ['ok', 'Ran within the last few minutes.'] : ['fail', 'The scheduled job stopped running. Check the cron entry on the server.'];
    }

    private function queue(): array
    {
        $stuck = DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->getTimestamp())->count();
        $failed = DB::table('failed_jobs')->count();
        if ($stuck > 0) {
            return ['warn', "{$stuck} background job(s) are waiting longer than expected."];
        }

        return $failed > 0 ? ['warn', "{$failed} background job(s) failed earlier."] : ['ok', 'No jobs waiting.'];
    }

    private function backups(): array
    {
        $latest = app(\App\Updates\BackupService::class)->latest('scheduled');
        if (! $latest) {
            return ['warn', 'No backup has been made yet. The daily backup runs at '.config('thryve.backups.schedule_time').' Philippine time.'];
        }
        $age = now()->getTimestamp() - \Carbon\CarbonImmutable::parse($latest['created_at'])->getTimestamp();
        if ($age > 26 * 3600) {
            return ['fail', 'The last backup is more than a day old.'];
        }
        if (! config('thryve.backups.offsite_disk')) {
            return ['warn', 'Backups are only kept on this server. No off-server copy is set up yet.'];
        }

        return $latest['offsite'] === 'ok' ? ['ok', 'Backed up and copied off the server.'] : ['warn', 'The last backup could not be copied off the server.'];
    }

    private function storage(): array
    {
        $path = storage_path('app');
        if (! is_writable($path)) {
            return ['fail', 'The storage folder is not writable.'];
        }
        $free = @disk_free_space($path);

        return $free !== false && $free < 512 * 1024 * 1024 ? ['warn', 'Less than 512 MB of disk space is free.'] : ['ok', 'Writable.'];
    }
}
