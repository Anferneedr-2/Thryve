<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** The signed-in person's own account. */
class AccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        $d = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.config('thryve.min_password_length'), 'max:255', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();

        if (! Hash::check($d['current_password'], $user->password)) {
            return Api::error('validation_failed', 'The given data was invalid.', 422, ['current_password' => ['The current password is incorrect.']]);
        }

        $user->password = $d['password']; // hashed by the model cast; the session stays signed in
        $user->save();
        Audit::log('account.password_changed', $user, $user);

        return Api::ok(['changed' => true]);
    }
}
