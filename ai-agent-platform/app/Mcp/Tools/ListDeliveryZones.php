<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Models\DeliveryZone;
use App\Models\Wilaya;
use App\Services\DeliveryService;

class ListDeliveryZones extends BaseTool
{
    public function __construct(private DeliveryService $delivery) {}

    public function name(): string
    {
        return 'list_delivery_zones';
    }

    public function description(): string
    {
        return 'List the shop delivery zones with fee, delay and the wilayas each zone covers. Use for questions like "do you deliver to ..." or "how much is delivery".';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function scopes(): array
    {
        return self::READ_ALL;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $zones = $this->delivery->list($business)->map(fn (DeliveryZone $zone) => [
            'id' => $zone->id,
            'name' => $zone->name,
            'fee' => (float) $zone->fee,
            'currency' => $business->currency ?: 'DZD',
            'days' => $zone->days,
            'wilayas' => $zone->wilayas->map(fn (Wilaya $w) => [
                'code' => $w->code,
                'name_fr' => $w->name_fr,
                'name_ar' => $w->name_ar,
            ])->values()->all(),
        ])->values()->all();

        return ['zones' => $zones, 'count' => count($zones)];
    }
}
