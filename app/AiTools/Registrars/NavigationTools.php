<?php

namespace App\AiTools\Registrars;

use App\AiTools\SiteNavigationRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class NavigationTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        return [
            [
                'permission' => null,
                'category'   => 'navigation',
                'tool'       => (new Tool)->as('find_page_location')
                    ->for('Find where in the system to do something or see certain information — e.g. "where do I register an expense", "where can I see cash register history". Returns page names with clickable links. Use this whenever the user asks "dónde", "cómo llego a", or similar navigation questions.')
                    ->withStringParameter('query', 'What the user wants to find or do, in their own words')
                    ->using(function (string $query) use ($user) {
                        $results = app(SiteNavigationRegistry::class)->searchFor($user, $query);

                        if (empty($results)) {
                            return json_encode(['message' => 'No encontré una página específica para eso. ¿Podrías darme más detalles sobre lo que buscas?']);
                        }

                        return json_encode($results, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}