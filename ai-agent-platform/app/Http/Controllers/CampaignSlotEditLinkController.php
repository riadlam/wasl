<?php

namespace App\Http\Controllers;

use App\Models\AiCampaignSlot;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Signed Telegram → Wasl deep link for editing a campaign slot.
 */
class CampaignSlotEditLinkController
{
    public function __invoke(Request $request, int $slot): RedirectResponse
    {
        $business = CurrentBusiness::require();
        $model = AiCampaignSlot::query()
            ->with('campaign')
            ->findOrFail($slot);

        $campaign = $model->campaign;
        if (! $campaign || (int) $campaign->business_id !== (int) $business->id) {
            abort(403, 'This post does not belong to the current shop.');
        }

        $query = ['c' => $campaign->id];
        if ($model->status === AiCampaignSlot::STATUS_AWAITING_APPROVAL) {
            $query['edit'] = $model->id;
        }

        return redirect()->to('/space/campaigns?'.http_build_query($query));
    }
}
