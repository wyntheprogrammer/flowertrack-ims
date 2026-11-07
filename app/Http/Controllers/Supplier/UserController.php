<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UserStatusRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\AccountSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(): View
    {
        return view('supplier.users');
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->users->data($request->user()));
    }

    public function show(User $user, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->users->row($user, $request->user())]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->store($request->validated(), $request->user());
        return response()->json([
            'message' => 'Account created. Share the temporary password privately; a password change is required at first login.',
            'data' => $this->users->row($user, $request->user()),
        ], 201);
    }

    public function update(User $user, UpdateUserRequest $request): JsonResponse
    {
        $updated = $this->users->update($user, $request->validated(), $request->user());
        if ((int) $updated->id === (int) $request->user()->id) {
            $request->session()->regenerate();
            AccountSession::remember($request, $updated);
        }
        return response()->json([
            'message' => 'Account updated. Changes to role, email or status revoke previous account sessions.',
            'data' => $this->users->row($updated, $request->user()),
        ]);
    }

    public function status(User $user, UserStatusRequest $request): JsonResponse
    {
        $updated = $this->users->status($user, $request->validated(), $request->user());
        return response()->json([
            'message' => 'Account status saved.',
            'data' => $this->users->row($updated, $request->user()),
        ]);
    }

    public function resetPassword(User $user, ResetUserPasswordRequest $request): JsonResponse
    {
        $updated = $this->users->resetPassword($user, $request->validated(), $request->user());
        return response()->json([
            'message' => 'Temporary password replaced. Previous sessions are revoked; the account must change password after signing in.',
            'data' => $this->users->row($updated, $request->user()),
        ]);
    }
}
