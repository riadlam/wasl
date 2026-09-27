<?php

namespace App\Services;

class WorkflowCatalog
{
    /**
     * Signals a shop can pick so the AI agent knows what “this is a lead” means.
     *
     * @return list<array{id: string, label: string, hint: string}>
     */
    public function triggerOptions(): array
    {
        return [
            ['id' => 'phone', 'label' => 'Phone number', 'hint' => 'They typed their mobile in the chat'],
            ['id' => 'wilaya', 'label' => 'Wilaya / city', 'hint' => 'They named where they live'],
            ['id' => 'commune', 'label' => 'Commune', 'hint' => 'They named their commune'],
            ['id' => 'email', 'label' => 'Email', 'hint' => 'They shared an email address'],
            ['id' => 'name', 'label' => 'Full name', 'hint' => 'They gave a name for the order'],
            ['id' => 'custom', 'label' => 'Something else', 'hint' => 'Describe what the agent should look for'],
        ];
    }

    public function triggerLabel(string $field): string
    {
        foreach ($this->triggerOptions() as $option) {
            if ($option['id'] === $field) {
                return $option['label'];
            }
        }

        return 'Phone number';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->dmKeyword(),
            $this->postComment(),
            $this->markLead(),
            $this->hotLead(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['id'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function dmKeyword(): array
    {
        return [
            'id' => 'dm_keyword',
            'kind' => 'dm',
            'category' => 'dm',
            'title' => 'DM keyword automation',
            'body' => 'When a DM contains your words or phrases, send a fixed reply (text + image) or let the AI agent answer.',
            'tone' => 'accent',
            'summary' => 'Pick a platform, add trigger words, then choose a fixed message or AI reply.',
            'benefits' => [
                'Works per platform (Instagram, Facebook, WhatsApp, …).',
                'Match simple words or phrases in incoming DMs.',
                'Reply with your own text and optional image, or hand off to the AI agent.',
            ],
            'how' => [
                'Choose which platform this automation watches.',
                'Add keywords like “price”, “prix”, or “كم السعر”.',
                'Set a fixed reply or AI agent, then activate.',
            ],
            'default_config' => [
                'platform' => null,
                'platforms' => [],
                'match' => 'contains',
                'keywords' => [],
                'steps' => [
                    [
                        'type' => 'dm_reply',
                        'mode' => 'fixed',
                        'text' => null,
                        'image_path' => null,
                    ],
                ],
            ],
            'steps' => [
                ['kind' => 'trigger', 'key' => 'keywords', 'title' => 'When someone messages', 'body' => 'Platform + keywords', 'color' => '#e91e63'],
                ['kind' => 'action', 'key' => 'dm_reply', 'title' => 'Send reply', 'body' => 'Fixed or AI', 'color' => '#1b70ff'],
                ['kind' => 'chip', 'label' => 'Done'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postComment(): array
    {
        return [
            'id' => 'post_comment',
            'kind' => 'engagement',
            'category' => 'engagement',
            'title' => 'Post comment automation',
            'body' => 'When someone comments on selected posts, send a public reply and/or a private DM — fixed text or AI.',
            'tone' => 'accent',
            'summary' => 'Pick the posts this flow covers, then configure public reply and Auto DM on the canvas.',
            'benefits' => [
                'Assign one or many posts to the same automation.',
                'Fixed reply or AI agent for comments; custom DM or default agent message.',
            ],
            'how' => [
                'Choose which posts trigger this flow.',
                'Configure public reply (agent or fixed) and Auto DM.',
                'Activate — new comments on those posts run the steps in order.',
            ],
            'default_config' => [
                'steps' => [
                    [
                        'type' => 'public_reply',
                        'enabled' => true,
                        'mode' => 'agent',
                        'text' => null,
                        'image_path' => null,
                    ],
                    [
                        'type' => 'private_dm',
                        'enabled' => true,
                        'mode' => 'agent',
                        'text' => null,
                    ],
                ],
            ],
            'steps' => [
                ['kind' => 'trigger', 'key' => 'posts', 'title' => 'Comment on posts', 'body' => 'Selected posts', 'color' => '#e91e63'],
                ['kind' => 'action', 'key' => 'public_reply', 'title' => 'Public reply', 'body' => 'Agent or fixed', 'color' => '#1b70ff'],
                ['kind' => 'action', 'key' => 'private_dm', 'title' => 'Auto DM', 'body' => 'Private message', 'color' => '#4caf50'],
                ['kind' => 'chip', 'label' => 'Done'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markLead(): array
    {
        return [
            'id' => 'mark_lead',
            'kind' => 'lead',
            'category' => 'leads',
            'title' => 'Mark Lead',
            'body' => 'You pick what “this is a lead” means — a phone number, wilaya, email, name, or something else you describe.',
            'tone' => 'coral',
            'summary' => 'Choose the signal the AI agent should watch for in inbox chats. It marks a lead only when it is sure that signal is the client’s.',
            'benefits' => [
                'The trigger is yours: phone, city, email, name, or a custom instruction.',
                'The agent uses the full conversation, not a keyword list.',
            ],
            'how' => [
                'Pick the signal that means “this is a lead”.',
                'Every inbox webhook runs the agent on the conversation.',
                'If the agent is confident, it saves a stored field when needed and marks a new lead.',
            ],
            'default_config' => ['trigger_field' => 'phone'],
            'trigger_options' => $this->triggerOptions(),
            'steps' => [
                ['kind' => 'trigger', 'title' => 'Trigger', 'body' => 'The signal you pick', 'color' => '#e91e63'],
                ['kind' => 'action', 'title' => 'AI classifies', 'body' => 'Confirm it is theirs', 'color' => '#1b70ff'],
                ['kind' => 'action', 'title' => 'Mark Lead', 'body' => 'Lifecycle', 'color' => '#4caf50'],
                ['kind' => 'chip', 'label' => 'Success'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hotLead(): array
    {
        return [
            'id' => 'hot_lead',
            'kind' => 'lead',
            'category' => 'leads',
            'title' => 'Hot Lead',
            'body' => 'You pick what “hot” means — a wilaya, phone, email, or something else that shows they are ready to order.',
            'tone' => 'apricot',
            'summary' => 'Choose the signal that means this client is ready. The AI agent marks them Hot only when that signal is clearly theirs.',
            'benefits' => [
                'Hot leads follow the rule you set, not a hidden default.',
                'The agent stays on DM and comment webhooks.',
            ],
            'how' => [
                'Pick the signal that means “hot”.',
                'The agent watches the same inbox stream.',
                'When it is sure, it saves a stored field when needed and sets the lead to Hot.',
            ],
            'default_config' => ['trigger_field' => 'wilaya'],
            'trigger_options' => $this->triggerOptions(),
            'steps' => [
                ['kind' => 'trigger', 'title' => 'Trigger', 'body' => 'The signal you pick', 'color' => '#e91e63'],
                ['kind' => 'action', 'title' => 'AI classifies', 'body' => 'Confirm they are ready', 'color' => '#1b70ff'],
                ['kind' => 'action', 'title' => 'Mark Hot', 'body' => 'Lifecycle', 'color' => '#ffb020'],
                ['kind' => 'chip', 'label' => 'Success'],
            ],
        ];
    }
}
