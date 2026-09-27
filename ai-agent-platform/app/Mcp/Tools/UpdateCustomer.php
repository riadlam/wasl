<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;

class UpdateCustomer extends BaseTool
{
    public function name(): string
    {
        return 'update_customer';
    }

    public function description(): string
    {
        return 'Save the current client\'s phone, wilaya, commune, email, or name only when you are sure from the conversation that they shared their own details.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'phone' => ['type' => 'string', 'description' => 'Client mobile number if they clearly gave it.'],
                'wilaya' => ['type' => 'string', 'description' => 'Delivery wilaya / city they stated.'],
                'commune' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'name' => ['type' => 'string', 'description' => 'Client full name if they clearly gave it for an order.'],
            ],
        ];
    }

    public function scopes(): array
    {
        return [McpContext::SURFACE_CUSTOMER];
    }

    public function mutates(): bool
    {
        return true;
    }

    public function handle(Business $business, array $arguments, McpContext $context): array
    {
        $customer = $this->currentCustomer($business, $context);
        if (! $customer) {
            return ['ok' => false, 'error' => 'Customer is missing'];
        }

        $payload = array_filter([
            'phone' => $this->cleanString($arguments['phone'] ?? null),
            'wilaya' => $this->cleanString($arguments['wilaya'] ?? null),
            'commune' => $this->cleanString($arguments['commune'] ?? null),
            'email' => $this->cleanString($arguments['email'] ?? null),
            'name' => $this->cleanString($arguments['name'] ?? null),
        ], fn ($value) => $value !== null);

        if ($payload === []) {
            return ['ok' => false, 'error' => 'No fields to update'];
        }

        $customer->fill($payload);
        $customer->save();

        return [
            'ok' => true,
            'phone' => $customer->phone,
            'wilaya' => $customer->wilaya,
            'commune' => $customer->commune,
            'email' => $customer->email,
            'name' => $customer->name,
        ];
    }
}
