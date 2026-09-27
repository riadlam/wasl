<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessPaymentMethod;
use App\Support\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentMethodService
{
    public const LABELS = [
        BusinessPaymentMethod::METHOD_FLEXY => 'Flexy',
        BusinessPaymentMethod::METHOD_BARIDIMOB => 'BaridiMob',
        BusinessPaymentMethod::METHOD_CCP => 'CCP',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUi(?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $this->ensureDefaults($business);

        return BusinessPaymentMethod::query()
            ->forBusiness($business->id)
            ->orderByRaw('CASE method WHEN \'flexy\' THEN 1 WHEN \'baridimob\' THEN 2 ELSE 3 END')
            ->get()
            ->map(fn (BusinessPaymentMethod $row) => $this->toUiArray($row))
            ->all();
    }

    /**
     * Enabled methods only, sorted by priority (for the customer agent).
     *
     * @return list<array<string, mixed>>
     */
    public function listForAgent(?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $this->ensureDefaults($business);

        return BusinessPaymentMethod::query()
            ->forBusiness($business->id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get()
            ->map(fn (BusinessPaymentMethod $row) => $this->toAgentArray($row))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $methods
     * @return list<array<string, mixed>>
     */
    public function saveAll(array $methods, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $this->ensureDefaults($business);

        $byMethod = [];
        foreach ($methods as $row) {
            if (! is_array($row)) {
                continue;
            }
            $method = strtolower(trim((string) ($row['method'] ?? '')));
            if (! in_array($method, BusinessPaymentMethod::METHODS, true)) {
                throw new RuntimeException('Unknown payment method: '.$method);
            }
            $byMethod[$method] = $row;
        }

        foreach (BusinessPaymentMethod::METHODS as $method) {
            if (! isset($byMethod[$method])) {
                throw new RuntimeException('Missing payment method payload: '.$method);
            }
        }

        $enabledPriorities = [];
        foreach (BusinessPaymentMethod::METHODS as $method) {
            $row = $byMethod[$method];
            $enabled = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $enabled) {
                continue;
            }
            $priority = (int) ($row['priority'] ?? 0);
            if ($priority < 1 || $priority > 3) {
                throw new RuntimeException('Enabled methods need a unique priority from 1 to 3.');
            }
            if (isset($enabledPriorities[$priority])) {
                throw new RuntimeException('Each enabled method must have a different priority.');
            }
            $enabledPriorities[$priority] = $method;
            $this->assertFields($method, $row, true);
        }

        if ($enabledPriorities !== [] && count($enabledPriorities) !== max(array_keys($enabledPriorities))) {
            // Priorities should be contiguous 1..N among enabled — soft: allow gaps but require uniqueness (already checked).
        }

        DB::transaction(function () use ($business, $byMethod) {
            foreach (BusinessPaymentMethod::METHODS as $method) {
                $row = $byMethod[$method];
                $enabled = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $model = BusinessPaymentMethod::query()
                    ->forBusiness($business->id)
                    ->where('method', $method)
                    ->firstOrFail();

                $model->fill([
                    'enabled' => $enabled,
                    'priority' => $enabled ? (int) $row['priority'] : null,
                    'phone' => in_array($method, [BusinessPaymentMethod::METHOD_FLEXY, BusinessPaymentMethod::METHOD_BARIDIMOB], true)
                        ? $this->clean($row['phone'] ?? null)
                        : null,
                    'ccp_cle' => $method === BusinessPaymentMethod::METHOD_CCP ? $this->clean($row['ccp_cle'] ?? null) : null,
                    'ccp_number' => $method === BusinessPaymentMethod::METHOD_CCP ? $this->clean($row['ccp_number'] ?? null) : null,
                ]);
                $model->save();
            }
        });

        return $this->listForUi($business);
    }

    public function ensureDefaults(Business $business): void
    {
        foreach (BusinessPaymentMethod::METHODS as $method) {
            BusinessPaymentMethod::query()->firstOrCreate(
                ['business_id' => $business->id, 'method' => $method],
                ['enabled' => false, 'priority' => null]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toUiArray(BusinessPaymentMethod $row): array
    {
        return [
            'method' => $row->method,
            'label' => self::LABELS[$row->method] ?? $row->method,
            'enabled' => (bool) $row->enabled,
            'priority' => $row->priority,
            'phone' => $row->phone,
            'ccp_cle' => $row->ccp_cle,
            'ccp_number' => $row->ccp_number,
            'logo_url' => asset('images/payments/'.$row->method.'.svg'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAgentArray(BusinessPaymentMethod $row): array
    {
        $base = [
            'method' => $row->method,
            'label' => self::LABELS[$row->method] ?? $row->method,
            'priority' => (int) $row->priority,
            'recommended' => (int) $row->priority === 1,
        ];

        return match ($row->method) {
            BusinessPaymentMethod::METHOD_CCP => array_merge($base, [
                'ccp_cle' => $row->ccp_cle,
                'ccp_number' => $row->ccp_number,
            ]),
            default => array_merge($base, [
                'phone' => $row->phone,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertFields(string $method, array $row, bool $enabled): void
    {
        if (! $enabled) {
            return;
        }

        if (in_array($method, [BusinessPaymentMethod::METHOD_FLEXY, BusinessPaymentMethod::METHOD_BARIDIMOB], true)) {
            if ($this->clean($row['phone'] ?? null) === null) {
                throw new RuntimeException(self::LABELS[$method].' needs a phone number when enabled.');
            }
        }

        if ($method === BusinessPaymentMethod::METHOD_CCP) {
            if ($this->clean($row['ccp_cle'] ?? null) === null || $this->clean($row['ccp_number'] ?? null) === null) {
                throw new RuntimeException('CCP needs clé and account number when enabled.');
            }
        }
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
