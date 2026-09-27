<?php

namespace App\Mcp;

use App\Mcp\Tools;
use Illuminate\Contracts\Container\Container;

class WaslMcpRegistry
{
    /**
     * @var list<class-string<WaslMcpTool>>
     */
    public const TOOLS = [
        Tools\GetShopProfile::class,
        Tools\SearchProducts::class,
        Tools\GetProduct::class,
        Tools\GetProductStock::class,
        Tools\ListCategories::class,
        Tools\ListDeliveryZones::class,
        Tools\ListWilayas::class,
        Tools\GetDeliveryPrice::class,
        Tools\ListPaymentMethods::class,
        Tools\ListOrders::class,
        Tools\GetOrder::class,
        Tools\GetCustomer::class,
        Tools\UpdateCustomer::class,
        Tools\MarkLead::class,
        Tools\CreateOrder::class,
        Tools\CancelOrder::class,
        Tools\HandoffToHuman::class,
        Tools\ListAiCampaigns::class,
        Tools\GetAiCampaign::class,
        Tools\CreateAiCampaign::class,
    ];

    /**
     * @var array<string, WaslMcpTool>|null
     */
    private ?array $tools = null;

    public function __construct(private Container $app) {}

    /**
     * @return array<string, WaslMcpTool>
     */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::TOOLS as $class) {
                $tool = $this->app->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function find(string $name): ?WaslMcpTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return list<WaslMcpTool>
     */
    public function forContext(McpContext $context): array
    {
        return array_values(array_filter($this->all(), fn (WaslMcpTool $tool) => $context->allows($tool)));
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    public function describe(WaslMcpTool $tool): array
    {
        return [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'readOnlyHint' => ! $tool->mutates(),
                'scopes' => $tool->scopes(),
            ],
        ];
    }
}
