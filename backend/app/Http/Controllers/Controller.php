<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Controllers are deliberately thin: validate (FormRequest) → authorize (Policy) →
 * call a service → shape the response (Resource + ApiResponse).
 */
abstract class Controller
{
    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
