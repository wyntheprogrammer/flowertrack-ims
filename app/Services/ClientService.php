<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientService
{
    /**
     * Create the client account and contact profile as one transaction.
     */
    public function store(array $input): Client
    {
        $values = $this->profileValues($input);

        return $this->withEmailErrors($values['email'], function () use ($input, $values): Client {
            return DB::transaction(function () use ($input, $values): Client {
                $this->assertEmailAvailable($values['email']);
                $user = new User;
                $user->name = $values['name'];
                $user->email = $values['email'];
                $user->password = $input['password']; // The existing User hashed cast owns hashing.
                $user->role = User::ROLE_CLIENT;
                $user->is_active = $values['status'] === 'Active';
                $user->must_change_password = true;
                $user->save();
                $client = Client::create([
                    'code' => 'TMP-'.Str::ulid(), 'user_id' => $user->id,
                    'phone' => $values['phone'], 'address' => $values['address'],
                ]);
                $client->code = 'CLI-'.str_pad((string) $client->id, 3, '0', STR_PAD_LEFT);
                $client->save();

                return $client->setRelation('user', $user);
            });
        });
    }

    /**
     * Apply an optimistic profile edit with a consistent User-then-Client lock order.
     */
    public function update(Client $client, array $input): Client
    {
        $values = $this->profileValues($input);

        return $this->withEmailErrors($values['email'], function () use ($client, $input, $values): Client {
            return DB::transaction(function () use ($client, $input, $values): Client {
                [$locked, $user] = $this->lockProfile($client);
                $this->assertVersion($locked, $input['version']);
                $this->assertEmailAvailable($values['email'], (int) $user->id);
                $user->name = $values['name'];
                if (mb_strtolower(trim($user->email)) !== $values['email']) {
                    $user->email_verified_at = null;
                }
                $user->email = $values['email'];
                $user->is_active = $values['status'] === 'Active';
                $user->save();
                $locked->phone = $values['phone'];
                $locked->address = $values['address'];
                $locked->save();

                return $locked->setRelation('user', $user);
            });
        }, (int) $client->user_id);
    }

    /**
     * Set the desired status safely; repeating an already-achieved state is a no-op.
     */
    public function updateStatus(Client $client, array $input): Client
    {
        return DB::transaction(function () use ($client, $input): Client {
            [$locked, $user] = $this->lockProfile($client);
            $desired = $input['status'] === 'Active';
            if ((bool) $user->is_active === $desired) {
                return $locked;
            }
            $this->assertVersion($locked, $input['version']);
            $user->is_active = $desired;
            $user->save();

            return $locked->setRelation('user', $user);
        });
    }

    /**
     * Serialize an explicit public allowlist; no credentials or privilege internals leave here.
     */
    public function row(Client $client): array
    {
        $client->loadMissing('user');
        $user = $client->user;
        abort_unless($user !== null && $user->role === User::ROLE_CLIENT, 404);
        $row = [
            'dbId' => (int) $client->id,
            'id' => $client->code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $client->phone,
            'address' => $client->address,
            'registered' => $client->created_at->copy()->setTimezone('Asia/Manila')->format('Y-m-d'),
            'status' => (bool) $user->is_active ? 'Active' : 'Inactive',
        ];
        $versionValues = [...$row, 'user_id' => (int) $client->user_id];
        ksort($versionValues);
        $row['version'] = hash('sha256', json_encode($versionValues, JSON_THROW_ON_ERROR));

        return $row;
    }

    /**
     * Lock the authoritative account before its profile and reject operational accounts.
     */
    private function lockProfile(Client $client): array
    {
        $user = User::query()->lockForUpdate()->findOrFail($client->user_id);
        $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
        abort_unless((int) $locked->user_id === (int) $user->id && $user->role === User::ROLE_CLIENT, 404);
        $locked->setRelation('user', $user);

        return [$locked, $user];
    }

    /**
     * Reject profile versions that no longer describe the locked public state.
     */
    private function assertVersion(Client $client, string $version): void
    {
        if (! hash_equals($this->row($client)['version'], strtolower($version))) {
            throw ValidationException::withMessages([
                'version' => 'This client changed after you opened it. Refresh and review the latest details before saving.',
            ]);
        }
    }

    /**
     * Canonicalize the only editable account and contact fields.
     */
    private function profileValues(array $input): array
    {
        return [
            'name' => trim($input['name']),
            'email' => mb_strtolower(trim($input['email'])),
            'phone' => trim($input['phone']),
            'address' => trim($input['address']),
            'status' => $input['status'],
        ];
    }

    /**
     * Reserve email identity globally across clients and all operational roles.
     */
    private function assertEmailAvailable(string $email, ?int $ignoreUserId = null): void
    {
        $query = User::query()->whereRaw('LOWER(email) = ?', [$email]);
        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
        }
    }

    /**
     * Convert raced unique-email violations after rollback without exposing SQL or user data.
     */
    private function withEmailErrors(string $email, Closure $action, ?int $ignoreUserId = null): Client
    {
        try {
            return $action();
        } catch (QueryException $exception) {
            $otherAccount = User::query()->whereRaw('LOWER(email) = ?', [$email]);
            if ($ignoreUserId !== null) {
                $otherAccount->where('id', '!=', $ignoreUserId);
            }
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true)
                && $otherAccount->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }
            throw $exception;
        }
    }
}
