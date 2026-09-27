<?php

namespace App\Services;

use App\AI\Rules\AgentRuleEngine;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private DeliveryService $delivery,
        private AgentRuleEngine $rules,
        private PaymentMethodService $payments,
    ) {}

    public function get(Business $business, string $orderNumber): ?array
    {
        $order = Order::query()
            ->forBusiness($business->id)
            ->with('items')
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order && ctype_digit($orderNumber)) {
            $order = Order::query()->forBusiness($business->id)->with('items')->find($orderNumber);
        }

        return $order ? $this->toToolArray($order) : null;
    }

    public function latestForCustomer(Business $business, int $customerId): ?array
    {
        $order = Order::query()
            ->forBusiness($business->id)
            ->with('items')
            ->where('customer_id', $customerId)
            ->latest()
            ->first();

        return $order ? $this->toToolArray($order) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Business $business, Customer $customer, array $payload): array
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $product = $productId > 0
            ? Product::query()->forBusiness($business->id)->with(['variants', 'deliveryZones', 'digitalAsset'])->find($productId)
            : null;

        $productName = $this->cleanString($payload['product_name'] ?? null)
            ?? $this->cleanString($payload['offer_title'] ?? null);
        $manualUnitPrice = array_key_exists('unit_price', $payload) && is_numeric($payload['unit_price'])
            ? (float) $payload['unit_price']
            : null;

        if (! $product && ($productName === null || $manualUnitPrice === null)) {
            return [
                'ok' => false,
                'error' => 'Pass product_id for catalog items, or product_name + unit_price for post/manual offers.',
                'missing' => $productName === null ? 'product_name' : 'unit_price',
            ];
        }

        $digitalFulfillment = $this->normalizeDigitalFulfillment($payload);
        $isDigital = $this->resolveIsDigital($product, $payload, $digitalFulfillment);

        $phone = $this->cleanString($payload['phone'] ?? null) ?? $this->cleanString($customer->phone);
        if ($phone === null) {
            return [
                'ok' => false,
                'error' => "Save the client's phone with update_customer first.",
                'missing' => 'phone',
            ];
        }

        // Digital / top-up / post offers: never require or store shipping fields.
        if ($isDigital) {
            $wilaya = null;
            $commune = null;
            $address = null;
            $deliveryType = null;
        } else {
            $wilaya = $this->cleanString($payload['wilaya'] ?? null) ?? $this->cleanString($customer->wilaya);
            $commune = $this->cleanString($payload['commune'] ?? null) ?? $this->cleanString($customer->commune);
            $address = $this->cleanString($payload['address'] ?? null);
            $deliveryType = strtolower((string) ($this->cleanString($payload['delivery_type'] ?? null) ?? ''));

            if ($wilaya === null) {
                return [
                    'ok' => false,
                    'error' => "Save the client's wilaya with update_customer first.",
                    'missing' => 'wilaya',
                ];
            }
            if (! in_array($deliveryType, ['home', 'stopdesk'], true)) {
                return [
                    'ok' => false,
                    'error' => 'Ask the client for delivery type: home or stopdesk.',
                    'missing' => 'delivery_type',
                ];
            }
        }

        if ($isDigital && $product) {
            $requiredFields = $this->fulfillmentFieldsFromProduct($product);
            foreach ($requiredFields as $field) {
                $value = $digitalFulfillment[$field] ?? null;
                if ($value === null || $value === '') {
                    return [
                        'ok' => false,
                        'error' => "Ask the client for {$field} before creating the order.",
                        'missing' => $field,
                    ];
                }
            }
        }

        if ($product && $product->variants->isNotEmpty() && empty($payload['variant_id'])) {
            return [
                'ok' => false,
                'error' => 'This product has variants. Pass variant_id for the one the client chose.',
                'missing' => 'variant_id',
            ];
        }

        $conversationId = $this->resolveConversationId($business, $customer, $payload['conversation_id'] ?? null);
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $variantId = ! empty($payload['variant_id']) ? (int) $payload['variant_id'] : null;

        $metadata = array_filter([
            'digital_fulfillment' => $digitalFulfillment !== [] ? $digitalFulfillment : null,
            'offer_source' => $this->cleanString($payload['offer_source'] ?? null),
            'payment_method' => $this->cleanString($payload['payment_method'] ?? null),
            'notes' => $this->cleanString($payload['notes'] ?? null),
            'post_offer' => $product ? null : true,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $aiNotes = $this->resolveAiNotes($payload, $digitalFulfillment, $wilaya, $commune, $address);

        if ($product) {
            return $this->createCatalogOrder(
                $business,
                $customer,
                $product,
                $quantity,
                $variantId,
                $phone,
                $wilaya,
                $commune,
                $address,
                $deliveryType,
                $conversationId,
                $metadata,
                $aiNotes,
            );
        }

        return $this->createManualOrder(
            $business,
            $customer,
            $productName,
            $manualUnitPrice,
            $quantity,
            $phone,
            $wilaya,
            $commune,
            $address,
            $deliveryType,
            $conversationId,
            $metadata,
            $isDigital,
            $aiNotes,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function createCatalogOrder(
        Business $business,
        Customer $customer,
        Product $product,
        int $quantity,
        ?int $variantId,
        string $phone,
        ?string $wilaya,
        ?string $commune,
        ?string $address,
        ?string $deliveryType,
        ?int $conversationId,
        array $metadata,
        ?string $aiNotes = null,
    ): array {
        return DB::transaction(function () use (
            $business,
            $customer,
            $product,
            $quantity,
            $variantId,
            $phone,
            $wilaya,
            $commune,
            $address,
            $deliveryType,
            $conversationId,
            $metadata,
            $aiNotes,
        ) {
            $lockedProduct = Product::query()->forBusiness($business->id)->lockForUpdate()->find($product->id);
            if (! $lockedProduct) {
                return ['ok' => false, 'error' => 'Product not found.'];
            }

            $variant = null;
            if ($variantId) {
                $variant = ProductVariant::query()->where('product_id', $lockedProduct->id)->whereKey($variantId)->lockForUpdate()->first();
                if (! $variant) {
                    return ['ok' => false, 'error' => 'Variant not found.'];
                }
            }

            $stock = $variant ? (int) $variant->stock : (int) $lockedProduct->stock;
            if ($stock < $quantity) {
                return ['ok' => false, 'error' => 'Not enough stock.', 'stock' => $stock];
            }

            $unit = $variant && $variant->price !== null ? (float) $variant->price : (float) $lockedProduct->price;
            $subtotal = $unit * $quantity;
            $delivery = ($wilaya && ! $lockedProduct->isDigital())
                ? $this->delivery->lookup($business, $wilaya, $lockedProduct)
                : ($lockedProduct->isDigital() ? ['fee' => 0] : null);
            $deliveryFee = $delivery['fee'] ?? 0;
            $total = $subtotal + $deliveryFee;

            if ($this->rules->requiresApprovalForTotal($business, $total)) {
                return [
                    'ok' => false,
                    'needs_approval' => true,
                    'error' => 'Order total is above the shop limit. A human must confirm it.',
                    'total' => $total,
                ];
            }

            $order = Order::query()->create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'conversation_id' => $conversationId,
                'order_number' => 'ORD-'.now()->format('Y').'-'.Str::upper(Str::random(5)),
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => 0,
                'total' => $total,
                'currency' => $business->currency ?: 'DZD',
                'wilaya' => $wilaya,
                'commune' => $commune,
                'address' => $address,
                'phone' => $phone,
                'delivery_type' => $deliveryType,
                'source' => 'agent',
                'ai_notes' => $aiNotes,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);

            $order->items()->create([
                'product_id' => $lockedProduct->id,
                'variant_id' => $variant?->id,
                'product_name' => $variant ? $lockedProduct->name.' · '.$variant->name : $lockedProduct->name,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'total' => $subtotal,
            ]);

            if ($variant) {
                $variant->decrement('stock', $quantity);
            } else {
                $lockedProduct->decrement('stock', $quantity);
            }

            $this->markCustomerConverted($customer, $phone, $wilaya, $commune);

            return $this->successPayload($business, $order->load('items'));
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function createManualOrder(
        Business $business,
        Customer $customer,
        string $productName,
        float $unitPrice,
        int $quantity,
        string $phone,
        ?string $wilaya,
        ?string $commune,
        ?string $address,
        ?string $deliveryType,
        ?int $conversationId,
        array $metadata,
        bool $isDigital,
        ?string $aiNotes = null,
    ): array {
        return DB::transaction(function () use (
            $business,
            $customer,
            $productName,
            $unitPrice,
            $quantity,
            $phone,
            $wilaya,
            $commune,
            $address,
            $deliveryType,
            $conversationId,
            $metadata,
            $isDigital,
            $aiNotes,
        ) {
            $subtotal = $unitPrice * $quantity;
            $deliveryFee = 0.0;
            if (! $isDigital && $wilaya) {
                $delivery = $this->delivery->lookup($business, $wilaya, null);
                $deliveryFee = (float) ($delivery['fee'] ?? 0);
            }
            $total = $subtotal + $deliveryFee;

            if ($this->rules->requiresApprovalForTotal($business, $total)) {
                return [
                    'ok' => false,
                    'needs_approval' => true,
                    'error' => 'Order total is above the shop limit. A human must confirm it.',
                    'total' => $total,
                ];
            }

            $order = Order::query()->create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'conversation_id' => $conversationId,
                'order_number' => 'ORD-'.now()->format('Y').'-'.Str::upper(Str::random(5)),
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => 0,
                'total' => $total,
                'currency' => $business->currency ?: 'DZD',
                'wilaya' => $wilaya,
                'commune' => $commune,
                'address' => $address,
                'phone' => $phone,
                'delivery_type' => $deliveryType,
                'source' => 'agent',
                'ai_notes' => $aiNotes,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);

            $order->items()->create([
                'product_id' => null,
                'variant_id' => null,
                'product_name' => $productName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $subtotal,
            ]);

            $this->markCustomerConverted($customer, $phone, $wilaya, $commune);

            return $this->successPayload($business, $order->load('items'));
        });
    }

    /**
     * Short owner-facing notes the agent should leave on the order (game ID, zone, address…).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $digitalFulfillment
     */
    private function resolveAiNotes(
        array $payload,
        array $digitalFulfillment,
        ?string $wilaya,
        ?string $commune,
        ?string $address,
    ): ?string {
        $explicit = $this->cleanString($payload['ai_notes'] ?? $payload['notes'] ?? null);
        if ($explicit !== null) {
            return mb_substr($explicit, 0, 2000);
        }

        $parts = [];
        foreach ($digitalFulfillment as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $trimmed = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
            if ($trimmed === '') {
                continue;
            }
            $parts[] = $key.': '.$trimmed;
        }
        foreach (array_filter([
            $wilaya ? 'wilaya: '.$wilaya : null,
            $commune ? 'commune: '.$commune : null,
            $address ? 'address: '.$address : null,
        ]) as $line) {
            $parts[] = $line;
        }

        if ($parts === []) {
            return null;
        }

        return mb_substr(implode(' · ', $parts), 0, 2000);
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(Business $business, Order $order): array
    {
        $paymentMethods = $this->payments->listForAgent($business);

        try {
            app(\App\Services\Telegram\TelegramMerchantNotifier::class)->orderCreated($business, $order);
        } catch (\Throwable) {
            // never break order create
        }

        return [
            'ok' => true,
            'order' => $this->toToolArray($order),
            'payment_methods' => $paymentMethods,
            'payment_recommended' => $paymentMethods[0] ?? null,
            'next_step' => $paymentMethods === []
                ? 'Give the order number. Payment methods are not configured for this shop.'
                : 'Give the order number, recommend priority-1 payment details, then a soft pay-and-we-process-ASAP closer. Never ask for receipt attachments.',
        ];
    }

    private function markCustomerConverted(Customer $customer, string $phone, ?string $wilaya, ?string $commune): void
    {
        $customer->fill(array_filter([
            'phone' => $phone,
            'wilaya' => $wilaya,
            'commune' => $commune,
        ], fn ($value) => $value !== null && $value !== ''));
        $customer->lifecycle = 'customer';
        $customer->lead_status = 'converted';
        $customer->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $digitalFulfillment
     */
    private function resolveIsDigital(?Product $product, array $payload, array $digitalFulfillment): bool
    {
        if ($product?->isDigital()) {
            return true;
        }

        $explicit = strtolower((string) ($payload['product_type'] ?? ''));
        if ($explicit === 'digital') {
            return true;
        }
        if ($explicit === 'physical') {
            return false;
        }

        // Post/manual offers default digital; shipping only when catalog product is physical.
        if (! $product) {
            return true;
        }

        // Catalog physical product — never treat as digital just because fulfillment keys were sent.
        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeDigitalFulfillment(array $payload): array
    {
        $raw = $payload['digital_fulfillment'] ?? null;
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (is_string($value) || is_numeric($value)) {
                    $trimmed = trim((string) $value);
                    if ($trimmed !== '') {
                        $out[$key] = $trimmed;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fulfillmentFieldsFromProduct(Product $product): array
    {
        $meta = is_array($product->metadata) ? $product->metadata : [];
        $fields = $meta['fulfillment_fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $clean = [];
        foreach ($fields as $field) {
            if (is_string($field) && trim($field) !== '') {
                $clean[] = trim($field);
            }
        }

        return array_values(array_unique($clean));
    }

    public function cancel(Business $business, string $orderNumber): array
    {
        return DB::transaction(function () use ($business, $orderNumber) {
            $order = Order::query()->forBusiness($business->id)->with('items')->where('order_number', $orderNumber)->lockForUpdate()->first();
            if (! $order) {
                return ['ok' => false, 'error' => 'Order not found.'];
            }

            if (in_array($order->status, ['shipped', 'delivered', 'cancelled'], true)) {
                return ['ok' => false, 'error' => 'This order cannot be cancelled.', 'status' => $order->status];
            }

            $order->update(['status' => 'cancelled']);

            foreach ($order->items as $item) {
                if ($item->variant_id) {
                    ProductVariant::query()->whereKey($item->variant_id)->increment('stock', $item->quantity);
                } elseif ($item->product_id) {
                    Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
                }
            }

            return ['ok' => true, 'order' => $this->toToolArray($order->fresh('items'))];
        });
    }

    /**
     * Shop-owner status change (Orders dashboard). Authoritative fulfillment signal for the agent.
     *
     * @return array<string, mixed>
     */
    public function updateStatus(Business $business, int $orderId, string $status): array
    {
        $status = strtolower(trim($status));
        if (! in_array($status, ['pending', 'shipped', 'delivered', 'cancelled'], true)) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }

        $order = Order::query()->forBusiness($business->id)->with('items')->find($orderId);
        if (! $order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }

        if ($status === 'cancelled') {
            if (in_array($order->status, ['shipped', 'delivered'], true)) {
                return ['ok' => false, 'error' => 'Shipped or delivered orders cannot be cancelled.', 'status' => $order->status];
            }
            if ($order->status !== 'cancelled') {
                $cancelled = $this->cancel($business, (string) $order->order_number);
                if (empty($cancelled['ok'])) {
                    return $cancelled;
                }
                $fresh = Order::query()
                    ->forBusiness($business->id)
                    ->with(['items', 'customer.socialProfiles', 'conversation.socialAccount'])
                    ->find($orderId);

                return ['ok' => true, 'order' => $this->toArray($fresh)];
            }

            return ['ok' => true, 'order' => $this->toArray($order->loadMissing(['items', 'customer.socialProfiles', 'conversation.socialAccount']))];
        }

        $order->update(['status' => $status]);

        return [
            'ok' => true,
            'order' => $this->toArray($order->fresh(['items', 'customer.socialProfiles', 'conversation.socialAccount'])),
        ];
    }

    /**
     * @return array{orders: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(?string $search = null, ?string $status = null, ?Business $business = null): array
    {
        $business ??= CurrentBusiness::require();
        $query = Order::query()
            ->forBusiness($business->id)
            ->with([
                'items',
                'conversation.socialAccount',
                'customer.socialProfiles',
                'customer.conversations' => function ($q) {
                    $q->with('socialAccount')->orderByDesc('last_message_at')->orderByDesc('id');
                },
            ]);

        if ($status && in_array($status, ['pending', 'cancelled', 'shipped', 'delivered'], true)) {
            $query->where('status', $status);
        }

        $term = is_string($search) ? trim($search) : '';
        if ($term !== '') {
            $query->where(function ($inner) use ($term) {
                $inner->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%')
                    ->orWhere('wilaya', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', function ($customer) use ($term) {
                        $customer->where('name', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%');
                    });
            });
        }

        $orders = $query->latest()->limit(200)->get();
        $base = Order::query()->forBusiness($business->id);

        return [
            'orders' => $orders->map(fn (Order $order) => $this->toArray($order))->all(),
            'counts' => [
                'all' => (clone $base)->count(),
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'shipped' => (clone $base)->where('status', 'shipped')->count(),
                'delivered' => (clone $base)->where('status', 'delivered')->count(),
                'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Order $order): array
    {
        $customer = $order->customer;
        $conversation = $order->conversation;
        if (! $conversation && $customer) {
            $conversation = $customer->conversations->first();
        }
        $profile = $customer?->socialProfiles->first();
        $account = $conversation?->socialAccount;
        $deliveryLabel = match ($order->delivery_type) {
            'home' => 'Home',
            'stopdesk' => 'Stop desk',
            default => null,
        };

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'currency' => $order->currency,
            'wilaya' => $order->wilaya,
            'commune' => $order->commune,
            'address' => $order->address,
            'phone' => $order->phone ?: $customer?->phone,
            'delivery_type' => $order->delivery_type,
            'delivery_label' => $deliveryLabel,
            'ai_notes' => $order->ai_notes,
            'name' => $customer?->name ?: ($profile?->username ?: 'Customer'),
            'avatar_url' => $profile?->avatar_url,
            'platform' => $conversation?->platform ?: ($profile?->platform ?: null),
            'account_name' => $account?->name,
            'conversation_id' => $conversation?->id,
            'conversation_status' => $conversation?->status,
            'placed_at' => $order->created_at?->diffForHumans(),
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->all(),
        ];
    }

    public function toToolArray(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'currency' => $order->currency,
            'wilaya' => $order->wilaya,
            'commune' => $order->commune,
            'address' => $order->address,
            'phone' => $order->phone,
            'delivery_type' => $order->delivery_type,
            'carrier' => $order->carrier,
            'tracking' => $order->tracking,
            'ai_notes' => $order->ai_notes,
            'metadata' => $order->metadata,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->all(),
        ];
    }

    private function resolveConversationId(Business $business, Customer $customer, mixed $conversationId): ?int
    {
        if (! is_numeric($conversationId)) {
            return null;
        }

        $conversation = Conversation::query()
            ->forBusiness($business->id)
            ->where('customer_id', $customer->id)
            ->find((int) $conversationId);

        return $conversation?->id;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
