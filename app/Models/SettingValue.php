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
    
    /**
     * CAMBIO: Se renombra el método de 'settable' a 'configurable' para evitar
     * conflictos con el método setTable() del modelo base de Eloquent.
     * * Se especifican los nombres de las columnas para que Eloquent sepa que
     * debe seguir usando 'settable_type' y 'settable_id' sin necesidad de una migración.
     */
    public function configurable(): MorphTo
    {
        return $this->morphTo('configurable', 'settable_type', 'settable_id');
    }
}