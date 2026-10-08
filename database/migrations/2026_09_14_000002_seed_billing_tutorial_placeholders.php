<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ports the initial billing tutorial placeholders (previously a static JS
 * file, `Pages/Billing/tutorials.js`) into the database so the new admin
 * panel ("Tutoriales") can manage them. The section structure is kept as a
 * draft; the URLs are added later from the admin panel.
 */
return new class extends Migration
{
    private const PLACEHOLDERS = [
        'Configuración fiscal' => [
            ['Cómo crear un emisor fiscal', 'Registra el RFC emisor con el que facturarás.'],
            ['Cómo subir tus certificados CSD', 'Carga el certificado (.cer) y la llave (.key).'],
            ['Cómo firmar el manifiesto', 'Firma el documento requerido por el PAC.'],
            ['Cómo comprar timbres', 'Adquiere timbres para tu emisor fiscal.'],
        ],
        'Facturas' => [
            ['Cómo crear una factura', 'Emite un CFDI 4.0 desde una venta o por conceptos libres.'],
            ['Cómo timbrar una prefactura', 'Convierte un borrador en una factura timbrada.'],
            ['Cómo solicitar la cancelación de una factura', 'Solicita la cancelación con los motivos del SAT.'],
            ['Cómo descargar el PDF y el XML', 'Obtén la representación impresa y el archivo fiscal.'],
        ],
        'Pagos y complementos' => [
            ['Cómo registrar el pago de una factura PPD', 'Agrega los pagos recibidos a una factura en parcialidades.'],
            ['Cómo generar un complemento de pago', 'Emite el CFDI de tipo pago (REP).'],
        ],
    ];

    public function up(): void
    {
        $now = now();
        $order = 0;
        $rows = [];

        foreach (self::PLACEHOLDERS as $section => $videos) {
            foreach ($videos as [$title, $description]) {
                $rows[] = [
                    'module'      => 'billing',
                    'section'     => $section,
                    'title'       => $title,
                    'description' => $description,
                    'duration'    => null,
                    'url'         => null,
                    'file_path'   => null,
                    'sort_order'  => ++$order,
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('tutorial_videos')->insert($rows);
        }
    }

    public function down(): void
    {
        $titles = collect(self::PLACEHOLDERS)
            ->flatten(1)
            ->map(fn (array $video) => $video[0])
            ->all();

        DB::table('tutorial_videos')
            ->where('module', 'billing')
            ->whereIn('title', $titles)
            ->delete();
    }
};
