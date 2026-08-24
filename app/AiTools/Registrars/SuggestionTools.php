<?php

namespace App\AiTools\Registrars;

use App\Models\Suggestion;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class SuggestionTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => null,
                'category'   => 'suggestions',
                'tool'       => (new Tool)->as('submit_feedback')
                    ->for('Enviar una sugerencia, reporte de error o comentario de mejora para el equipo de EzyVentas. Usa esta herramienta cuando el usuario quiera dejar feedback, reportar un problema, sugerir una funcionalidad o compartir cualquier comentario sobre el sistema.')
                    ->withStringParameter('category', 'Categoría: "feature" (nueva funcionalidad), "bug" (error), "improvement" (mejora), "other" (otro)')
                    ->withStringParameter('title', 'Título breve y descriptivo de la sugerencia')
                    ->withStringParameter('description', 'Descripción detallada de la sugerencia, problema o comentario')
                    ->using(function (string $category, string $title, string $description) use ($branchId, $user) {
                        $suggestion = Suggestion::create([
                            'branch_id'   => $branchId,
                            'user_id'     => $user->id,
                            'category'    => $category,
                            'title'       => $title,
                            'description' => $description,
                            'status'      => 'pending',
                            'priority'    => 'medium',
                        ]);

                        return json_encode([
                            'message' => '¡Gracias por tu sugerencia! El equipo de EzyVentas la revisará próximamente.',
                            'suggestion' => [
                                'id'       => $suggestion->id,
                                'category' => $suggestion->category,
                                'title'    => $suggestion->title,
                                'status'   => $suggestion->status,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => null,
                'category'   => 'suggestions',
                'tool'       => (new Tool)->as('request_new_capability')
                    ->for('Registrar una solicitud de capacidad faltante. Usa esta herramienta cuando el usuario pida hacer algo que no se puede resolver con las herramientas disponibles, por ejemplo un reporte, una acción o una consulta que el asistente no puede ejecutar. Esto permite al equipo de EzyVentas conocer las capacidades que los usuarios necesitan y priorizar su desarrollo.')
                    ->withStringParameter('title', 'Título breve de la solicitud: la capacidad o funcionalidad que el usuario desea')
                    ->withStringParameter('description', 'Descripción detallada: qué pidió el usuario, en qué contexto, y qué se requeriría para resolverlo')
                    ->using(function (string $title, string $description) use ($branchId, $user) {
                        $suggestion = Suggestion::create([
                            'branch_id'   => $branchId,
                            'user_id'     => $user->id,
                            'category'    => 'capability_request',
                            'title'       => $title,
                            'description' => $description,
                            'status'      => 'pending',
                            'priority'    => 'medium',
                        ]);

                        return json_encode([
                            'message' => 'Tu solicitud ha sido registrada para que el equipo de EzyVentas considere agregar esta capacidad al asistente.',
                            'suggestion' => [
                                'id'       => $suggestion->id,
                                'category' => $suggestion->category,
                                'title'    => $suggestion->title,
                                'status'   => $suggestion->status,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => null,
                'category'   => 'suggestions',
                'tool'       => (new Tool)->as('list_my_suggestions')
                    ->for('Consultar las sugerencias y comentarios que has enviado previamente, con su estado actual')
                    ->withStringParameter('status', 'Filtrar por estado: "pending", "reviewed", "planned", "implemented", "declined" (opcional)')
                    ->withStringParameter('category', 'Filtrar por categoría: "feature", "bug", "improvement", "other" (opcional)')
                    ->using(function (?string $status = null, ?string $category = null) use ($user) {
                        $query = Suggestion::query()->where('user_id', $user->id);

                        if ($status) {
                            $query->where('status', $status);
                        }

                        if ($category) {
                            $query->where('category', $category);
                        }

                        $suggestions = $query->orderByDesc('created_at')
                            ->limit(20)
                            ->get(['id', 'category', 'title', 'status', 'priority', 'created_at']);

                        if ($suggestions->isEmpty()) {
                            return json_encode([
                                'message' => 'No tienes sugerencias registradas con esos filtros.',
                                'suggestions' => [],
                            ], JSON_PRETTY_PRINT);
                        }

                        return json_encode([
                            'message' => 'Tus sugerencias enviadas:',
                            'suggestions' => $suggestions->toArray(),
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}