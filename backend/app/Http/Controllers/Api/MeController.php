<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('memberships.restaurant'));
    }

    public function destroy(Request $request, AccountService $accounts): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('delete', $user);

        $accounts->delete($user);

        return response()->noContent();
    }
}
