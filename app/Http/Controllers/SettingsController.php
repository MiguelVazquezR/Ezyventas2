<?php

namespace App\Http\Controllers;

use App\Models\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.generals.access', only: ['index']),
            // ELIMINADO: new Middleware('can:settings.generals.update', only: ['update']),
            // Ahora la validación se hace a nivel granular dentro del método update
        ];
    }

    public function index(): Response
    {
        $user = Auth::user();
        $branch = $user->branch;
        $subscription = $branch->subscription;

        $definitions = SettingDefinition::orderBy('name')->get();

        // --- NUEVO: Obtener los módulos disponibles de la suscripción ---
        $availableModules = $subscription->getAvailableModuleNames();
        // Agregamos módulos base que siempre deben existir para configuraciones generales
        $availableModules[] = 'Sistema';
        $availableModules[] = 'Configuraciones Generales';
        $availableModules = array_unique($availableModules);
        sort($availableModules); // Ordenarlos alfabéticamente

        $userValues = $user->settings()->pluck('value', 'setting_definition_id');
        $branchValues = $branch->settings()->pluck('value', 'setting_definition_id');
        $subscriptionValues = $subscription->settings()->pluck('value', 'setting_definition_id');

        $settings = [];

        foreach ($definitions as $definition) {
            $value = match ($definition->level) {
                'user' => $userValues->get($definition->id),
                'branch' => $branchValues->get($definition->id),
                'subscription' => $subscriptionValues->get($definition->id),
                default => null,
            };

            if ($value === null) {
                $value = $definition->default_value;
            }

            if (in_array($definition->type, ['select', 'list']) && is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $settings[$definition->module][] = [
                'id' => $definition->id,
                'name' => $definition->name,
                'key' => $definition->key,
                'description' => $definition->description,
                'type' => $definition->type,
                'level' => $definition->level,
                'value' => $value,
                'default_value' => in_array($definition->type, ['select', 'list']) && is_string($definition->default_value) ? json_decode($definition->default_value, true) : $definition->default_value,
            ];
        }

        return Inertia::render('Setting/Index', [
            'settings' => $settings,
            'availableModules' => array_values($availableModules),
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $branch = $user->branch;
        $subscription = $branch->subscription;

        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        foreach ($validated['settings'] as $key => $value) {
            $definition = SettingDefinition::where('key', $key)->first();
            if (!$definition) continue;

            // --- NUEVA VALIDACIÓN DE PERMISOS POR NIVEL ---
            if ($definition->level === 'subscription' && !$user->can('settings.generals.update_subscription')) {
                continue; // Omite guardar si no tiene permiso para la suscripción
            }
            if ($definition->level === 'branch' && !$user->can('settings.generals.update_branch')) {
                continue; // Omite guardar si no tiene permiso para la sucursal
            }
            // El nivel 'user' siempre está permitido y pasará de largo estas validaciones

            if ($definition->type === 'file' && $request->hasFile("settings.{$key}")) {
                $file = $request->file("settings.{$key}");
                $path = $file->store('settings', 'public');
                $value = Storage::url($path);
            } elseif ($definition->type === 'file' && is_string($value)) {
                // Si es un archivo y llega como string, significa que no se subió uno nuevo.
                // Mantenemos la URL que ya existía.
                continue;
            }

            $model = match ($definition->level) {
                'subscription' => $subscription,
                'branch' => $branch,
                'user' => $user,
            };

            $model->settings()->updateOrCreate(
                ['setting_definition_id' => $definition->id],
                ['value' => is_array($value) ? json_encode($value) : $value]
            );
        }

        return redirect()->back()->with('success', 'Configuraciones actualizadas con éxito.');
    }

    public function storeDefinition(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:255|unique:setting_definitions',
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

    public function destroyDefinition(SettingDefinition $setting)
    {
        // Validación extra de seguridad en backend para el ID 1
        if (Auth::id() !== 1) {
            abort(403, 'Acción no autorizada.');
        }

        $setting->delete();

        return redirect()->back()->with('success', 'Configuración eliminada con éxito.');
    }

    private function handleDefinitionRequestData(array $data): array
    {
        if (in_array($data['type'], ['select', 'list']) && isset($data['default_value']) && is_array($data['default_value'])) {
            $data['default_value'] = json_encode($data['default_value']);
        } elseif (!isset($data['default_value'])) {
            $data['default_value'] = null;
        }
        return $data;
    }
}