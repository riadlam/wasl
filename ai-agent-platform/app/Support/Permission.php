<?php

namespace App\Support;

enum Permission: string
{
    case InboxView = 'inbox.view';
    case InboxReply = 'inbox.reply';
    case InboxSimulate = 'inbox.simulate';
    case InboxHandoff = 'inbox.handoff';
    case ContactsView = 'contacts.view';
    case ContactsManage = 'contacts.manage';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case KnowledgeView = 'knowledge.view';
    case KnowledgeManage = 'knowledge.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case AgentsView = 'agents.view';
    case AgentsManage = 'agents.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case TeamManage = 'team.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function catalog(): array
    {
        return [
            'Inbox' => [
                ['value' => self::InboxView->value, 'label' => 'View inbox'],
                ['value' => self::InboxReply->value, 'label' => 'Reply as the shop'],
                ['value' => self::InboxSimulate->value, 'label' => 'Send test customer messages'],
                ['value' => self::InboxHandoff->value, 'label' => 'Pause / resume AI'],
            ],
            'Contacts' => [
                ['value' => self::ContactsView->value, 'label' => 'View contacts'],
                ['value' => self::ContactsManage->value, 'label' => 'Manage contacts'],
            ],
            'Products' => [
                ['value' => self::ProductsView->value, 'label' => 'View products'],
                ['value' => self::ProductsManage->value, 'label' => 'Manage products'],
            ],
            'Delivery' => [
                ['value' => self::KnowledgeView->value, 'label' => 'View delivery'],
                ['value' => self::KnowledgeManage->value, 'label' => 'Manage delivery'],
            ],
            'Orders' => [
                ['value' => self::OrdersView->value, 'label' => 'View orders'],
                ['value' => self::OrdersManage->value, 'label' => 'Manage orders'],
            ],
            'AI agent' => [
                ['value' => self::AgentsView->value, 'label' => 'View agent'],
                ['value' => self::AgentsManage->value, 'label' => 'Edit agent'],
            ],
            'Settings' => [
                ['value' => self::SettingsView->value, 'label' => 'View settings'],
                ['value' => self::SettingsManage->value, 'label' => 'Edit settings'],
            ],
            'Team' => [
                ['value' => self::TeamManage->value, 'label' => 'Manage staff'],
            ],
        ];
    }
}
