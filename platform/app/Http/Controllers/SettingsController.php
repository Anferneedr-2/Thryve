<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Mail\MailConfigurator;
use App\Mail\NotificationMail;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** Super Admin only (route guard) and again in the service. */
class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return Api::ok(Settings::all());
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->json()->all();
        if ($values === []) {
            return Api::error('validation_failed', 'No settings were provided.', 422);
        }
        $changes = Settings::update($values, $request->user());

        return Api::ok(['changed' => array_keys($changes), 'settings' => Settings::all()]);
    }

    /**
     * Send a real test email with the saved SMTP settings, right now. Failures come back as a short safe
     * category (never the server's raw message, which can contain hosts or credentials).
     */
    public function smtpTest(Request $request): JsonResponse
    {
        $d = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $to = $d['to'] ?? $request->user()->email;
        if (! $to) {
            return Api::error('validation_failed', 'Enter an address to send the test to.', 422, ['to' => ['Enter an address to send the test to.']]);
        }
        if (! filled(Settings::get('smtp_host')) || ! filled(Settings::get('smtp_from_address'))) {
            return Api::error('validation_failed', 'Save the server name and the From address first.', 422, ['smtp_host' => ['Save the server name and the From address first.']]);
        }

        try {
            MailConfigurator::apply();
            Mail::mailer('smtp')->to($to)->send(new NotificationMail('Test email', 'This message confirms that email is set up correctly.'));
            Cache::forever('smtp:last_test', ['ok' => true, 'at' => now()->toIso8601String()]);
            Audit::log('settings.smtp_tested', $request->user(), null, ['ok' => true]);

            return Api::ok(['ok' => true, 'message' => 'The test email was sent.']);
        } catch (\Throwable $e) {
            report($e);
            $category = MailConfigurator::category($e);
            Cache::forever('smtp:last_test', ['ok' => false, 'category' => $category, 'at' => now()->toIso8601String()]);
            Audit::log('settings.smtp_tested', $request->user(), null, ['ok' => false, 'category' => $category]);

            return Api::ok(['ok' => false, 'category' => $category, 'message' => MailConfigurator::describe($category)]);
        }
    }
}
