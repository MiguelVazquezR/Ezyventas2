<?php

namespace App\AiTools\Registrars;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Prism\Prism\Tool;

class ExportTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $subscriptionId = $user->branch->subscription_id;

        return [
            [
                'permission' => ['products.access', 'products.import_export'],
                'category'   => 'downloadable Excel exports',
                'tool'       => (new Tool)->as('export_products_excel')
                    ->for('Generar un archivo Excel descargable con el catálogo completo de productos')
                    ->using(function () use ($subscriptionId) {
                        $filename = 'exports/' . $subscriptionId . '/productos_' . now()->timestamp . '.xlsx';

                        Excel::store(new \App\Exports\ProductsExport, $filename, config('ai-agent.export_disk', 'local'));

                        $url = URL::temporarySignedRoute(
                            'ai-agent.download',
                            now()->addMinutes(config('ai-agent.download_url_ttl', 15)),
                            ['path' => rtrim(strtr(base64_encode($filename), '+/', '-_'), '=')],
                        );

                        return json_encode([
                            'download_url'       => $url,
                            'expires_in_minutes' => config('ai-agent.download_url_ttl', 15),
                        ]);
                    }),
            ],
        ];
    }
}