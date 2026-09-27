<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Workflow;
use App\Support\CurrentBusiness;

class LeadService
{
    public function __construct(
        private WorkflowService $workflows,
        private CustomerService $customers,
    ) {}

    /**
     * @return array{leads: list<array<string, mixed>>, counts: array{all: int, new: int, hot: int}}
     */
    public function list(?string $search = null, ?string $status = null, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $query = Customer::query()
            ->forBusiness($business->id)
            ->where('lifecycle', 'lead')
            ->with(['conversations' => function ($q) {
                $q->with(['socialAccount', 'customer.socialProfiles'])
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id');
            }, 'socialProfiles']);

        if ($status && in_array($status, ['new', 'hot'], true)) {
            $query->where('lead_status', $status);
        }

        $term = is_string($search) ? trim($search) : '';
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('wilaya', 'like', '%'.$term.'%');
            });
        }

        $leads = $query->orderByDesc('lead_marked_at')->orderByDesc('id')->limit(200)->get();

        $counts = [
            'all' => Customer::query()->forBusiness($business->id)->where('lifecycle', 'lead')->count(),
            'new' => Customer::query()->forBusiness($business->id)->where('lifecycle', 'lead')->where('lead_status', 'new')->count(),
            'hot' => Customer::query()->forBusiness($business->id)->where('lifecycle', 'lead')->where('lead_status', 'hot')->count(),
        ];

        return [
            'leads' => $leads->map(fn (Customer $customer) => $this->toArray($customer))->all(),
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(int $id, ?Business $business = null): ?array
    {
        $business ??= CurrentBusiness::require();
        $customer = Customer::query()
            ->forBusiness($business->id)
            ->where('lifecycle', 'lead')
            ->with(['conversations' => function ($q) {
                $q->with(['socialAccount', 'customer.socialProfiles', 'messages'])
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id');
            }, 'socialProfiles'])
            ->find($id);

        return $customer ? $this->toArray($customer) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function mark(Customer $customer, string $status, string $source = 'agent:mark_lead'): array
    {
        $status = $status === 'hot' ? 'hot' : 'new';
        $current = $customer->lead_status;
        if ($current === 'hot' && $status === 'new') {
            return $this->toArray($customer->fresh(['conversations.socialAccount', 'socialProfiles']));
        }
        if ($current === 'converted') {
            return $this->toArray($customer->fresh(['conversations.socialAccount', 'socialProfiles']));
        }

        $customer->forceFill([
            'lifecycle' => 'lead',
            'lead_status' => $status,
            'lead_marked_at' => $customer->lead_marked_at ?: now(),
            'lead_source' => $source,
        ])->save();

        return $this->toArray($customer->fresh(['conversations.socialAccount', 'socialProfiles']));
    }

    public function convert(Customer $customer): void
    {
        $customer->forceFill([
            'lifecycle' => 'customer',
            'lead_status' => 'converted',
        ])->save();
    }

    /**
     * Apply mark_lead / hot_lead if the matching workflow is active and the trigger field is set.
     *
     * @return array<string, mixed>
     */
    public function markFromWorkflow(Business $business, Customer $customer, string $status): array
    {
        $key = $status === 'hot' ? 'hot_lead' : 'mark_lead';
        $workflow = $this->workflows->activeByKey($business, $key);
        if (! $workflow) {
            return ['ok' => false, 'error' => 'That lead workflow is not active.'];
        }

        $field = $workflow->triggerField();
        if ($field !== 'custom') {
            $value = trim((string) ($customer->{$field} ?? ''));
            if ($value === '') {
                return [
                    'ok' => false,
                    'error' => "Save the client's {$field} with update_customer first.",
                    'trigger_field' => $field,
                ];
            }
        }

        $this->mark($customer, $status, 'agent:'.$key);
        $fresh = $customer->fresh(['conversations.socialAccount', 'socialProfiles']);

        return [
            'ok' => true,
            'lead_status' => $fresh?->lead_status,
            'trigger_field' => $field,
        ];
    }

    public function workflowAllows(Business $business, string $templateKey): bool
    {
        return $this->workflows->activeByKey($business, $templateKey) instanceof Workflow;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Customer $customer): array
    {
        $conversation = $customer->conversations->first();
        $profile = $customer->socialProfiles->first();
        if ($conversation) {
            $conversation->loadMissing(['socialAccount', 'messages']);
        }
        $last = $conversation?->messages->sortByDesc('id')->first();
        $account = $conversation?->socialAccount;
        $insights = is_array($customer->metadata) ? ($customer->metadata['insights'] ?? []) : [];
        $ads = $this->customers->metaAdFields($customer, $conversation);

        return [
            'id' => $customer->id,
            'name' => $customer->name ?: ($profile?->username ?: 'Customer'),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'wilaya' => $customer->wilaya,
            'commune' => $customer->commune,
            'language' => $customer->language,
            'lifecycle' => $customer->lifecycle,
            'lead_status' => $customer->lead_status,
            'lead_marked_at' => $customer->lead_marked_at?->toIso8601String(),
            'marked_at' => $customer->lead_marked_at?->diffForHumans(),
            'lead_source' => $customer->lead_source,
            'platform' => $conversation?->platform ?: ($profile?->platform ?: null),
            'username' => $profile?->username,
            'avatar_url' => $profile?->avatar_url,
            'account_name' => $account?->name,
            'conversation_id' => $conversation?->id,
            'conversation_status' => $conversation?->status,
            'preview' => $last?->text ? mb_substr((string) $last->text, 0, 140) : null,
            'insights' => is_array($insights) ? $insights : [],
            'meta_ad_id' => $ads['meta_ad_id'],
            'meta_ad_title' => $ads['meta_ad_title'],
        ];
    }
}
