<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Business;
use App\Services\LeadService;

class MarkLead extends BaseTool
{
    public function __construct(private LeadService $leads) {}

    public function name(): string
    {
        return 'mark_lead';
    }

    public function description(): string
    {
        return 'Mark the current client as a new or hot lead after the shop\'s chosen signal is clearly present. For stored fields, save them with update_customer first. For a custom signal, mark only when the shop instruction matches the conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['new', 'hot'],
                    'description' => 'new = Mark Lead workflow, hot = Hot Lead workflow',
                ],
            ],
            'required' => ['status'],
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

        $status = strtolower((string) ($arguments['status'] ?? 'new'));
        if (! in_array($status, ['new', 'hot'], true)) {
            $status = 'new';
        }

        return $this->leads->markFromWorkflow($business, $customer, $status);
    }
}
