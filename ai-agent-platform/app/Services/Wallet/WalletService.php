<?php

namespace App\Services\Wallet;

use App\Exceptions\InsufficientWalletException;
use App\Exceptions\WalletOwnerMissingException;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    public function usdToDaRate(): int
    {
        return (int) config('billing.usd_to_da', 250);
    }

    public function ownerForBusiness(Business $business): User
    {
        $membership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('role', 'owner')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->first();

        if (! $membership) {
            throw new WalletOwnerMissingException;
        }

        $owner = User::query()->find($membership->user_id);
        if (! $owner) {
            throw new WalletOwnerMissingException;
        }

        return $owner;
    }

    public function balance(User $owner): float
    {
        return round((float) $owner->wallet_balance_da, 2);
    }

    /**
     * Convert USD to DA.
     * - ceil: whole DA (catalog / image list prices)
     * - round: 0.01 DA (live Fal usage — avoids 1 DA on tiny costs)
     */
    public function usdToDa(float $usd, string $mode = 'ceil'): float
    {
        if ($usd <= 0) {
            return 0.0;
        }

        $raw = $usd * $this->usdToDaRate();
        if ($mode === 'round') {
            $da = round($raw, 2);
            $min = (float) config('billing.min_charge_da', 0.01);

            return $da > 0 ? $da : $min;
        }

        return (float) (int) ceil($raw);
    }

    public function ensureCanAfford(User $owner, float $amountDa): void
    {
        $amountDa = $this->normalizeAmount($amountDa);
        $available = $this->balance($owner);
        if ($available + 1e-9 < $amountDa) {
            throw new InsufficientWalletException($amountDa, $available);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function debit(
        User $owner,
        float $amountDa,
        string $reason,
        ?Business $business = null,
        ?User $actor = null,
        ?float $usdAmount = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
    ): WalletLedger {
        $amountDa = $this->normalizeAmount($amountDa);
        if ($amountDa <= 0) {
            throw new InvalidArgumentException('Debit amount must be positive.');
        }

        $meta = array_merge(['currency' => 'DZD'], $meta);

        return DB::transaction(function () use ($owner, $amountDa, $reason, $business, $actor, $usdAmount, $referenceType, $referenceId, $meta) {
            /** @var User $locked */
            $locked = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $available = round((float) $locked->wallet_balance_da, 2);
            if ($available + 1e-9 < $amountDa) {
                throw new InsufficientWalletException($amountDa, $available);
            }

            $locked->wallet_balance_da = round($available - $amountDa, 2);
            $locked->wallet_currency = 'DZD';
            $locked->save();

            return WalletLedger::query()->create([
                'user_id' => $locked->id,
                'business_id' => $business?->id,
                'actor_user_id' => $actor?->id,
                'direction' => WalletLedger::DIRECTION_DEBIT,
                'amount_da' => $amountDa,
                'usd_amount' => $usdAmount,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'meta' => $meta ?: null,
                'balance_after' => (float) $locked->wallet_balance_da,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function credit(
        User $owner,
        float $amountDa,
        string $reason = 'topup',
        ?Business $business = null,
        ?User $actor = null,
        ?float $usdAmount = null,
        array $meta = [],
    ): WalletLedger {
        $amountDa = $this->normalizeAmount($amountDa);
        if ($amountDa <= 0) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        $meta = array_merge(['currency' => 'DZD'], $meta);
        $rate = $this->usdToDaRate();

        return DB::transaction(function () use ($owner, $amountDa, $reason, $business, $actor, $usdAmount, $meta, $rate) {
            /** @var User $locked */
            $locked = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $locked->wallet_balance_da = round((float) $locked->wallet_balance_da + $amountDa, 2);
            $locked->wallet_currency = 'DZD';
            $locked->save();

            return WalletLedger::query()->create([
                'user_id' => $locked->id,
                'business_id' => $business?->id,
                'actor_user_id' => $actor?->id,
                'direction' => WalletLedger::DIRECTION_CREDIT,
                'amount_da' => $amountDa,
                'usd_amount' => $usdAmount ?? round($amountDa / max(1, $rate), 4),
                'reason' => $reason,
                'meta' => $meta ?: null,
                'balance_after' => (float) $locked->wallet_balance_da,
            ]);
        });
    }

    /**
     * Debit the shop owner from USD (0 markup).
     *
     * @param  array<string, mixed>  $meta
     */
    public function chargeFromUsd(
        Business $business,
        float $usd,
        string $reason,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
        string $daMode = 'ceil',
    ): WalletLedger {
        $usd = round($usd, 8);
        if ($usd <= 0) {
            throw new InvalidArgumentException('USD cost must be positive.');
        }

        $da = $this->usdToDa($usd, $daMode);
        $meta = array_merge([
            'cost_usd' => $usd,
            'cost_da' => $da,
            'usd_to_da' => $this->usdToDaRate(),
            'da_mode' => $daMode,
            'markup' => 0,
        ], $meta);

        return $this->chargeOwnerDa(
            $business,
            $da,
            $usd,
            $reason,
            $actor,
            $referenceType,
            $referenceId,
            $meta,
        );
    }

    /**
     * Debit the shop owner a DA amount (already converted).
     *
     * @param  array<string, mixed>  $meta
     */
    public function chargeOwnerDa(
        Business $business,
        float $amountDa,
        ?float $costUsd,
        string $reason,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $meta = [],
    ): WalletLedger {
        $owner = $this->ownerForBusiness($business);

        return $this->debit(
            $owner,
            $amountDa,
            $reason,
            $business,
            $actor,
            $costUsd,
            $referenceType,
            $referenceId,
            $meta,
        );
    }

    public function authorizeBusinessAi(Business $business, float $amountDa): User
    {
        $owner = $this->ownerForBusiness($business);
        $this->ensureCanAfford($owner, $amountDa);

        return $owner;
    }

    /**
     * @return array{balance_da: float, balance: float, owner_user_id: int, is_owner: bool, currency: string, usd_to_da: int}
     */
    public function snapshotForBusiness(Business $business, ?User $viewer = null): array
    {
        $owner = $this->ownerForBusiness($business);
        $balance = $this->balance($owner);

        return [
            'balance_da' => $balance,
            'balance' => $balance,
            'owner_user_id' => $owner->id,
            'is_owner' => $viewer ? $viewer->id === $owner->id : false,
            'currency' => 'DZD',
            'usd_to_da' => $this->usdToDaRate(),
        ];
    }

    private function normalizeAmount(float $amount): float
    {
        return round(max(0, $amount), 2);
    }
}
