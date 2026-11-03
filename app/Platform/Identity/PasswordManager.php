<?php

namespace App\Platform\Identity;

use App\Models\User;
use App\Modules\Krama\AccountManager;
use App\Platform\Audit\Audit;
use App\Platform\Tenancy\CurrentBanjar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

// Ganti kata sandi sendiri (pengurus maupun warga). Kata sandi awal warga tidak boleh dipakai lagi.
final class PasswordManager
{
    public function __construct(private Audit $audit, private CurrentBanjar $tenant) {}

    public function change(User $user, array $input): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers(), Rule::notIn([AccountManager::DEFAULT_PASSWORD])],
        ], [
            'password.not_in' => 'Jangan memakai kata sandi awal. Buat kata sandi baru.',
            'password.confirmed' => 'Ulangi kata sandi baru dengan sama persis.',
        ], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru'])->validate();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi baru harus berbeda dari yang lama.']);
        }
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $this->audit->record($this->tenant->optional() ? $this->tenant->id() : null, $user->id, 'auth.password_changed', 'user', $user->id);
    }
}
