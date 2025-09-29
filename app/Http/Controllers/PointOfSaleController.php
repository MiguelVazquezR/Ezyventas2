<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PromotionEffectType;
use App\Enums\PromotionType;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Promotion;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PointOfSaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:pos.access', only: ['index']),
            new Middleware('can:pos.create_sale', only: ['checkout']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $search = $request->input('search');
        $categoryId = $request->input('category');

        $activeSession = CashRegisterSession::where('user_id', $user->id)
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->with([
                'cashRegister:id,name',
                'user:id,name',
                'transactions' => function ($query) {
                    $query->with(['payments', 'customer:id,name'])->latest();
                },
                'cashMovements' => function ($query) {
                    $query->latest();
                }
            ])
            ->first();

        if ($activeSession) {
            $paymentTotals = $activeSession->transactions
                ->flatMap->payments
                ->where('status', 'completado')
                ->groupBy('payment_method.value')
                ->map->sum('amount');

            $activeSession->totals = [
                'card' => $paymentTotals['tarjeta'] ?? 0,
                'transfer' => $paymentTotals['transferencia'] ?? 0,
            ];
        }

        $availableCashRegisters = null;
        if (!$activeSession) {
            $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
                ->where('is_active', true)->where('in_use', false)
                ->select('id', 'name')->get();
        }

        // Se obtienen las plantillas de impresión disponibles para la sucursal actual
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('POS/Index', [
            'products' => $this->getProductsData($search, $categoryId),
            'categories' => $this->getCategoriesData(),
            'customers' => $this->getCustomersData(),
            'defaultCustomer' => $this->getDefaultCustomerData(),
            'filters' => $request->only(['search', 'category']),
            'activePromotions' => $this->getActivePromotions(),
            'activeSession' => $activeSession,
            'availableCashRegisters' => $availableCashRegisters,
            'availableTemplates' => $availableTemplates, // Se pasan a la vista
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'cartItems.*.quantity' => 'required|numeric|min:1',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'customerId' => 'nullable|exists:customers,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'required|numeric',
            'total' => 'required|numeric',
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'use_balance' => 'required|boolean',
        ]);

        $user = Auth::user();
        $customer = $validated['customerId'] ? Customer::find($validated['customerId']) : null;
        $totalPaid = collect($validated['payments'])->sum('amount');
        $totalSale = $validated['total'];

        try {
            $transaction = DB::transaction(function () use ($validated, $user, $customer, $totalPaid, $totalSale) {
                $amountFromBalance = 0;
                if ($customer && $validated['use_balance'] && $customer->balance > 0) {
                    $amountFromBalance = min($totalSale, $customer->balance);
                }
                $remainingDue = $totalSale - $totalPaid - $amountFromBalance;

                if (!$customer) {
                    if ($remainingDue > 0.01) {
                        throw new \Exception('El pago debe ser completo para ventas a Público en General.');
                    }
                } else {
                    if ($remainingDue > 0.01 && $remainingDue > $customer->available_credit) {
                        throw new \Exception('El crédito disponible del cliente no es suficiente para cubrir el monto restante.');
                    }
                }

                $newTransaction = Transaction::create([
                    'cash_register_session_id' => $validated['cash_register_session_id'],
                    'folio' => $this->generateFolio(),
                    'customer_id' => $customer?->id,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    'status' => $remainingDue > 0.01 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
                    'channel' => TransactionChannel::POS,
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['total_discount'],
                    'total_tax' => 0,
                    'currency' => 'MXN',
                    'status_changed_at' => now(),
                ]);

                foreach ($validated['cartItems'] as $item) {
                    $itemableId = $item['id'];
                    $itemableType = Product::class;
                    if (!empty($item['product_attribute_id'])) {
                        $itemableId = $item['product_attribute_id'];
                        $itemableType = ProductAttribute::class;
                    }

                    $newTransaction->items()->create([
                        'itemable_id' => $itemableId,
                        'itemable_type' => $itemableType,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['quantity'] * $item['unit_price'],
                    ]);

                    if (!empty($item['product_attribute_id'])) {
                        ProductAttribute::find($item['product_attribute_id'])->decrement('current_stock', $item['quantity']);
                    }
                    Product::find($item['id'])->decrement('current_stock', $item['quantity']);
                }

                foreach ($validated['payments'] as $payment) {
                    $newTransaction->payments()->create([
                        'amount' => $payment['amount'],
                        'payment_method' => $payment['method'],
                        'payment_date' => now(),
                        'status' => 'completado',
                    ]);
                }

                if ($customer) {
                    $totalChargedToBalance = $remainingDue + $amountFromBalance;
                    if ($totalChargedToBalance > 0) {
                        $customer->decrement('balance', $totalChargedToBalance);
                    }
                }

                return $newTransaction;
            });

            // Se redirige con un flash message que contiene los datos para la impresión
            return redirect()->route('pos.index')
                ->with('success', 'Venta registrada con éxito. Folio: ' . $transaction->folio)
                ->with('print_data', [
                    'type' => 'transaction',
                    'id' => $transaction->id
                ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar la venta: ' . $e->getMessage());
        }
    }

    private function getProductsData($search = null, $categoryId = null)
    {
        $branchId = Auth::user()->branch_id;
        $query = Product::where('branch_id', $branchId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query->with(['media', 'category:id,name', 'productAttributes'])->get()
            ->map(function ($product) {
                $promotionData = $this->getPromotionData($product);
                $variantImages = $product->getMedia('product-variant-images');

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $promotionData['price'],
                    'original_price' => $promotionData['original_price'],
                    'stock' => $product->current_stock,
                    'category' => $product->category->name ?? 'Sin categoría',
                    'image' => $product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' . urlencode($product->name),
                    'description' => $product->description,
                    'sku' => $product->sku,
                    'variants' => $this->mapVariants($product->productAttributes),
                    'variant_combinations' => $this->mapVariantCombinations($product, $variantImages),
                    'promotions' => $promotionData['promotions'],
                ];
            });
    }

    private function getPromotionData(Product $product): array
    {
        $now = Carbon::now();
        $originalPrice = (float)$product->selling_price;

        $promotions = Promotion::where('is_active', true)
            ->where(fn($q) => $q->where('start_date', '<=', $now)->orWhereNull('start_date'))
            ->where(fn($q) => $q->where('end_date', '>=', $now)->orWhereNull('end_date'))
            ->where(function ($query) use ($product) {
                $query->whereHas('rules', function ($q) use ($product) {
                    $q->where('itemable_type', Product::class)->where('itemable_id', $product->id);
                })->orWhereHas('effects', function ($q) use ($product) {
                    $q->where('itemable_type', Product::class)->where('itemable_id', $product->id);
                });
            })
            ->with(['rules.itemable:id,name', 'effects.itemable:id,name'])
            ->orderBy('priority', 'desc')
            ->get();

        if ($promotions->isEmpty()) {
            return ['price' => $originalPrice, 'original_price' => $originalPrice, 'promotions' => []];
        }

        $bestPrice = $originalPrice;

        foreach ($promotions->where('type', PromotionType::ITEM_DISCOUNT) as $promo) {
            $effect = $promo->effects->where('itemable_id', $product->id)->first();
            if (!$effect) continue;

            $promoPrice = $originalPrice;
            switch ($effect->type) {
                case PromotionEffectType::FIXED_DISCOUNT:
                    $promoPrice = $originalPrice - $effect->value;
                    break;
                case PromotionEffectType::PERCENTAGE_DISCOUNT:
                    $promoPrice = $originalPrice * (1 - ($effect->value / 100));
                    break;
                case PromotionEffectType::SET_PRICE:
                    $promoPrice = $effect->value;
                    break;
            }
            $promoPrice = max(0, (float)$promoPrice);
            if ($promoPrice < $bestPrice) {
                $bestPrice = $promoPrice;
            }
        }

        $formattedPromotions = $promotions->map(function ($p) {
            return [
                'name' => $p->name,
                'description' => $p->description,
                'type' => $p->type->value,
                'rules' => $p->rules->map(fn($r) => ['type' => $r->type->value, 'value' => $r->value, 'itemable' => $r->itemable ? ['name' => $r->itemable->name] : null]),
                'effects' => $p->effects->map(fn($e) => ['type' => $e->type->value, 'value' => $e->value, 'itemable' => $e->itemable ? ['name' => $e->itemable->name] : null]),
            ];
        })->values()->all();

        return [
            'price' => $bestPrice,
            'original_price' => ($bestPrice < $originalPrice) ? $originalPrice : $bestPrice,
            'promotions' => $formattedPromotions,
        ];
    }

    private function getActivePromotions()
    {
        $now = Carbon::now();
        $subscriptionId = Auth::user()->branch->subscription_id;

        return Promotion::where('subscription_id', $subscriptionId)
            ->where('is_active', true)
            ->where(fn($q) => $q->where('start_date', '<=', $now)->orWhereNull('start_date'))
            ->where(fn($q) => $q->where('end_date', '>=', $now)->orWhereNull('end_date'))
            ->where('type', '!=', PromotionType::ITEM_DISCOUNT)
            ->with(['rules.itemable:id,name', 'effects.itemable:id,name'])
            ->get();
    }

    private function mapVariants($productAttributes)
    {
        if ($productAttributes->isEmpty()) return new \stdClass();
        $variantsGrouped = [];
        foreach ($productAttributes as $attributeCombination) {
            foreach ($attributeCombination->attributes as $key => $value) {
                if (!isset($variantsGrouped[$key])) $variantsGrouped[$key] = [];
                if (!isset($variantsGrouped[$key][$value])) $variantsGrouped[$key][$value] = ['value' => $value, 'stock' => 0];
                $variantsGrouped[$key][$value]['stock'] += $attributeCombination->current_stock;
            }
        }
        return array_map('array_values', $variantsGrouped);
    }

    private function mapVariantCombinations(Product $product, $variantImages)
    {
        return $product->productAttributes->map(function ($attr) use ($variantImages) {
            $imageUrl = null;
            if ($variantImages->isNotEmpty()) {
                foreach ($attr->attributes as $optionValue) {
                    $foundImage = $variantImages->first(fn($media) => $media->getCustomProperty('variant_option') === $optionValue);
                    if ($foundImage) {
                        $imageUrl = $foundImage->getUrl();
                        break;
                    }
                }
            }
            return [
                'id' => $attr->id,
                'attributes' => $attr->attributes,
                'price_modifier' => (float) $attr->selling_price_modifier,
                'stock' => $attr->current_stock,
                'sku_suffix' => $attr->sku_suffix,
                'image_url' => $imageUrl,
            ];
        });
    }

    private function getCategoriesData()
    {
        $branchId = Auth::user()->branch_id;
        $subscriptionId = Auth::user()->branch->subscription_id;
        $categories = Category::where('subscription_id', $subscriptionId)
            ->where('type', 'product')
            ->withCount(['products' => fn($q) => $q->where('branch_id', $branchId)])
            ->get();
        $totalProducts = Product::where('branch_id', $branchId)->count();
        $formattedCategories = $categories->map(fn($cat) => ['id' => $cat->id, 'name' => $cat->name, 'products_count' => $cat->products_count]);
        return collect([['id' => null, 'name' => 'Todos', 'products_count' => $totalProducts]])->merge($formattedCategories);
    }

    private function getCustomersData()
    {
        $branchId = Auth::user()->branch_id;
        return Customer::where('branch_id', $branchId)->select('id', 'name', 'phone', 'balance', 'credit_limit')->orderBy('name')->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'balance' => (float) $c->balance,
                'credit_limit' => (float) $c->credit_limit,
                'available_credit' => (float) $c->available_credit,
            ]);
    }

    private function getDefaultCustomerData()
    {
        return ['id' => null, 'name' => 'Público en General', 'phone' => '', 'balance' => 0.0, 'credit_limit' => 0.0, 'available_credit' => 0.0];
    }

    private function generateFolio(): string
    {
        $prefix = strtoupper(substr(Auth::user()->branch->name, 0, 4));
        $date = Carbon::now()->format('Ymd');
        $lastTransaction = Transaction::whereDate('created_at', Carbon::today())->where('branch_id', Auth::user()->branch_id)->latest('id')->first();
        $sequence = $lastTransaction ? (int)substr($lastTransaction->folio, -3) + 1 : 1;
        return $prefix . '-' . $date . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
