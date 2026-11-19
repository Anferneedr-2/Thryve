<?php

namespace Tests\Feature;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConventionsTest extends TestCase
{
    public function test_database_stores_and_returns_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        DB::statement('create temporary table t_utc (at timestamp null)');
        DB::table('t_utc')->insert(['at' => '2026-11-19 16:30:00']);
        $this->assertSame('2026-11-19 16:30:00', DB::table('t_utc')->value('at'));
    }

    public function test_instant_maps_to_philippine_business_day(): void
    {
        // 16:30 UTC is 00:30 next day in Manila (UTC+8).
        $this->assertSame('2026-11-20', BusinessDay::dateOf(CarbonImmutable::parse('2026-11-19 16:30:00', 'UTC')));
        // 15:59:59 UTC is still 23:59:59 same day.
        $this->assertSame('2026-11-19', BusinessDay::dateOf(CarbonImmutable::parse('2026-11-19 15:59:59', 'UTC')));
    }

    public function test_business_day_bounds_are_utc_and_half_open(): void
    {
        $this->assertSame('2026-11-18 16:00:00', BusinessDay::startUtc('2026-11-19')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-19 16:00:00', BusinessDay::endUtc('2026-11-19')->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', BusinessDay::startUtc('2026-11-19')->timezoneName);
    }

    public function test_success_envelope(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['data' => ['status' => 'ok']]);
    }

    public function test_unauthenticated_envelope(): void
    {
        Route::middleware(['api', 'auth'])->get('/api/_t/secure', fn () => ['x' => 1]);

        $this->getJson('/api/_t/secure')->assertStatus(401)
            ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Authentication required.']]);
    }

    public function test_validation_envelope(): void
    {
        Route::middleware('api')->post('/api/_t/validate', fn () => throw ValidationException::withMessages(['name' => ['Required.']]));

        $this->postJson('/api/_t/validate')->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.name.0', 'Required.');
    }

    public function test_not_found_envelope(): void
    {
        $this->getJson('/api/nope')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_server_errors_do_not_leak_internals(): void
    {
        Route::middleware('api')->get('/api/_t/boom', fn () => throw new \RuntimeException('secret-db-password'));

        $this->getJson('/api/_t/boom')->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error')
            ->assertDontSee('secret-db-password');
    }

    public function test_scheduler_heartbeat_is_registered_and_runs(): void
    {
        $events = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringContainsString('thryve:heartbeat', $events);

        Cache::forget('scheduler:last_run');
        $this->artisan('thryve:heartbeat')->assertSuccessful();
        $this->assertNotNull(Cache::get('scheduler:last_run'));
    }

    public function test_private_disk_is_not_web_served(): void
    {
        $disk = config('filesystems.disks.private');
        $this->assertFalse($disk['serve']);
        $this->assertSame('private', $disk['visibility']);

        Storage::fake(config('thryve.private_disk'));
        Storage::disk('private')->put('a/b.txt', 'hi');
        Storage::disk('private')->assertExists('a/b.txt');
        $this->get('/storage/a/b.txt')->assertStatus(200)->assertSee('id="root"', false); // SPA shell, not the file
    }
}
