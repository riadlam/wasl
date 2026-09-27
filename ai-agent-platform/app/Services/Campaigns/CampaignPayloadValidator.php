<?php

namespace App\Services\Campaigns;

use App\Models\AgentAsset;
use App\Models\AiCampaign;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignPayloadValidator
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, int $businessId, bool $example = false, bool $estimate = false): array
    {
        $validator = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:160'],
            'channel_ids' => ['required', 'array', 'min:1'],
            'channel_ids.*' => ['integer'],
            'day_count' => ['required', 'integer', 'min:1', 'max:7'],
            'starts_on' => ['nullable', 'date', 'after_or_equal:today'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.day_index' => ['required', 'integer', 'min:1', 'max:7'],
            'days.*.posts' => ['required', 'integer', 'min:0', 'max:20'],
            'days.*.stories' => ['required', 'integer', 'min:0', 'max:20'],
            'days.*.times' => ['nullable', 'array'],
            'days.*.times.*' => ['string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'content_mode' => ['required', Rule::in([AiCampaign::MODE_AI_RECENT, AiCampaign::MODE_PRODUCT_IMAGES])],
            'focus_prompt' => ['nullable', 'string', 'max:2000'],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer'],
            'plan_meta' => ['nullable', 'array'],
            'accepted_tease' => ['nullable', 'string', 'max:4000'],
            'brief_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        $owned = SocialAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $data['channel_ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if (count($owned) !== count(array_unique($data['channel_ids']))) {
            throw ValidationException::withMessages([
                'channel_ids' => 'One or more channels are not connected to this shop.',
            ]);
        }

        $posts = 0;
        $stories = 0;
        foreach ($data['days'] as $i => $day) {
            $dayPosts = (int) $day['posts'];
            $times = array_values(array_filter($day['times'] ?? []));
            $posts += $dayPosts;
            $stories += (int) $day['stories'];
            if ($dayPosts > 0 && count($times) < $dayPosts) {
                throw ValidationException::withMessages([
                    "days.$i.times" => 'Add a posting time for each post.',
                ]);
            }
        }
        if ($posts + $stories < 1) {
            throw ValidationException::withMessages([
                'days' => 'Plan at least one post or story.',
            ]);
        }

        if ($data['content_mode'] === AiCampaign::MODE_AI_RECENT) {
            if (mb_strlen(trim((string) ($data['focus_prompt'] ?? ''))) < 10) {
                throw ValidationException::withMessages([
                    'focus_prompt' => 'Focus prompt must be at least 10 characters.',
                ]);
            }
        } elseif (! $estimate) {
            $assetIds = array_values(array_unique(array_map('intval', $data['asset_ids'] ?? [])));
            $have = AgentAsset::query()->where('business_id', $businessId)->whereIn('id', $assetIds)->count();
            if ($have !== count($assetIds)) {
                throw ValidationException::withMessages([
                    'asset_ids' => 'One or more images are invalid for this shop.',
                ]);
            }
            if ($example) {
                if ($have < 1) {
                    throw ValidationException::withMessages([
                        'asset_ids' => 'Upload at least one product image.',
                    ]);
                }
            } else {
                $min = $posts > 0 ? (int) ceil($posts * 0.8) : 0;
                if ($have < $min) {
                    throw ValidationException::withMessages([
                        'asset_ids' => "Upload at least {$min} images for {$posts} posts.",
                    ]);
                }
            }
            $data['asset_ids'] = $assetIds;
        }

        return $data;
    }
}
