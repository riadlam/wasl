<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShopProvisioner;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, ShopProvisioner $provisioner, WalletService $wallets): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'shop_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 'active',
            'platform_role' => 'user',
        ]);

        $business = $provisioner->createShop($user, $data['shop_name']);

        $bonus = (float) config('billing.signup_bonus_da', 500);
        if ($bonus > 0) {
            $wallets->credit(
                $user,
                $bonus,
                'signup_bonus',
                $business,
                $user,
                null,
                ['source' => 'register'],
            );
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('current_business_id', $business->id);

        return redirect('/space');
    }
}
