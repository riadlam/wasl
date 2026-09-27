<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\CustomerService;

class GetCustomer extends BaseTool
{
    public function __construct(private CustomerService $customers) {}

    public function name(): string
    {
        return 'get_customer';
    }

    public function description(): string
    {
        return 'Customer details plus known_checkout from profile and their latest prior order (phone, wilaya, address, digital game IDs). Use this to CONFIRM returning-customer fields warmly ("can we use this phone / ID?") instead of blank re-asking.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Name or phone (owner only)'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_OWNER, McpContext::SURFACE_CUSTOMER, McpContext::SURFACE_EXTERNAL];
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        if ($context->isCustomer()) {
            $customer = $this->currentCustomer($business, $context);
            if (! $customer) {
                return ['error' => 'Customer is missing'];
            }

            $known = $this->customers->knownCheckout($customer);

            return [
                'customers' => [[
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'wilaya' => $customer->wilaya,
                    'commune' => $customer->commune,
                    'lead_status' => $customer->lead_status,
                    'known_checkout' => $known,
                ]],
            ];
        }

        return ['customers' => $this->customers->search($business, (string) ($arguments['query'] ?? ''))];
    }
}
