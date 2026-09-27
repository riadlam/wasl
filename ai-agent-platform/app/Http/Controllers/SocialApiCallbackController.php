<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Services\OnboardingService;
use App\Services\SocialApi\SocialApiAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class SocialApiCallbackController extends Controller
{
    public function __invoke(Request $request, SocialApiAccountService $accounts, OnboardingService $onboarding): RedirectResponse
    {
        $status = $request->query('status');
        $state = (string) $request->query('state', '');

        try {
            $decoded = $state !== '' ? $accounts->decodeState($state) : [];
        } catch (Throwable) {
            return $this->toChannels('error', 'invalid_state');
        }

        $businessId = $decoded['business_id'] ?? null;
        $userId = $decoded['user_id'] ?? null;
        $business = $businessId ? Business::query()->find($businessId) : null;
        if (! $business) {
            return $this->toChannels('error', 'unknown_shop');
        }

        // OAuth leaves our domain (Meta → SocialAPI → ngrok). Do not require an
        // existing browser session; restore shop + user from the signed state.
        $request->session()->put('current_business_id', $business->id);

        if ($userId && ! Auth::check()) {
            $user = User::query()->find($userId);
            if ($user) {
                Auth::login($user);
                $request->session()->regenerate();
                $request->session()->put('current_business_id', $business->id);
            }
        }

        if ($status === 'selection_required') {
            $qs = http_build_query([
                'socialapi' => 'select',
                'connection_id' => $request->query('connection_id'),
                'platform' => $request->query('platform', $decoded['platform'] ?? ''),
            ]);

            return redirect('/space/channels?'.$qs);
        }

        if ($status !== 'success') {
            return $this->toChannels('error', (string) $request->query('error', 'denied'));
        }

        $accountId = (string) $request->query('account_id');
        try {
            $accounts->ensureBrand($business->fresh());
            $remote = $accounts->fetchAccount($accountId);
            $saved = $accounts->upsertFromRemote($business->fresh(), $remote);
            $onboarding->onChannelConnected($business, $saved);
        } catch (Throwable) {
            return $this->toChannels('error', 'fetch_failed');
        }

        return $this->toChannels('connected');
    }

    private function toChannels(string $socialapi, ?string $reason = null): RedirectResponse
    {
        $query = array_filter([
            'socialapi' => $socialapi,
            'reason' => $reason,
        ]);

        $qs = http_build_query($query);

        return redirect($qs !== '' ? '/space/channels?'.$qs : '/space/channels');
    }
}
