<?php

namespace App\Services;

use App\Models\User;
use App\Rules\SafeAccountPassword;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserService
{
    public const ROLES = [
        User::ROLE_ADMIN => 'Admin',
        User::ROLE_SUPPLIER => 'Supplier/Staff',
        User::ROLE_DELIVERY => 'Delivery Personnel',
    ];

    /** Share validation rules between HTTP requests and direct service callers. */
    public static function rules(string $operation): array
    {
        $version = ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'];
        $password = ['required', 'string', Password::min(12), new SafeAccountPassword()];
        $rules = match ($operation) {
            'store' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:254'],
                'role' => ['required', Rule::in(array_keys(self::ROLES))],
                'status' => ['required', Rule::in(['Active', 'Inactive'])],
                'password' => $password,
            ],
            'update' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:254'],
                'role' => ['required', Rule::in(array_keys(self::ROLES))],
                'status' => ['required', Rule::in(['Active', 'Inactive'])],
                'version' => $version,
            ],
            'status' => [
                'status' => ['required', Rule::in(['Active', 'Inactive'])],
                'version' => $version,
            ],
            'reset' => ['password' => $password, 'version' => $version],
            default => throw new \InvalidArgumentException('Unknown account operation.'),
        };

        foreach (['id', 'code', 'user_id', 'is_active', 'must_change_password',
            'remember_token', 'password_hash', 'email_verified_at', 'created_at', 'updated_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if (!in_array($operation, ['store', 'reset'], true)) {
            $rules['password'] = ['prohibited'];
        }

        return $rules;
    }

    /** List operational accounts only; Client identities stay in Client Management. */
    public function data(User $actor): array
    {
        $this->authorize($actor);
        $query = User::query()->whereIn('role', array_keys(self::ROLES));
        if (Schema::hasTable('clients')) {
            $query->whereNotIn('id', DB::table('clients')->select('user_id'));
        }
        $users = $query->orderBy('id')->get();
        $activeAdmins = $users->where('role', User::ROLE_ADMIN)->where('is_active', true)->count();

        return ['data' => $users->map(fn (User $user) => $this->row($user, $actor, $activeAdmins))->all()];
    }

    /** Expose a credential-free DTO and opaque stale-update version. */
    public function row(User $user, User $actor, ?int $activeAdmins = null): array
    {
        $this->authorize($actor);
        $this->operational($user);
        $activeAdmins ??= User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count();
        $self = (int) $user->id === (int) $actor->id;
        $last = $user->role === User::ROLE_ADMIN && $user->is_active && $activeAdmins <= 1;

        return [
            'dbId' => (int) $user->id,
            'id' => 'USR-'.str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
            'name' => $user->name, 'email' => $user->email,
            'role' => self::ROLES[$user->role], 'roleValue' => $user->role,
            'status' => $user->is_active ? 'Active' : 'Inactive',
            'mustChangePassword' => (bool) $user->must_change_password,
            'created' => $user->created_at?->copy()->setTimezone('Asia/Manila')->toIso8601String(),
            'version' => $this->version($user),
            'isSelf' => $self, 'isLastAdmin' => $last,
            'canReset' => !$self, 'canChangeStatus' => !$self && !$last,
        ];
    }

    /** Create an account with a hashed temporary password and forced first-login change. */
    public function store(array $input, User $actor): User
    {
        $values = $this->validateInput('store', $input);
        return $this->write(function () use ($values, $actor) {
            $this->lockAdmins($actor);
            $this->uniqueEmail($values['email']);
            $user = new User();
            $user->name = $values['name'];
            $user->email = $values['email'];
            $user->role = $values['role'];
            $user->is_active = $values['status'] === 'Active';
            $user->password = $values['password'];
            $user->must_change_password = true;
            $user->save();
            $this->audit('created', $actor, $user, ['name', 'email', 'role', 'status']);
            return $user;
        });
    }

    /** Change only profile/access fields; passwords require the separate reset action. */
    public function update(User $target, array $input, User $actor): User
    {
        $values = $this->validateInput('update', $input);
        return $this->write(function () use ($target, $values, $actor) {
            $admins = $this->lockAdmins($actor);
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->operational($user);
            $this->assertVersion($user, $values['version']);
            $active = $values['status'] === 'Active';
            $this->protectAccess($user, $actor, $values['role'], $active, $admins);
            $this->uniqueEmail($values['email'], (int) $user->id);
            $accessChanged = $user->email !== $values['email']
                || $user->role !== $values['role'] || (bool) $user->is_active !== $active;
            $fields = [];
            foreach (['name', 'email', 'role'] as $field) {
                if ($user->{$field} !== $values[$field]) $fields[] = $field;
            }
            if ((bool) $user->is_active !== $active) $fields[] = 'status';
            if ($user->email !== $values['email']) $user->email_verified_at = null;
            $user->name = $values['name'];
            $user->email = $values['email'];
            $user->role = $values['role'];
            $user->is_active = $active;
            if ($accessChanged) $user->remember_token = Str::random(60);
            $user->save();
            if ($fields !== []) $this->audit('updated', $actor, $user, $fields);
            return $user;
        });
    }

    /** Set an explicit desired status; a matching retry is a no-op, never a toggle. */
    public function status(User $target, array $input, User $actor): User
    {
        $values = $this->validateInput('status', $input);
        return $this->write(function () use ($target, $values, $actor) {
            $admins = $this->lockAdmins($actor);
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->operational($user);
            $active = $values['status'] === 'Active';
            if ((bool) $user->is_active === $active) return $user;
            $this->assertVersion($user, $values['version']);
            $this->protectAccess($user, $actor, $user->role, $active, $admins);
            $user->is_active = $active;
            $user->remember_token = Str::random(60);
            $user->save();
            $this->audit('status_changed', $actor, $user, ['status']);
            return $user;
        });
    }

    /** Issue a fresh temporary password without returning or logging the credential. */
    public function resetPassword(User $target, array $input, User $actor): User
    {
        $values = $this->validateInput('reset', $input);
        return $this->write(function () use ($target, $values, $actor) {
            $this->lockAdmins($actor);
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->operational($user);
            if ((int) $user->id === (int) $actor->id) {
                $this->invalid('password', 'Use your own Change Password screen instead of an Admin reset.');
            }
            $this->assertVersion($user, $values['version']);
            if (Hash::check($values['password'], $user->password)) {
                $this->invalid('password', 'Choose a different temporary password.');
            }
            $user->password = $values['password'];
            $user->must_change_password = true;
            $user->remember_token = Str::random(60);
            $user->save();
            $this->audit('temporary_password_reset', $actor, $user, ['password', 'must_change_password']);
            return $user;
        });
    }

    /** Lock Admin rows in ID order so concurrent changes cannot remove the last active Admin. */
    private function lockAdmins(User $actor)
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->lockForUpdate()->get();
        $current = $admins->firstWhere('id', $actor->id);
        abort_unless($current && $current->is_active && !$current->must_change_password, 403);
        return $admins;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->role === User::ROLE_ADMIN && $actor->is_active && !$actor->must_change_password, 403);
    }

    private function operational(User $user): void
    {
        abort_unless(isset(self::ROLES[$user->role]), 404);
        if (Schema::hasTable('clients')) {
            abort_if(DB::table('clients')->where('user_id', $user->id)->exists(), 404);
        }
    }

    private function protectAccess(User $user, User $actor, string $role, bool $active, $admins): void
    {
        if ((int) $user->id === (int) $actor->id && ($role !== User::ROLE_ADMIN || !$active)) {
            $this->invalid('role', 'You cannot deactivate or remove Admin access from your own account.');
        }
        if ($user->role === User::ROLE_ADMIN && $user->is_active
            && ($role !== User::ROLE_ADMIN || !$active)
            && $admins->where('is_active', true)->count() <= 1) {
            $this->invalid('role', 'The last active Admin must remain active and retain the Admin role.');
        }
    }

    private function version(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            (int) $user->id, $user->name, $user->email, $user->role,
            (bool) $user->is_active, (bool) $user->must_change_password,
            $user->password, $user->remember_token,
            $user->email_verified_at?->format('Y-m-d H:i:s'),
            $user->updated_at?->format('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function assertVersion(User $user, string $expected): void
    {
        if (!hash_equals($this->version($user), $expected)) {
            $this->invalid('version', 'Account changed. Close and reopen this dialog.');
        }
    }

    private function uniqueEmail(string $email, ?int $ignore = null): void
    {
        $query = User::query()->whereRaw('LOWER(email) = ?', [$email]);
        if ($ignore !== null) $query->where('id', '!=', $ignore);
        if ($query->exists()) $this->invalid('email', 'This email already belongs to an account.');
    }

    private function validateInput(string $operation, array $input): array
    {
        if (isset($input['name']) && is_string($input['name'])) $input['name'] = trim($input['name']);
        if (isset($input['email']) && is_string($input['email'])) $input['email'] = Str::lower(trim($input['email']));
        return Validator::make($input, self::rules($operation))->validate();
    }

    /** Convert only email-uniqueness races into a validation response. */
    private function write(callable $callback): User
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true)
                && str_contains(strtolower($exception->getMessage()), 'email')) {
                $this->invalid('email', 'This email already belongs to an account.');
            }
            throw $exception;
        }
    }

    /** Record safe event metadata after commit; never include passwords, hashes or tokens. */
    private function audit(string $action, User $actor, User $target, array $fields): void
    {
        $context = ['actor_id' => (int) $actor->id, 'user_id' => (int) $target->id, 'changed_fields' => $fields];
        DB::afterCommit(static fn () => Log::info('Account '.$action, $context));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
