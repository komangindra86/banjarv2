<?php

namespace App\Http\Controllers;

use App\Platform\Identity\PasswordManager;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    public function password(Request $request)
    {
        return view('account.password', ['forced' => (bool) $request->user()->must_change_password]);
    }

    public function updatePassword(Request $request, PasswordManager $passwords)
    {
        $passwords->change($request->user(), $request->only('current_password', 'password', 'password_confirmation'));
        $request->session()->regenerate();

        return redirect($request->attributes->get('portal') ? '/portal/beranda' : '/admin')->with('success', 'Kata sandi berhasil diganti.');
    }
}
