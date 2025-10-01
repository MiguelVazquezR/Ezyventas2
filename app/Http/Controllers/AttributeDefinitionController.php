<?php

namespace App\Http\Controllers;

use App\Models\AttributeDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttributeDefinitionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate(['category_id' => 'required|exists:categories,id']);

        $attributes = AttributeDefinition::with('options')
            ->where('subscription_id', Auth::user()->branch->subscription_id)
            ->where('category_id', $request->category_id)
            ->orderBy('name')
            ->get();

        return response()->json($attributes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('attribute_definitions')->where(function ($query) use ($request) {
                    return $query->where('category_id', $request->category_id)
                                 ->where('subscription_id', Auth::user()->branch->subscription_id);
                }),
            ],
            'requires_image' => 'required|boolean',
            'options' => 'present|array',
            'options.*.value' => 'required|string|max:255',
        ]);

        $attribute = DB::transaction(function () use ($validated) {
            $definition = AttributeDefinition::create([
                'subscription_id' => Auth::user()->branch->subscription_id,
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'requires_image' => $validated['requires_image'],
            ]);

            if (!empty($validated['options'])) {
                $optionsToInsert = array_map(function ($option) {
                    return ['value' => $option['value']];
                }, $validated['options']);
                $definition->options()->createMany($optionsToInsert);
            }

            return $definition->load('options');
        });

        return response()->json($attribute, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AttributeDefinition $attributeDefinition)
    {
        // Se carga con sus opciones por si se necesita en el futuro.
        return response()->json($attributeDefinition->load('options'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AttributeDefinition $attributeDefinition)
    {
        // Asegurarse de que el atributo pertenezca a la suscripción del usuario.
        if ($attributeDefinition->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('attribute_definitions')->where(function ($query) use ($request, $attributeDefinition) {
                    return $query->where('category_id', $attributeDefinition->category_id)
                                 ->where('subscription_id', Auth::user()->branch->subscription_id);
                })->ignore($attributeDefinition->id),
            ],
            'requires_image' => 'required|boolean',
            'options' => 'present|array',
            'options.*.id' => 'nullable|integer',
            'options.*.value' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($validated, $attributeDefinition) {
            $attributeDefinition->update([
                'name' => $validated['name'],
                'requires_image' => $validated['requires_image'],
            ]);

            $incomingOptionIds = collect($validated['options'])->pluck('id')->filter()->all();
            
            // 1. Eliminar opciones que ya no existen
            $attributeDefinition->options()->whereNotIn('id', $incomingOptionIds)->delete();

            // 2. Actualizar o crear las opciones restantes
            foreach ($validated['options'] as $optionData) {
                $attributeDefinition->options()->updateOrCreate(
                    ['id' => $optionData['id'] ?? null], // Condición de búsqueda
                    ['value' => $optionData['value']]  // Valores para actualizar o crear
                );
            }
        });

        return response()->json($attributeDefinition->load('options'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AttributeDefinition $attributeDefinition)
    {
        // Asegurarse de que el atributo pertenezca a la suscripción del usuario.
        if ($attributeDefinition->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'Unauthorized action.');
        }

        $attributeDefinition->delete();

        return response()->json(null, 204);
    }
}