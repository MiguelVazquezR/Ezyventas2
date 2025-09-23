<?php

namespace App\Http\Controllers;

use App\Models\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        // CAMBIO: Obtener la sucursal actual del usuario en lugar de la suscripción.
        $branch = Auth::user()->branch;

        // Obtener todas las definiciones de configuración disponibles
        $definitions = SettingDefinition::all();

        // CAMBIO: Obtener los valores guardados para esta sucursal.
        $values = $branch->settings()->pluck('value', 'setting_definition_id');

        // Combinar las definiciones con sus valores (o el valor por defecto)
        $settings = $definitions->map(function ($definition) use ($values) {
            $definition->value = $values[$definition->id] ?? $definition->default_value;
            // Convertir strings 'true'/'false' a booleanos para el frontend
            if ($definition->type === 'boolean') {
                $definition->value = filter_var($definition->value, FILTER_VALIDATE_BOOLEAN);
            }
            return $definition;
        })->groupBy('module'); // Agrupar por módulo para las pestañas

        return Inertia::render('Setting/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        // CAMBIO: Obtener la sucursal actual del usuario en lugar de la suscripción.
        $branch = Auth::user()->branch;
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            $definition = SettingDefinition::where('key', $key)->first();
            if ($definition) {
                // CAMBIO: Actualizar o crear el valor de la configuración para esta sucursal.
                $branch->settings()->updateOrCreate(
                    ['setting_definition_id' => $definition->id],
                    ['value' => is_bool($value) ? ($value ? 'true' : 'false') : $value]
                );
            }
        }

        return redirect()->back()->with('success', 'Configuraciones guardadas con éxito.');
    }
}
