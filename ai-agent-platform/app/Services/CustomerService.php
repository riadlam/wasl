<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerSocialProfile;
use App\Support\CurrentBusiness;

class CustomerService
{
    public function findOrCreate(Business $business, array $data): Customer
    {
        if (isset($data['platform_user_id']) && $data['platform_user_id'] !== '') {
            $data['platform_user_id'] = (string) $data['platform_user_id'];
        } else {
            unset($data['platform_user_id']);
        }

        $customer = $this->matchExisting($business, $data);

        if ($customer) {
            $customer->fill(array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'wilaya' => $data['wilaya'] ?? null,
                'commune' => $data['commune'] ?? null,
                'email' => $data['email'] ?? null,
                'language' => $data['language'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
            $customer->save();
        } else {
            $customer = Customer::query()->create([
                'business_id' => $business->id,
                'name' => $data['name'] ?? 'Customer',
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'wilaya' => $data['wilaya'] ?? null,
                'commune' => $data['commune'] ?? null,
                'language' => $data['language'] ?? null,
            ]);
        }

        $this->upsertSocialProfile($customer, $data);

        return $customer->load('socialProfiles');
    }

    public function search(Business $business, string $query): array
    {
        $term = trim($query);

        return Customer::query()
            ->forBusiness($business->id)
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', '%'.$term.'%')
                        ->orWhere('phone', 'like', '%'.$term.'%');
                });
            })
            ->limit(8)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'wilaya' => $c->wilaya,
            ])
            ->all();
    }

    /**
     * Profile + latest-order checkout fields for warm "can we use this?" confirmation.
     *
     * @return array{
     *   phone: ?string,
     *   wilaya: ?string,
     *   commune: ?string,
     *   address: ?string,
     *   digital_fulfillment: array<string, string>,
     *   prior_order_number: ?string,
     *   sources: list<string>
     * }
     */
    public function knownCheckout(Customer $customer): array
    {
        $sources = [];
        $phone = filled($customer->phone) ? (string) $customer->phone : null;
        $wilaya = filled($customer->wilaya) ? (string) $customer->wilaya : null;
        $commune = filled($customer->commune) ? (string) $customer->commune : null;
        $address = null;
        $digital = [];
        $priorOrderNumber = null;

        if ($phone || $wilaya || $commune) {
            $sources[] = 'profile';
        }

        $latest = $customer->orders()->latest('id')->first();
        if ($latest) {
            $priorOrderNumber = (string) $latest->order_number;
            $sources[] = 'prior_order';
            if (! $phone && filled($latest->phone)) {
                $phone = (string) $latest->phone;
            }
            if (! $wilaya && filled($latest->wilaya)) {
                $wilaya = (string) $latest->wilaya;
            }
            if (! $commune && filled($latest->commune)) {
                $commune = (string) $latest->commune;
            }
            if (filled($latest->address)) {
                $address = (string) $latest->address;
            }
            $meta = is_array($latest->metadata) ? $latest->metadata : [];
            $raw = $meta['digital_fulfillment'] ?? null;
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $k = trim((string) $key);
                    $v = trim((string) $value);
                    if ($k !== '' && $v !== '') {
                        $digital[$k] = $v;
                    }
                }
            }
        }

        return [
            'phone' => $phone,
            'wilaya' => $wilaya,
            'commune' => $commune,
            'address' => $address,
            'digital_fulfillment' => $digital,
            'prior_order_number' => $priorOrderNumber,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * Flatten known checkout into evidence-friendly key=value facts.
     *
     * @return list<string>
     */
    public function knownCheckoutFacts(Customer $customer): array
    {
        $known = $this->knownCheckout($customer);
        $facts = [];
        if ($known['phone']) {
            $facts[] = 'phone='.$known['phone'];
        }
        if ($known['wilaya']) {
            $facts[] = 'wilaya='.$known['wilaya'];
        }
        if ($known['commune']) {
            $facts[] = 'commune='.$known['commune'];
        }
        if ($known['address']) {
            $facts[] = 'address='.$known['address'];
        }
        foreach ($known['digital_fulfillment'] as $key => $value) {
            $facts[] = $key.'='.$value;
            if (preg_match('/(player|game|account|user)?_?id|zone/i', (string) $key)) {
                $facts[] = 'game_or_player_id='.$value;
            }
        }
        if ($known['prior_order_number']) {
            $facts[] = 'prior_order_number='.$known['prior_order_number'];
        }

        return $facts;
    }

    /**
     * @return array{customers: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(?string $search = null, int $perPage = 25, int $page = 1, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 25;
        $page = max(1, $page);

        $query = Customer::query()
            ->forBusiness($business->id)
            ->with(['socialProfiles', 'conversations' => function ($q) {
                $q->with('socialAccount')->orderByDesc('last_message_at')->orderByDesc('id');
            }]);

        $term = is_string($search) ? trim($search) : '';
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('wilaya', 'like', '%'.$term.'%')
                    ->orWhere('commune', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        $paginator = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return [
            'customers' => $paginator->getCollection()->map(fn (Customer $customer) => $this->toArray($customer))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Customer $customer): array
    {
        $conversation = $customer->conversations->first();
        $profile = $customer->socialProfiles->first();
        $ads = $this->metaAdFields($customer, $conversation);

        return [
            'id' => $customer->id,
            'name' => $customer->name ?: ($profile?->username ?: 'Customer'),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'wilaya' => $customer->wilaya,
            'commune' => $customer->commune,
            'language' => $customer->language,
            'lifecycle' => $customer->lifecycle,
            'platform' => $conversation?->platform ?: ($profile?->platform ?: null),
            'username' => $profile?->username,
            'avatar_url' => $profile?->avatar_url,
            'account_name' => $conversation?->socialAccount?->name,
            'conversation_id' => $conversation?->id,
            'conversation_status' => $conversation?->status,
            'meta_ad_id' => $ads['meta_ad_id'],
            'meta_ad_title' => $ads['meta_ad_title'],
        ];
    }

    /**
     * Update contact details (phone, email, location).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateDetails(int $id, array $data, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $customer = Customer::query()
            ->forBusiness($business->id)
            ->with(['socialProfiles', 'conversations' => function ($q) {
                $q->with('socialAccount')->orderByDesc('last_message_at')->orderByDesc('id');
            }])
            ->findOrFail($id);

        $normalize = static function ($value) {
            if ($value === null) {
                return null;
            }
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        };

        $customer->fill([
            'phone' => array_key_exists('phone', $data) ? $normalize($data['phone']) : $customer->phone,
            'email' => array_key_exists('email', $data) ? $normalize($data['email']) : $customer->email,
            'wilaya' => array_key_exists('wilaya', $data) ? $normalize($data['wilaya']) : $customer->wilaya,
            'commune' => array_key_exists('commune', $data) ? $normalize($data['commune']) : $customer->commune,
        ]);
        $customer->save();

        return $this->toArray($customer->fresh([
            'socialProfiles',
            'conversations' => function ($q) {
                $q->with('socialAccount')->orderByDesc('last_message_at')->orderByDesc('id');
            },
        ]));
    }

    /**
     * Pull Meta Ads attribution from a SocialAPI / Meta webhook payload and store it on the customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyMetaAdAttribution(Customer $customer, array $data, ?int $conversationId = null): Customer
    {
        $extracted = $this->extractMetaAdFromPayload($data);
        if ($extracted['meta_ad_id'] === null && $extracted['meta_ad_title'] === null) {
            return $customer;
        }

        $meta = is_array($customer->metadata) ? $customer->metadata : [];
        $changed = false;

        // Keep the latest ad on the customer as the primary display fields.
        if ($extracted['meta_ad_id'] !== null && ($meta['meta_ad_id'] ?? null) !== $extracted['meta_ad_id']) {
            $meta['meta_ad_id'] = $extracted['meta_ad_id'];
            $changed = true;
        }
        if ($extracted['meta_ad_title'] !== null && ($meta['meta_ad_title'] ?? null) !== $extracted['meta_ad_title']) {
            $meta['meta_ad_title'] = $extracted['meta_ad_title'];
            $changed = true;
        }

        $history = is_array($meta['meta_ads'] ?? null) ? array_values($meta['meta_ads']) : [];
        $already = false;
        foreach ($history as $row) {
            if (($row['ad_id'] ?? null) === $extracted['meta_ad_id']
                && (int) ($row['conversation_id'] ?? 0) === (int) ($conversationId ?? 0)) {
                $already = true;
                break;
            }
        }
        if (! $already && $extracted['meta_ad_id'] !== null) {
            $history[] = array_filter([
                'ad_id' => $extracted['meta_ad_id'],
                'title' => $extracted['meta_ad_title'],
                'conversation_id' => $conversationId,
                'seen_at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null && $v !== '');
            // Keep a reasonable trail of every ad click, oldest → newest.
            $meta['meta_ads'] = array_slice($history, -40);
            $changed = true;
        }

        if ($changed) {
            $customer->metadata = $meta;
            $customer->save();
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{meta_ad_id: ?string, meta_ad_title: ?string}
     */
    public function extractMetaAdFromPayload(array $data): array
    {
        $referral = is_array($data['referral'] ?? null) ? $data['referral'] : [];
        $nestedMeta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $nestedReferral = is_array($nestedMeta['referral'] ?? null) ? $nestedMeta['referral'] : [];
        // SocialAPI docs: metadata.referral.ads_context_data.ad_title
        $adsContext = is_array($data['ads_context_data'] ?? null)
            ? $data['ads_context_data']
            : (is_array($referral['ads_context_data'] ?? null)
                ? $referral['ads_context_data']
                : (is_array($nestedReferral['ads_context_data'] ?? null)
                    ? $nestedReferral['ads_context_data']
                    : (is_array($nestedMeta['ads_context_data'] ?? null) ? $nestedMeta['ads_context_data'] : [])));

        $id = $this->firstNonEmptyString([
            $data['ad_id'] ?? null,
            $data['source_id'] ?? null,
            $referral['ad_id'] ?? null,
            $referral['source_id'] ?? null,
            $nestedReferral['ad_id'] ?? null,
            $nestedReferral['source_id'] ?? null,
            $adsContext['ad_id'] ?? null,
            $adsContext['source_id'] ?? null,
            $nestedMeta['ad_id'] ?? null,
            $nestedMeta['source_id'] ?? null,
            // Deep fallback for unexpected nesting / future SocialAPI shapes.
            $this->deepFindAdId($data),
        ]);

        $title = $this->firstNonEmptyString([
            $data['ad_title'] ?? null,
            $data['headline'] ?? null,
            $referral['ad_title'] ?? null,
            $referral['headline'] ?? null,
            $nestedReferral['ad_title'] ?? null,
            $nestedReferral['headline'] ?? null,
            $adsContext['ad_title'] ?? null,
            $adsContext['headline'] ?? null,
            $nestedMeta['ad_title'] ?? null,
            $this->deepFindAdTitle($data),
        ]);

        return [
            'meta_ad_id' => $id,
            'meta_ad_title' => $title,
        ];
    }

    /**
     * Resolve Meta ad fields for API payloads.
     * Prefer this conversation's inbound messages (so older threads keep their own ad),
     * then customer history, then the customer primary fields.
     *
     * @return array{meta_ad_id: ?string, meta_ad_title: ?string}
     */
    public function metaAdFields(Customer $customer, $conversation = null): array
    {
        $meta = is_array($customer->metadata) ? $customer->metadata : [];
        $history = is_array($meta['meta_ads'] ?? null) ? $meta['meta_ads'] : [];

        if ($conversation) {
            $messages = $conversation->relationLoaded('messages')
                ? $conversation->messages
                : $conversation->messages()->orderByDesc('id')->limit(80)->get();

            foreach ($messages->sortByDesc('id') as $message) {
                if (($message->direction ?? null) !== 'inbound') {
                    continue;
                }
                $payload = is_array($message->metadata) ? $message->metadata : [];
                $extracted = $this->extractMetaAdFromPayload($payload);
                if ($extracted['meta_ad_id'] === null) {
                    continue;
                }
                $this->applyMetaAdAttribution($customer, $payload, (int) $conversation->id);

                return [
                    'meta_ad_id' => $extracted['meta_ad_id'],
                    'meta_ad_title' => $extracted['meta_ad_title'],
                ];
            }

            // History entry scoped to this conversation.
            foreach (array_reverse($history) as $row) {
                if ((int) ($row['conversation_id'] ?? 0) === (int) $conversation->id && ! empty($row['ad_id'])) {
                    return [
                        'meta_ad_id' => (string) $row['ad_id'],
                        'meta_ad_title' => isset($row['title']) ? (string) $row['title'] : null,
                    ];
                }
            }
        }

        $id = $this->firstNonEmptyString([$meta['meta_ad_id'] ?? null]);
        $title = $this->firstNonEmptyString([$meta['meta_ad_title'] ?? null]);
        if ($id !== null) {
            return ['meta_ad_id' => $id, 'meta_ad_title' => $title];
        }

        // Last resort: any historical ad on this customer.
        foreach (array_reverse($history) as $row) {
            if (! empty($row['ad_id'])) {
                return [
                    'meta_ad_id' => (string) $row['ad_id'],
                    'meta_ad_title' => isset($row['title']) ? (string) $row['title'] : null,
                ];
            }
        }

        return ['meta_ad_id' => null, 'meta_ad_title' => null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deepFindAdId(array $data): ?string
    {
        $stack = [$data];
        $guard = 0;
        while ($stack !== [] && $guard++ < 200) {
            $node = array_pop($stack);
            if (! is_array($node)) {
                continue;
            }
            foreach (['ad_id', 'source_id', 'adId', 'sourceId'] as $key) {
                if (array_key_exists($key, $node)) {
                    $found = $this->firstNonEmptyString([$node[$key]]);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deepFindAdTitle(array $data): ?string
    {
        $stack = [$data];
        $guard = 0;
        while ($stack !== [] && $guard++ < 200) {
            $node = array_pop($stack);
            if (! is_array($node)) {
                continue;
            }
            foreach (['ad_title', 'headline', 'adTitle'] as $key) {
                if (array_key_exists($key, $node)) {
                    $found = $this->firstNonEmptyString([$node[$key]]);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function matchExisting(Business $business, array $data): ?Customer
    {
        if (! empty($data['phone'])) {
            $byPhone = Customer::query()
                ->forBusiness($business->id)
                ->where('phone', $data['phone'])
                ->first();

            if ($byPhone) {
                return $byPhone;
            }
        }

        $platform = $data['platform'] ?? null;
        $platformUserId = $data['platform_user_id'] ?? null;
        if (! $platform || ! $platformUserId) {
            return null;
        }

        return Customer::query()
            ->forBusiness($business->id)
            ->whereHas('socialProfiles', function ($q) use ($platform, $platformUserId) {
                $q->where('platform', $platform)
                    ->where('platform_user_id', $platformUserId);
            })
            ->first();
    }

    private function upsertSocialProfile(Customer $customer, array $data): void
    {
        $platform = $data['platform'] ?? null;
        if (! $platform) {
            return;
        }

        $query = $customer->socialProfiles()->where('platform', $platform);
        if (! empty($data['platform_user_id'])) {
            $query->where('platform_user_id', $data['platform_user_id']);
        }

        $profile = $query->first() ?: new CustomerSocialProfile([
            'customer_id' => $customer->id,
            'platform' => $platform,
        ]);

        $profile->fill(array_filter([
            'platform_user_id' => $data['platform_user_id'] ?? null,
            'username' => $data['username'] ?? null,
            'profile_url' => $data['profile_url'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
        $profile->save();
    }
}
