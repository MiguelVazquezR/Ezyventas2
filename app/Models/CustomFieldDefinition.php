<?php

namespace App\Models;

use App\Traits\HasSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomFieldDefinition extends Model
{
    use HasFactory, HasSubscription;
    protected $guarded = [];

    protected $casts = [
        'options' => 'array', // Asegura que las opciones se manejen como un array
    ];
}