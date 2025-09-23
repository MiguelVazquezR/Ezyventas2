<?php

namespace App\Http\Controllers;

use App\Models\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $branch = $user->branch;
        $subscription = $branch->subscription;

        $definitions = SettingDefinition::all();

        $userValues = $user->settings()->pluck('value', 'setting_definition_id');
        $branchValues = $branch->settings()->pluck('value', 'setting_definition_id');
        $subscriptionValues = $subscription->settings()->pluck('value', 'setting_definition_id');

        $settings = $definitions->map(function ($definition) use ($userValues, $branchValues, $subscriptionValues) {
            $value = null;
            switch ($definition->level) {
                case 'user':
                    $value = $userValues[$definition->id] ?? null;
                    break;
                case 'branch':
                    $value = $branchValues[$definition->id] ?? null;
                    break;
                case 'subscription':
                    $value = $subscriptionValues[$definition->id] ?? null;
                    break;
            }
            
            // Si el valor no está personalizado, usa el default_value, a menos que sea un 'select' o 'list'.
            if ($value === null) {
                 if ($definition->type === 'list' || $definition->type === 'select') {
                    $value = '[]'; // Un array vacío como string JSON
                 } else {
                    $value = $definition->default_value;
                 }
            }
            
            $definition->value = $value;

            // Procesar el valor final para el frontend
            if ($definition->type === 'boolean') {
                $definition->value = filter_var($definition->value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($definition->type === 'list') {
                $decoded = json_decode($definition->value, true);
                $definition->value = is_array($decoded) ? $decoded : [];
            } elseif ($definition->type === 'select') {
                 // Para 'select', el default_value contiene las opciones
                $options = json_decode($definition->default_value, true);
                $definition->options = is_array($options) ? $options : [];
            }

            return $definition;
        })->groupBy('module');

        return Inertia::render('Setting/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $branch = $user->branch;
        $subscription = $branch->subscription;
        
        $inputs = $request->input('settings', []);
        $files = $request->file('settings', []);
        $settings = array_merge($inputs, $files);

        foreach ($settings as $key => $value) {
            $definition = SettingDefinition::where('key', $key)->first();
            
            if ($definition) {
                $finalValue = $value;

                if ($definition->type === 'boolean') {
                    $finalValue = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                } elseif ($definition->type === 'list' && is_array($value)) {
                    $finalValue = json_encode($value);
                } elseif ($definition->type === 'file' && $value instanceof \Illuminate\Http\UploadedFile) {
                    $path = $value->store('settings_files', 'public');
                    $finalValue = Storage::url($path);
                }

                $entity = null;
                switch ($definition->level) {
                    case 'user': $entity = $user; break;
                    case 'branch': $entity = $branch; break;
                    case 'subscription': $entity = $subscription; break;
                }

                if ($entity) {
                    $entity->settings()->updateOrCreate(
                        ['setting_definition_id' => $definition->id],
                        ['value' => $finalValue]
                    );
                }
            }
        }

        return redirect()->back()->with('success', 'Configuraciones guardadas con éxito.');
    }

    private function handleDefinitionRequestData(array $data): array
    {
        // Si el tipo es 'select' o 'list' y el valor por defecto es un array, lo convierte a JSON.
        if (in_array($data['type'], ['select', 'list']) && is_array($data['default_value'])) {
            $data['default_value'] = json_encode($data['default_value']);
        } elseif (!isset($data['default_value'])) {
             $data['default_value'] = null;
        }
        return $data;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:255|unique:setting_definitions,key',
            'description' => 'nullable|string',
            'module' => 'required|string|max:255',
            'level' => ['required', Rule::in(['subscription', 'branch', 'user'])],
            'type' => ['required', Rule::in(['text', 'number', 'boolean', 'list', 'file', 'select'])],
            'default_value' => 'nullable|sometimes',
        ]);

        SettingDefinition::create($this->handleDefinitionRequestData($validated));

        return redirect()->back()->with('success', 'Nueva configuración creada con éxito.');
    }

    public function updateDefinition(Request $request, SettingDefinition $setting)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => ['required', 'string', 'max:255', Rule::unique('setting_definitions')->ignore($setting->id)],
            'description' => 'nullable|string',
            'module' => 'required|string|max:255',
            'level' => ['required', Rule::in(['subscription', 'branch', 'user'])],
            'type' => ['required', Rule::in(['text', 'number', 'boolean', 'list', 'file', 'select'])],
            'default_value' => 'nullable|sometimes',
        ]);
        
        $setting->update($this->handleDefinitionRequestData($validated));

        return redirect()->back()->with('success', 'Configuración actualizada con éxito.');
    }
}

