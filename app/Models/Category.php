<?php

namespace App\Models;

use App\Traits\HasSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasSubscription;

    protected $fillable = [
        'name',
        'type',
        'business_type',
        'subscription_id',
    ];

    /**
     * Obtiene y formatea las categorías disponibles para el POS de una sucursal
     */
    public static function getPosCategories(int $subscriptionId, int $branchId)
    {
        $categories = self::where('subscription_id', $subscriptionId)
            ->where('type', 'product')
            ->withCount(['products' => fn($q) => $q->whereHas('branches', function($b) use ($branchId) {
                $b->where('branches.id', $branchId);
            })])
            ->get();
            
        $totalProducts = Product::whereHas('branches', function($q) use ($branchId) {
            $q->where('branches.id', $branchId);
        })->count();
        
        $formattedCategories = $categories->map(fn($cat) => [
            'id' => $cat->id, 
            'name' => $cat->name, 
            'products_count' => $cat->products_count
        ]);
        
        return collect([['id' => null, 'name' => 'Todos', 'products_count' => $totalProducts]])
            ->merge($formattedCategories);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
    
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function globalProducts(): HasMany
    {
        return $this->hasMany(GlobalProduct::class);
    }
}