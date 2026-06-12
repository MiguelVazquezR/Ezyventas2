<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityLogService
{
    /**
     * Obtiene y formatea el historial de actividades de un modelo.
     *
     * @param Model $model El modelo que utiliza Spatie Activitylog (Product, Quote, etc.)
     * @param Request $request El request con los parámetros de filtrado (start_date, end_date, all_activities)
     * @param string $translationKey La llave en config/log_translations.php (ej. 'Product', 'Quote')
     * @param bool $strictChanges Si es true, limpia los "changes" vacíos y sincroniza "before" con "after" (Útil para Cotizaciones/Órdenes).
     * @return \Illuminate\Support\Collection
     */
    public function getFormattedActivities(Model $model, Request $request, string $translationKey, bool $strictChanges = false)
    {
        $translations = config("log_translations.{$translationKey}", []);
        $activitiesQuery = $model->activities()->with('causer');

        if ($request->has('all_activities')) {
            $rawActivities = $activitiesQuery->latest()->get();
        } else {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            if ($startDate && $endDate) {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->endOfDay();
            } else {
                // Por defecto, carga solo los de la semana actual
                $start = now()->startOfWeek();
                $end = now()->endOfWeek();
            }

            $rawActivities = $activitiesQuery->whereBetween('created_at', [$start, $end])->latest()->get();
        }

        $formattedActivities = $rawActivities->map(function ($activity) use ($translations, $strictChanges) {
            $changes = ['before' => [], 'after' => []];

            $oldProps = $activity->properties->get('old', []);
            $newProps = $activity->properties->get('attributes', []);

            // Construir el Before
            if (is_array($oldProps)) {
                foreach ($oldProps as $key => $value) {
                    $changes['before'][$translations[$key] ?? $key] = $value;
                }
            }

            // Construir el After
            if (is_array($newProps)) {
                foreach ($newProps as $key => $value) {
                    if ($strictChanges) {
                        // En modo estricto, evitamos iterar propiedades que no cambiaron su valor
                        if (!is_array($oldProps) || !array_key_exists($key, $oldProps) || $oldProps[$key] !== $value) {
                            $changes['after'][$translations[$key] ?? $key] = $value;
                        }
                    } else {
                        $changes['after'][$translations[$key] ?? $key] = $value;
                    }
                }
            }

            // En modo estricto, alineamos el "before" para que solo muestre las llaves que están en "after"
            if ($strictChanges) {
                $changes['before'] = array_intersect_key($changes['before'], $changes['after']);
            }

            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                'created_at' => $activity->created_at->toIso8601String(), // FECHA EXACTA
                'properties' => $activity->properties,
                // Cast a object para que Vue.js siempre lo reciba como objeto JSON y no array si está vacío
                'changes' => (object) (!empty($changes['before']) || !empty($changes['after']) ? $changes : []),
            ];
        });

        if ($strictChanges) {
            return $formattedActivities->filter(function ($activity) {
                return $activity['event'] !== 'updated' || !empty((array) $activity['changes']);
            })->values();
        }

        return $formattedActivities;
    }
}