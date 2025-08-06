<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SettingValue extends Model
{
    use HasFactory;
    
    protected $table = 'setting_values';

    protected $fillable = ['setting_definition_id', 'settable_id', 'settable_type', 'value'];
    
    public function definition(): BelongsTo
    {
        return $this->belongsTo(SettingDefinition::class, 'setting_definition_id');
    }
    
    public function settable(): MorphTo
    {
        return $this->morphTo();
    }
}