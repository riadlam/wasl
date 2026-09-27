<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('business.{businessId}', function (User $user, int $businessId) {
    if ($user->isSuperAdmin()) {
        return true;
    }

    return $user->memberships()
        ->where('business_id', $businessId)
        ->whereNull('disabled_at')
        ->exists();
});
