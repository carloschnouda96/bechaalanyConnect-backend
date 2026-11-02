<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OrderController;
use App\Models\User;
use App\Order;
use App\Product;
use App\ProductsVariation;
use App\Services\Orders\OrderPlacement;
use App\Services\Orders\OrderPlacementException;
use App\Services\Reseller\OrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The reseller API (`/api/v1/*`): other stores buy from BechaalanyConnect with an
 * API key. Documented in public/openapi/reseller-v1.yaml (served at /api-docs).
 *
 * A reseller is an ordinary customer: KYC-approved, spending the same credits
 * balance through the same CreditLedger, priced at their user type's tier.
 * Orders go through OrderPlacement — the exact rules the storefront uses — with
 * two differences: a supplier-fulfilled product is approved and dispatched at
 * once, and `client_order_id` makes a retried POST safe.
 *
 * Every response is built field by field here (orders by OrderPresenter, shared
 * with the webhooks) rather than by serialising a model, so cost, profit and
 * supplier fields can never leak to a reseller.
 */
class ResellerApiController extends Controller
{
    private const PRODUCT_TYPES = [
        Product::TYPE_USER_ID => 'user_id',
        Product::TYPE_CODE => 'code',
        Product::TYPE_PHONE => 'phone',
        Product::TYPE_COIN => 'coin',
        Product::TYPE_SOCIAL => 'social',
    ];

    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'balance' => round((float) $user->credits_balance, 2),
                'currency' => 'USD',
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $user = $request->user();

        $products = Product::listable()
            ->with(['variations' => fn ($q) => $q->sellable()->orderBy('ht_pos')])
            ->orderBy('ht_pos')
            ->get();

        return response()->json([
            'data' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->subcategory?->category?->title,
                'subcategory' => $product->subcategory?->title,
                'type' => self::PRODUCT_TYPES[(int) $product->product_type_id] ?? 'code',
                'image' => $this->imageUrl($product),
                'variations' => $product->variations
                    ->map(fn (ProductsVariation $v) => $this->presentVariation($v->setRelation('product', $product), $user))
                    ->values(),
            ])->values(),
        ]);
    }

    public function product(Request $request, $variationId): JsonResponse
    {
        $variation = ProductsVariation::sellable()->with('product')->find((int) $variationId);

        if (!$variation) {
            return $this->error('not_found', 'Variation not found or not for sale.', 404);
        }

        return response()->json(['data' => $this->presentVariation($variation, $request->user())]);
    }

    public function placeOrder(Request $request, OrderPlacement $placement): JsonResponse
    {
        try {
            $data = $request->validate([
                'variation_id' => ['required', 'integer'],
                'quantity' => ['required', 'integer', 'min:1', 'max:' . OrderController::MAX_COINS],
                'recipient_user' => ['nullable', 'string', 'max:255'],
                'recipient_phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
                // Required here (optional on the storefront): an integration that
                // retries on a timeout must be able to do so without paying twice.
                'client_order_id' => ['required', 'string', 'max:191'],
            ]);
        } catch (ValidationException $e) {
            return $this->error('validation_failed', $e->getMessage(), 422, ['fields' => $e->errors()]);
        }

        try {
            $placed = $placement->place($request->user(), [
                'product_variation_id' => $data['variation_id'],
                'quantity' => $data['quantity'],
                'recipient_user' => $data['recipient_user'] ?? null,
                'recipient_phone_number' => $data['recipient_phone_number'] ?? null,
            ], [
                'source' => 'api',
                'client_order_id' => $data['client_order_id'],
                'auto_approve_supplier' => true,
            ]);
        } catch (OrderPlacementException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->extra);
        }

        // 201 for a new order, 200 for a replay of the same client_order_id — the
        // body is the order either way.
        return response()->json(['data' => OrderPresenter::present($placed->order)], $placed->created ? 201 : 200);
    }

    public function order(Request $request, $id): JsonResponse
    {
        $order = $this->ownOrders($request->user())->where('id', (int) $id)->first();

        return $order
            ? response()->json(['data' => OrderPresenter::present($order)])
            : $this->error('not_found', 'Order not found.', 404);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = $this->ownOrders($request->user())->orderByDesc('id');

        if ($request->filled('client_order_id')) {
            $query->where('client_order_id', (string) $request->query('client_order_id'));
        }

        if ($request->filled('status')) {
            $statusId = array_search($request->query('status'), OrderPresenter::statusNames(), true);
            $query->where('statuses_id', $statusId === false ? 0 : $statusId);
        }

        $page = $query->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $o) => OrderPresenter::present($o))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function ownOrders(User $user)
    {
        return Order::where('users_id', $user->id)->with('product_variation.product');
    }

    private function presentVariation(ProductsVariation $variation, User $user): array
    {
        $type = (int) ($variation->product->product_type_id ?? 0);
        $prorated = $variation->isProrated();

        return [
            'id' => $variation->id,
            'product_id' => $variation->product_id,
            'product_name' => $variation->product?->name,
            'name' => $variation->name,
            'type' => self::PRODUCT_TYPES[$type] ?? 'code',
            'description' => $variation->description ? trim(strip_tags($variation->description)) : null,
            // The caller's own price: their tier's when one is set, else public.
            // For coin / social products it is per `pricing_unit` units.
            'price' => round(OrderPlacement::unitPriceFor($variation, $user), 4),
            'pricing_unit' => $prorated ? $variation->pricingUnit() : 1,
            'unit_label' => $type === Product::TYPE_COIN ? $variation->unit_label : null,
            'min_quantity' => $variation->minOrderQuantity(),
            'max_quantity' => $variation->maxOrderQuantity(),
            'required_fields' => match ($type) {
                Product::TYPE_PHONE => ['recipient_phone_number'],
                Product::TYPE_USER_ID, Product::TYPE_COIN, Product::TYPE_SOCIAL => ['recipient_user'],
                default => [],
            },
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        $path = $product->full_path ?? null;
        if (is_array($path) || is_object($path)) {
            $path = data_get($path, 'image') ?? data_get($path, 'url');
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
