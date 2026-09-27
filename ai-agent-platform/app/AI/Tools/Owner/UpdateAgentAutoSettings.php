<?php

namespace App\AI\Tools\Owner;

use App\AI\Tools\AgentTool;
use App\Models\Business;

class UpdateAgentAutoSettings implements AgentTool
{
    public function name(): string
    {
        return 'update_agent_auto_settings';
    }

    public function description(): string
    {
        return 'Enable or disable shop AI auto-action flags when the owner asks (e.g. turn on publish without confirm, or turn off auto DMs). Only change flags they asked about. Confirm which flags changed in your reply.';
    }

    public function parameters(): array
    {
        $props = [];
        foreach (GetAgentAutoSettings::FLAGS as $key) {
            $props[$key] = [
                'type' => 'boolean',
                'description' => 'Set '.$key.' on (true) or off (false). Omit to leave unchanged.',
            ];
        }

        return [
            'type' => 'object',
            'properties' => $props,
            'required' => [],
        ];
    }

    public function handle(Business $business, array $arguments, array $context = []): array
    {
        $settings = $business->agentSettings;
        if (! $settings) {
            return ['error' => 'Agent settings not found.'];
        }

        $changed = [];
        foreach (GetAgentAutoSettings::FLAGS as $key) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }
            $value = filter_var($arguments[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                return ['error' => "Invalid boolean for {$key}."];
            }
            $before = (bool) ($settings->{$key} ?? false);
            if ($before !== $value) {
                $settings->{$key} = $value;
                $changed[$key] = ['from' => $before, 'to' => $value];
            }
        }

        if ($changed === []) {
            return [
                'ok' => true,
                'changed' => [],
                'message' => 'No flags updated (nothing provided or already set).',
                'flags' => $this->snapshot($settings),
            ];
        }

        $settings->save();

        return [
            'ok' => true,
            'changed' => $changed,
            'flags' => $this->snapshot($settings->fresh()),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function snapshot($settings): array
    {
        $flags = [];
        foreach (GetAgentAutoSettings::FLAGS as $key) {
            $flags[$key] = (bool) ($settings->{$key} ?? false);
        }

        return $flags;
    }
}
