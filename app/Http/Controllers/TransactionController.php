<?php

namespace App\Http\Controllers;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:transactions.access', only: ['index']),
            new Middleware('can:transactions.see_details', only: ['show']),
            new Middleware('can:transactions.cancel', only: ['cancel']),
            new Middleware('can:transactions.refund', only: ['refund']),
        ];
    }
    
   public function index(Request $request): Response
   {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = Transaction::query()
            ->leftJoin('customers', 'transactions.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.branch_id', $branchId)
            // ->where('transactions.channel', '!=', TransactionChannel::BALANCE_PAYMENT)
            ->with(['customer:id,name', 'user:id,name'])
            ->select('transactions.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('transactions.folio', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('customers.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        $sortColumn = match ($sortField) {
            'customer.name' => 'customers.name',
            'user.name' => 'users.name',
            'total' => DB::raw('transactions.subtotal - transactions.total_discount'),
            default => 'transactions.' . $sortField,
        };
        $query->orderBy($sortColumn, $sortOrder);

        $transactions = $query->paginate($request->input('rows', 20))->withQueryString();

        // Se obtienen las plantillas disponibles para la sucursal actual
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('Transaction/Index', [
            'transactions' => $transactions,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'availableTemplates' => $availableTemplates, // Se pasan a la vista
        ]);
   }

   public function show(Transaction $transaction): Response
   {
        $transaction->load([
            'customer:id,name',
            'user:id,name',
            'branch:id,name',
            'items.itemable',
            'payments',
            // 'customerBalanceMovements' // Ya no es necesario
        ]);

        // --- INICIO DE LA CORRECCIÓN ---
        // Se elimina el cálculo del monto pagado con saldo,
        // ya que ahora viene incluido en la relación 'payments'.
        // --- FIN DE LA CORRECCIÓN ---


        // Se obtienen las plantillas disponibles para la sucursal y el contexto de transacción
        $availableTemplates = Auth::user()->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::TRANSACTION, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('Transaction/Show', [
            'transaction' => $transaction,
            'availableTemplates' => $availableTemplates, // Se pasan a la vista
        ]);
   }

    /**
     * Cancela una transacción y repone el stock si estaba completada.
     */
    public function cancel(Transaction $transaction)
    {
        if (!in_array($transaction->status, [TransactionStatus::PENDING, TransactionStatus::COMPLETED])) {
            return redirect()->back()->with(['error' => 'Solo se pueden cancelar ventas pendientes o completadas.']);
        }

        DB::transaction(function () use ($transaction) {
            // Si la venta estaba completada, reponer el stock
            if ($transaction->status === TransactionStatus::COMPLETED) {
                $this->returnStock($transaction);
            }
            $transaction->update(['status' => TransactionStatus::CANCELLED]);
        });

        return redirect()->back()->with('success', 'Venta cancelada con éxito.');
    }

    /**
     * Marca una transacción como reembolsada y repone el stock.
     */
    public function refund(Transaction $transaction)
    {
        if ($transaction->status !== TransactionStatus::COMPLETED) {
            return redirect()->back()->with(['error' => 'Solo se pueden reembolsar ventas completadas.']);
        }

        DB::transaction(function () use ($transaction) {
            $this->returnStock($transaction);
            $transaction->update(['status' => TransactionStatus::REFUNDED]);
        });

        return redirect()->back()->with('success', 'Devolución generada con éxito.');
    }

    /**
     * Helper para reponer el stock de los productos de una transacción.
     */
    private function returnStock(Transaction $transaction)
    {
        foreach ($transaction->items as $item) {
            if ($item->itemable_type === Product::class) {
                $product = $item->itemable;
                if ($product) {
                    $product->increment('current_stock', $item->quantity);
                }
            }
        }
    }
}