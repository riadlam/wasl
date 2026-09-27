<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\DeliveryZone;
use App\Models\Wilaya;
use App\Services\DeliveryService;

class ListWilayas extends BaseTool
{
    public function __construct(private DeliveryService $delivery) {}

    public function name(): string
    {
        return 'list_wilayas';
    }

    public function description(): string
    {
        return 'List Algerian wilayas with whether the shop delivers there and the cheapest fee and delay. Filter by zone_id or only_covered.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zone_id' => ['type' => 'integer', 'description' => 'Only wilayas in this delivery zone'],
                'only_covered' => ['type' => 'boolean', 'description' => 'Only wilayas the shop delivers to'],
            ],
        ];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $zones = $this->delivery->list($business);
        $zoneId = (int) ($arguments['zone_id'] ?? 0);
        if ($zoneId > 0) {
            $zones = $zones->where('id', $zoneId)->values();
            if ($zones->isEmpty()) {
                return ['error' => 'Delivery zone not found for this shop.'];
            }
        }

        $best = [];
        foreach ($zones as $zone) {
            /** @var DeliveryZone $zone */
            foreach ($zone->wilayas as $wilaya) {
                $current = $best[$wilaya->id] ?? null;
                if (! $current || (float) $zone->fee < $current['fee']) {
                    $best[$wilaya->id] = ['fee' => (float) $zone->fee, 'days' => $zone->days, 'zone' => $zone->name];
                }
            }
        }

        $onlyCovered = $zoneId > 0 || filter_var($arguments['only_covered'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rows = Wilaya::query()->orderBy('code')->get()
            ->filter(fn (Wilaya $w) => ! $onlyCovered || isset($best[$w->id]))
            ->map(fn (Wilaya $w) => [
                'code' => $w->code,
                'name_fr' => $w->name_fr,
                'name_ar' => $w->name_ar,
                'covered' => isset($best[$w->id]),
                'fee' => $best[$w->id]['fee'] ?? null,
                'days' => $best[$w->id]['days'] ?? null,
                'zone' => $best[$w->id]['zone'] ?? null,
            ])
            ->values()
            ->all();

        return ['wilayas' => $rows, 'currency' => $business->currency ?: 'DZD'];
    }
}
