<?php
namespace App\Models;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpArticle extends Model
{
    use HasFactory;
    
    protected $table = 'help_articles';

    protected $fillable = [
        'help_category_id', 'title', 'slug', 'type',
        'content', 'youtube_id', 'views', 'status',
    ];

    protected $casts = [
        'type' => HelpArticleType::class,
        'status' => HelpArticleStatus::class,
        'views' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }
}