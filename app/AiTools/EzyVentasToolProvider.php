<?php

namespace App\AiTools;

use App\AiTools\Registrars\CashRegisterTools;
use App\AiTools\Registrars\CustomerTools;
use App\AiTools\Registrars\DashboardTools;
use App\AiTools\Registrars\ExpenseTools;
use App\AiTools\Registrars\ExportTools;
use App\AiTools\Registrars\NavigationTools;
use App\AiTools\Registrars\ProductTools;
use App\AiTools\Registrars\PromotionTools;
use App\AiTools\Registrars\QuoteInvoiceTools;
use App\AiTools\Registrars\ReportTools;
use App\AiTools\Registrars\ServiceOrderTools;
use App\AiTools\Registrars\ServiceTools;
use App\AiTools\Registrars\StaffPerformanceTools;
use App\AiTools\Registrars\ToolRegistrar;
use App\AiTools\Registrars\TransactionTools;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(function ($def) use ($user) {
                $perms = (array) ($def['permission'] ?? []);
                return empty($perms) || collect($perms)->every(fn ($p) => $user->can($p));
            })
            ->map(fn ($def) => $def['tool'])
            ->values()
            ->all();
    }

    /**
     * Return the list of available category labels for the user's permitted tools.
     */
    public function categories(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(function ($def) use ($user) {
                $perms = (array) ($def['permission'] ?? []);
                return empty($perms) || collect($perms)->every(fn ($p) => $user->can($p));
            })
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Aggregate all tool definitions from every module registrar.
     */
    private function definitions(Authenticatable $user): array
    {
        return collect($this->registrars())
            ->flatMap(fn (ToolRegistrar $registrar) => $registrar->definitions($user))
            ->all();
    }

    /**
     * Registry of all tool-providing modules.
     *
     * To add or remove a category of AI tools, simply add or comment out
     * the corresponding registrar line below — no other file needs to change.
     *
     * @return ToolRegistrar[]
     */
    private function registrars(): array
    {
        return [
            new ReportTools(),
            new TransactionTools(),
            new CustomerTools(),
            new ProductTools(),
            new CashRegisterTools(),
            new ServiceTools(),
            new PromotionTools(),
            new QuoteInvoiceTools(),
            new ExpenseTools(),
            new ServiceOrderTools(),
            new StaffPerformanceTools(),
            new DashboardTools(),
            new NavigationTools(),
            new ExportTools(),
        ];
    }
}