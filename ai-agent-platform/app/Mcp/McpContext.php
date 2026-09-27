<?php

namespace App\Mcp;

final class McpContext
{
    public const SURFACE_OWNER = 'owner';

    public const SURFACE_CUSTOMER = 'customer';

    public const SURFACE_CAMPAIGN = 'campaign';

    public const SURFACE_EXTERNAL = 'external';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $scopes = [],
        public readonly ?int $userId = null,
        public readonly ?int $customerId = null,
        public readonly ?int $conversationId = null,
        public readonly ?int $agentRunId = null,
        public readonly ?int $tokenId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fromAgentContext(string $surface, array $context = []): self
    {
        return new self(
            surface: $surface,
            scopes: [$surface],
            userId: isset($context['user_id']) ? (int) $context['user_id'] : null,
            customerId: isset($context['customer_id']) ? (int) $context['customer_id'] : null,
            conversationId: isset($context['conversation_id']) ? (int) $context['conversation_id'] : null,
            agentRunId: isset($context['agent_run_id']) ? (int) $context['agent_run_id'] : null,
        );
    }

    public function allows(WaslMcpTool $tool): bool
    {
        $scopes = $this->scopes !== [] ? $this->scopes : [$this->surface];

        return array_intersect($scopes, $tool->scopes()) !== [];
    }

    public function isCustomer(): bool
    {
        return $this->surface === self::SURFACE_CUSTOMER;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentContext(): array
    {
        return array_filter([
            'user_id' => $this->userId,
            'customer_id' => $this->customerId,
            'conversation_id' => $this->conversationId,
            'agent_run_id' => $this->agentRunId,
        ], fn ($v) => $v !== null);
    }
}
