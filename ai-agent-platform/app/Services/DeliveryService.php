<?php

namespace App\Services;

use App\Models\Business;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Wilaya;
use App\Support\CurrentBusiness;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class DeliveryService
{
    /**
     * @return array{wilaya: string, code: string, fee: float, currency: string, days: ?string, zone: ?string}|null
     */
    public function lookup(Business $business, string $wilaya, ?Product $product = null): ?array
    {
        if ($product?->isDigital()) {
            $resolved = $this->resolveWilaya($wilaya);

            return [
                'wilaya' => $resolved?->name_fr ?? trim($wilaya),
                'code' => $resolved?->code ?? '',
                'fee' => 0.0,
                'currency' => 'DZD',
                'days' => null,
                'zone' => null,
                'digital' => true,
            ];
        }

        $resolved = $this->resolveWilaya($wilaya);
        if (! $resolved) {
            return null;
        }

        $zones = $this->zonesForWilaya($business, $resolved);
        if ($product && $product->deliveryZones()->exists()) {
            $allowed = $product->deliveryZones()->pluck('delivery_zones.id');
            $zones = $zones->whereIn('id', $allowed);
        }

        $zone = $zones->sortBy('fee')->first();
        if (! $zone) {
            return null;
        }

        return [
            'wilaya' => $resolved->name_fr,
            'code' => $resolved->code,
            'fee' => (float) $zone->fee,
            'currency' => 'DZD',
            'days' => $zone->days,
            'zone' => $zone->name,
        ];
    }

    /**
     * @return Collection<int, DeliveryZone>
     */
    public function list(?Business $business = null): Collection
    {
        $business ??= CurrentBusiness::require();

        return DeliveryZone::query()
            ->forBusiness($business->id)
            ->with('wilayas')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?Business $business = null): DeliveryZone
    {
        $business ??= CurrentBusiness::require();
        $ids = $this->normalizeWilayaIds($data['wilaya_ids'] ?? []);
        if ($ids === []) {
            throw new RuntimeException('Pick at least one wilaya.');
        }

        $zone = isset($data['id'])
            ? DeliveryZone::query()->forBusiness($business->id)->findOrFail($data['id'])
            : new DeliveryZone(['business_id' => $business->id]);

        $zone->fill([
            'name' => trim((string) ($data['name'] ?? '')),
            'fee' => $data['fee'],
            'days' => $data['days'] ?? null,
        ]);
        if ($zone->name === '') {
            throw new RuntimeException('Zone name is required.');
        }
        $zone->save();
        $zone->wilayas()->sync($ids);

        return $zone->load('wilayas');
    }

    public function delete(int $id, ?Business $business = null): void
    {
        $business ??= CurrentBusiness::require();
        DeliveryZone::query()->forBusiness($business->id)->whereKey($id)->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wilayas(): array
    {
        return Wilaya::query()
            ->orderBy('code')
            ->get()
            ->map(fn (Wilaya $wilaya) => [
                'id' => $wilaya->id,
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(DeliveryZone $zone): array
    {
        $zone->loadMissing('wilayas');

        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'fee' => (float) $zone->fee,
            'days' => $zone->days,
            'wilaya_ids' => $zone->wilayas->pluck('id')->all(),
            'wilayas' => $zone->wilayas->map(fn (Wilaya $wilaya) => [
                'id' => $wilaya->id,
                'code' => $wilaya->code,
                'name_fr' => $wilaya->name_fr,
                'name_ar' => $wilaya->name_ar,
            ])->all(),
        ];
    }

    public function resolveWilaya(string $value): ?Wilaya
    {
        $needle = trim($value);
        if ($needle === '') {
            return null;
        }

        $code = str_pad($needle, 2, '0', STR_PAD_LEFT);
        $byCode = Wilaya::query()->where('code', $code)->first();
        if ($byCode) {
            return $byCode;
        }

        $folded = $this->fold($needle);

        return Wilaya::query()->get()->first(function (Wilaya $wilaya) use ($folded, $needle) {
            return $this->fold($wilaya->name_fr) === $folded
                || $this->fold($wilaya->name_ar) === $folded
                || mb_strtolower($wilaya->name_fr) === mb_strtolower($needle);
        });
    }

    /**
     * @return Collection<int, DeliveryZone>
     */
    private function zonesForWilaya(Business $business, Wilaya $wilaya): Collection
    {
        return DeliveryZone::query()
            ->forBusiness($business->id)
            ->whereHas('wilayas', fn ($q) => $q->where('wilayas.id', $wilaya->id))
            ->get();
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function normalizeWilayaIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function fold(string $value): string
    {
        $value = Str::lower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $ascii !== false ? strtolower((string) $ascii) : $value;
    }
}
