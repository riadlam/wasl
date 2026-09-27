<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\WaslMcpTool;
use App\Models\Customer;
use App\Models\Business;

abstract class BaseTool implements WaslMcpTool
{
    public const READ_ALL = [
        McpContext::SURFACE_OWNER,
        McpContext::SURFACE_CUSTOMER,
        McpContext::SURFACE_CAMPAIGN,
        McpContext::SURFACE_EXTERNAL,
    ];

    public function mutates(): bool
    {
        return false;
    }

    protected function currentCustomer(Business $business, McpContext $context): ?Customer
    {
        if (! $context->customerId) {
            return null;
        }

        return Customer::query()->forBusiness($business->id)->find($context->customerId);
    }

    protected function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
