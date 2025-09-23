<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettingDefinition extends Model
{
    use HasFactory;
    
    protected $table = 'setting_definitions';

    protected $fillable = ['key', 'name', 'description', 'module', 'level', 'type', 'default_value'];

    public function values(): HasMany
    {
        return $this->hasMany(SettingValue::class);
    }
}