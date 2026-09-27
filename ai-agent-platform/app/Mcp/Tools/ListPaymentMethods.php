<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\PaymentMethodService;

class ListPaymentMethods extends BaseTool
{
    public function __construct(private PaymentMethodService $payments) {}

    public function name(): string
    {
        return 'list_payment_methods';
    }

    public function description(): string
    {
        return 'List this shop\'s enabled payment methods (Flexy, BaridiMob, CCP) ordered by owner priority. Use after create_order to recommend priority-1 details, or when the client asks how to pay / prefers another enabled method. Never invent methods not returned here.';
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
        $methods = $this->payments->listForAgent($business);

        return [
            'methods' => $methods,
            'count' => count($methods),
            'recommended' => $methods[0] ?? null,
        ];
    }
}
