<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PromotionEffectType;
use App\Enums\PromotionType;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Promotion;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Laravel\Jetstream\Agent;

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
        $branchId = $user->branch_id;
        $isOwner = !$user->roles()->exists();

        $activeSession = $user->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $branchId))
            ->with([
                'cashRegister:id,name',
                'users:id,name',
                'opener:id,name',
                'transactions' => fn($q) => $q->with([
                    'customer:id,name',
                    'user:id,name'
                ])->latest(),
                'cashMovements' => fn($q) => $q->with([
                    'user:id,name'
                ])->latest(),
                'payments.transaction' => function ($query) {
                    $query->with(['customer:id,name', 'user:id,name']);
                },
            ])
            ->first();

        $joinableSessions = null;
        $availableCashRegisters = null;
        $userBankAccounts = null; // Cambiado de branchBankAccounts

        if (!$activeSession) {
            $joinableSessions = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $branchId))
                ->with('cashRegister:id,name', 'opener:id,name')
                ->get();

            if ($joinableSessions->isEmpty()) {
                $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
                    ->where('is_active', true)->where('in_use', false)
                    ->select('id', 'name')->get();
            }

            // --- LÓGICA DE CUENTAS BANCARIAS CORREGIDA ---
            if ($isOwner) {
                // Si es propietario, obtiene todas las cuentas de la sucursal.
                $userBankAccounts = Auth::user()->branch->bankAccounts()->get();
            } else {
                // Si no es propietario, obtiene solo las cuentas asignadas.
                $userBankAccounts = $user->bankAccounts()->get();
            }
        }

        if ($activeSession) {
            $paymentTotals = $activeSession->payments
                ->where('status', 'completado')
                ->groupBy('payment_method.value')
                ->map->sum('amount');

            $activeSession->totals = [
                'cash' => $paymentTotals['efectivo'] ?? 0,
                'card' => $paymentTotals['tarjeta'] ?? 0,
                'transfer' => $paymentTotals['transferencia'] ?? 0,
                'balance' => $paymentTotals['saldo'] ?? 0,
            ];
        }

        $search = $request->input('search');
        $categoryId = $request->input('category');
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        $props = [
            'products' => $this->getProductsData($search, $categoryId),
            'categories' => $this->getCategoriesData(),
            'customers' => $this->getCustomersData(),
            'defaultCustomer' => $this->getDefaultCustomerData(),
            'filters' => $request->only(['search', 'category']),
            'activePromotions' => $this->getActivePromotions(),
            'activeSession' => $activeSession,
            'joinableSessions' => $joinableSessions,
            'availableCashRegisters' => $availableCashRegisters,
            'availableTemplates' => $availableTemplates,
            'userBankAccounts' => $userBankAccounts, // Se pasa la nueva variable
        ];

        $agent = new Agent();
        $view = ($agent->isMobile() || $agent->isTablet()) ? 'POS/IndexMobile' : 'POS/Index';

        return Inertia::render($view, $props);
    }

    public function checkout(Request $request, PaymentService $paymentService)
    {
        $validated = $request->validate([
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id',
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.product_attribute_id' => 'nullable|exists:product_attributes,id',
            'cartItems.*.quantity' => 'required|numeric|min:1',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'cartItems.*.discount' => 'required|numeric',
            'cartItems.*.discount_reason' => 'nullable|string|max:255',
            'customerId' => 'nullable|exists:customers,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'nullable|numeric',
            'total' => 'required|numeric',
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payments.*.notes' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        $customer = $validated['customerId'] ? Customer::find($validated['customerId']) : null;
        $totalSale = (float) $validated['total'];
        $paymentsFromRequest = $validated['payments'] ?? [];

        try {
            $transaction = DB::transaction(function () use ($validated, $user, $customer, $totalSale, $paymentsFromRequest, $paymentService) {

                $newTransaction = Transaction::create([
                    'cash_register_session_id' => $validated['cash_register_session_id'],
                    'folio' => $this->generateFolio(),
                    'customer_id' => $customer?->id,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    // Se crea como PENDIENTE y se actualiza al final si se paga por completo.
                    'status' => TransactionStatus::PENDING,
                    'channel' => TransactionChannel::POS,
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['total_discount'] ?? 0,
                    'total_tax' => 0, // Ajustar si se manejan impuestos
                    'total' => $totalSale,
                    'currency' => 'MXN',
                    'status_changed_at' => now(),
                ]);

                // Crear items de la transacción y descontar stock
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
                        'discount_amount' => $item['discount'],
                        'discount_reason' => $item['discount_reason'] ?? null,
                        'line_total' => $item['quantity'] * $item['unit_price'],
                    ]);

                    if (!empty($item['product_attribute_id'])) {
                        ProductAttribute::find($item['product_attribute_id'])->decrement('current_stock', $item['quantity']);
                    }
                    Product::find($item['id'])->decrement('current_stock', $item['quantity']);
                }

                // 1. Aplicar el saldo a favor del cliente si existe.
                if ($customer && $customer->balance > 0) {
                    $balanceToUse = min($totalSale, (float) $customer->balance);
                    if ($balanceToUse > 0) {

                        $balancePaymentData = [[
                            'amount' => $balanceToUse,
                            'method' => PaymentMethod::BALANCE->value,
                            'notes' => "Uso de saldo en venta POS #{$newTransaction->folio}"
                        ]];

                        $paymentService->processPayments(
                            $newTransaction,
                            $balancePaymentData,
                            $validated['cash_register_session_id']
                        );

                        $customer->decrement('balance', $balanceToUse);

                        $customer->balanceMovements()->create([
                            'transaction_id' => $newTransaction->id,
                            'type' => CustomerBalanceMovementType::CREDIT_USAGE,
                            'amount' => -$balanceToUse,
                            'balance_after' => $customer->balance,
                            'notes' => "Uso de saldo en venta POS #{$newTransaction->folio}",
                        ]);
                    }
                }

                // 2. Registrar los pagos directos (efectivo, tarjeta, etc.) que vienen del modal.
                if (!empty($paymentsFromRequest)) {
                    $paymentService->processPayments($newTransaction, $paymentsFromRequest, $validated['cash_register_session_id']);
                }

                // 3. Calcular el estado final y gestionar el crédito.
                $totalPaid = $newTransaction->fresh()->payments()->sum('amount');
                $remainingDue = $totalSale - $totalPaid;

                if ($remainingDue > 0.01) { // VENTA A CRÉDITO
                    if (!$customer || $remainingDue > $customer->available_credit) {
                        throw new \Exception("Pago insuficiente y el cliente no tiene crédito disponible para cubrir la diferencia.");
                    }

                    $customer->decrement('balance', $remainingDue);

                    $customer->balanceMovements()->create([
                        'transaction_id' => $newTransaction->id,
                        'type' => CustomerBalanceMovementType::CREDIT_SALE,
                        'amount' => -$remainingDue,
                        'balance_after' => $customer->balance,
                        'notes' => "Cargo a crédito por venta POS #{$newTransaction->folio}",
                    ]);
                } else { // VENTA PAGADA POR COMPLETO
                    $newTransaction->update(['status' => TransactionStatus::COMPLETED]);
                }

                return $newTransaction;
            });

            return redirect()->route('pos.index')
                ->with('success', 'Venta registrada con éxito. Folio: ' . $transaction->folio)
                ->with('print_data', ['type' => 'transaction', 'id' => $transaction->id]);
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

        // --- Cargar price_tiers explícitamente ---
        $paginatedProducts = $query->with(['media', 'category:id,name', 'productAttributes'])->paginate(20)->withQueryString();

        $paginatedProducts->through(function ($product) {
            $promotionData = $this->getPromotionData($product);
            $variantImages = $product->getMedia('product-variant-images');
            $generalImages = $product->getMedia('product-general-images')->map->getUrl();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $promotionData['price'],
                'original_price' => $promotionData['original_price'], // Precio base antes de promos/tiers
                'selling_price' => (float) $product->selling_price, // Precio de venta (1 pieza)
                'price_tiers' => $product->price_tiers ?? [], // <-- AÑADIDO: Incluir price_tiers
                'stock' => $product->current_stock,
                'category' => $product->category->name ?? 'Sin categoría',
                'image' => $generalImages->first() ?: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' . urlencode($product->name),
                'general_images' => $generalImages,
                'description' => $product->description,
                'sku' => $product->sku,
                'variants' => $this->mapVariants($product->productAttributes),
                'variant_combinations' => $this->mapVariantCombinations($product, $variantImages),
                'promotions' => $promotionData['promotions'],
            ];
        });

        return $paginatedProducts;
    }

    /**
     * Calcula el precio base considerando promociones de descuento directo.
     * NO considera precios por volumen aquí, eso se hará en el frontend.
     */
    private function getPromotionData(Product $product): array
    {
        $now = Carbon::now();
        // Usar selling_price como base SIEMPRE para descuentos
        $basePrice = (float)$product->selling_price; // Precio de 1 pieza

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
            // Devolver el precio base (1 pieza)
            return ['price' => $basePrice, 'original_price' => $basePrice, 'promotions' => []];
        }

        $bestPriceAfterDiscount = $basePrice; // Empezar con el precio base

        // Calcular SOLO descuentos directos (ITEM_DISCOUNT)
        foreach ($promotions->where('type', PromotionType::ITEM_DISCOUNT) as $promo) {
            $effect = $promo->effects->where('itemable_id', $product->id)->first();
            if (!$effect) continue;

            $promoPrice = $basePrice; // Aplicar descuento sobre el precio base
            switch ($effect->type) {
                case PromotionEffectType::FIXED_DISCOUNT:
                    $promoPrice = $basePrice - $effect->value;
                    break;
                case PromotionEffectType::PERCENTAGE_DISCOUNT:
                    $promoPrice = $basePrice * (1 - ($effect->value / 100));
                    break;
                case PromotionEffectType::SET_PRICE:
                    // Si la promo establece un precio fijo, usarlo si es menor
                    $promoPrice = (float)$effect->value < $basePrice ? (float)$effect->value : $basePrice;
                    break;
            }
            $promoPrice = max(0, (float)$promoPrice);
            // Actualizar el mejor precio encontrado *después de descuentos*
            if ($promoPrice < $bestPriceAfterDiscount) {
                $bestPriceAfterDiscount = $promoPrice;
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
            // 'price' es el mejor precio DESPUÉS de descuentos (para 1 pieza)
            'price' => $bestPriceAfterDiscount,
            // 'original_price' es el precio ANTES de descuentos (para 1 pieza)
            'original_price' => $basePrice,
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
        // Obtener la suscripción del usuario actual
        $branchId = Auth::user()->branch_id;

        // Buscar la última transacción para esta suscripción que siga el formato V-
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'LIKE', 'V-%')
            ->orderBy('id', 'desc') // Ordenar por ID para obtener la más reciente de forma fiable
            ->first();

        $sequence = 1; // Iniciar en 1 por defecto para un nuevo suscriptor

        if ($lastTransaction) {
            // Extraer solo la parte numérica del folio anterior
            $lastFolioNumber = (int) substr($lastTransaction->folio, 2);
            $sequence = $lastFolioNumber + 1;
        }

        // Formatear el nuevo folio con el prefijo y el número consecutivo con ceros a la izquierda
        return 'V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
