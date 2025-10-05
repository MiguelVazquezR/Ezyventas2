<?php

namespace App\Http\Controllers;

use App\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldDefinitionController extends Controller
{
    public function store(Request $request)
    {
        $subscriptionId = Auth::user()->branch->subscription_id;

        $validated = $request->validate([
            'module' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['text', 'number', 'textarea', 'boolean', 'pattern', 'select', 'checkbox'])],
            'options' => ['nullable', 'string'], // Opciones como un string separado por comas
        ]);

        $optionsArray = null;
        if (in_array($validated['type'], ['select', 'checkbox']) && !empty($validated['options'])) {
            $optionsArray = array_map('trim', explode(',', $validated['options']));
        }

        CustomFieldDefinition::create([
            'subscription_id' => $subscriptionId,
            'module' => $validated['module'],
            'name' => $validated['name'],
            'key' => Str::snake($validated['name'] . '_' . Str::random(3)),
            'type' => $validated['type'],
            'options' => $optionsArray, // Guardar como array JSON
        ]);

        return back();
    }

    public function update(Request $request, CustomFieldDefinition $customFieldDefinition)
    {
        $this->authorizeToModify($customFieldDefinition);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['text', 'number', 'textarea', 'boolean', 'pattern', 'select', 'checkbox'])],
            'options' => ['nullable', 'string'],
        ]);

        $optionsArray = null;
        if (in_array($validated['type'], ['select', 'checkbox']) && !empty($validated['options'])) {
            $optionsArray = array_map('trim', explode(',', $validated['options']));
        }

        $customFieldDefinition->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'options' => $optionsArray,
        ]);


        return back();
    }

    public function destroy(CustomFieldDefinition $customFieldDefinition)
    {
        $this->authorizeToModify($customFieldDefinition);
        $customFieldDefinition->delete();
        return back();
    }

    private function authorizeToModify(CustomFieldDefinition $customFieldDefinition)
    {
        if ($customFieldDefinition->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }
    }
}