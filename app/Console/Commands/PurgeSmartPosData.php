<?php

namespace App\Console\Commands;

use App\Models\SmartPosDetectionEvent;
use App\Models\SmartPosProductSample;
use App\Models\SmartPosSetting;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Retención de datos del "Modo Caja Inteligente" (Fase 5).
 *
 * Purga, negocio por negocio, las dos cosas que la especificación permite borrar
 * por antigüedad:
 *
 * 1. **Eventos de detección** (`smartpos_detection_events`) anteriores a la
 *    retención del negocio. Se borran en trozos de mil filas para no bloquear una
 *    tabla que puede ser grande.
 * 2. **Recortes** (`smartpos_product_samples.crop_path`): se borra el archivo del
 *    disco privado y la referencia queda en `null`. La fila y el vector **nunca**
 *    se tocan: la especificación prohíbe borrar vectores por edad.
 *
 * La purga de recortes se delega en
 * `SmartCheckoutGalleryService::purgeExpiredCrops()`, que ya conoce el criterio
 * (y que también corre al guardar una muestra): este comando no lo duplica.
 *
 * ## Reglas de retención
 *
 * - `--days` fuerza el plazo de **todos** los negocios (entero >= 1), tanto para
 *   los eventos como para los recortes: una sola invocación no deja nada atrás.
 * - Si no se pasa, se usa `smartpos_settings.retention_days`.
 * - Si la fila no existe o el valor es <= 0, se usa
 *   `SmartPosSettingsService::DEFAULT_RETENTION_DAYS` (30), igual que el servicio.
 *
 * El criterio de recortes no se duplica aquí: `purgeExpiredCrops()` acepta un
 * plazo opcional y sigue siendo el único lugar que decide qué archivo se borra.
 * La simulación cuenta con el mismo plazo que aplicaría la corrida real.
 *
 * ## Degradación
 *
 * Un despliegue con la migración pendiente no puede romper la tarea programada:
 * si falta `smartpos_settings` se avisa y se sale con código 0, y si falta la
 * tabla de eventos se avisa una vez y se continúa con los recortes.
 *
 * ## Uso
 *
 * ```powershell
 * php artisan smartpos:purge                 # todos los negocios con ajustes
 * php artisan smartpos:purge --dry-run       # enseña lo que se borraría
 * php artisan smartpos:purge --days=7        # fuerza 7 días para todos
 * php artisan smartpos:purge --subscription=12
 * ```
 */
class PurgeSmartPosData extends Command
{
    /** Filas de eventos que se borran por sentencia (MySQL: DELETE … LIMIT). */
    public const EVENTS_PER_CHUNK = 1000;

    protected $signature = 'smartpos:purge
        {--days= : Forzar el número de días de retención para todos los negocios}
        {--dry-run : Mostrar qué se borraría sin borrar nada}
        {--subscription= : Limitar la purga a una suscripción concreta}';

    protected $description = 'Purga los eventos de detección y los recortes caducados de Caja Inteligente';

    public function handle(SmartCheckoutGalleryService $gallery, SmartPosSettingsService $settings): int
    {
        $forcedDays = $this->positiveIntegerOption('days');

        if ($forcedDays === false) {
            return self::FAILURE;
        }

        $onlySubscription = $this->positiveIntegerOption('subscription');

        if ($onlySubscription === false) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $subscriptionIds = $this->subscriptionIds($onlySubscription);

        // La tabla de ajustes no existe: el módulo no está instalado en esta
        // instalación y no hay nada que purgar. La tarea programada no puede
        // fallar por eso.
        if ($subscriptionIds === null) {
            $this->warn('El módulo Modo Caja Inteligente no está instalado (falta la tabla smartpos_settings). No se purgó nada.');

            return self::SUCCESS;
        }

        if ($subscriptionIds === []) {
            $this->warn('No hay negocios con Caja Inteligente configurado. No se purgó nada.');

            return self::SUCCESS;
        }

        $totalEvents = 0;
        $totalCrops = 0;
        $eventsUnavailable = false;
        $cropsUnavailable = false;

        foreach ($subscriptionIds as $subscriptionId) {
            $tenantRetentionDays = $this->retentionDaysFor($subscriptionId, $settings);
            $retentionDays = $forcedDays ?? $tenantRetentionDays;
            $threshold = now()->subDays($retentionDays);
            // El criterio de recortes vive en el servicio de la galería; aquí sólo
            // se mueve la fecha límite cuando el operador fuerza `--days`, para que
            // una sola invocación no deje recortes viejos atrás.
            $cropRetentionDays = $forcedDays ?? $tenantRetentionDays;

            try {
                $events = $dryRun
                    ? $this->countExpiredEvents($subscriptionId, $threshold)
                    : $this->purgeExpiredEvents($subscriptionId, $threshold);
            } catch (QueryException $exception) {
                if (! $eventsUnavailable) {
                    $this->warn('No se pudieron purgar los eventos de detección (¿falta la migración de smartpos_detection_events?). Se continúa con los recortes.');
                    $eventsUnavailable = true;
                }

                $events = 0;
            }

            try {
                $crops = $dryRun
                    ? $this->countExpiredCrops($subscriptionId, now()->subDays($cropRetentionDays))
                    : $gallery->purgeExpiredCrops($subscriptionId, $forcedDays);
            } catch (QueryException $exception) {
                if (! $cropsUnavailable) {
                    $this->warn('No se pudieron contar los recortes caducados (¿falta la migración de smartpos_product_samples?).');
                    $cropsUnavailable = true;
                }

                $crops = 0;
            }

            $totalEvents += $events;
            $totalCrops += $crops;

            $this->line(sprintf(
                '%s Suscripción %d · retención %d días · eventos %s: %d · recortes %s: %d',
                $dryRun ? '[simulación]' : '[purga]',
                $subscriptionId,
                $retentionDays,
                $dryRun ? 'a borrar' : 'borrados',
                $events,
                $dryRun ? 'a purgar' : 'purgados',
                $crops,
            ));
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(sprintf(
                'Resumen (simulación): %d negocio(s) · %d evento(s) a borrar · %d recorte(s) a purgar.',
                count($subscriptionIds),
                $totalEvents,
                $totalCrops,
            ));

            $this->warn('Modo simulación (--dry-run): no se borró nada.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Resumen: %d negocio(s) · %d evento(s) borrado(s) · %d recorte(s) purgado(s).',
            count($subscriptionIds),
            $totalEvents,
            $totalCrops,
        ));

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | SUSCRIPCIONES Y RETENCIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Negocios que se van a purgar: los que tienen fila en `smartpos_settings`,
     * o sólo el indicado con `--subscription`.
     *
     * Devuelve `null` cuando la tabla de ajustes no existe (módulo sin instalar),
     * que el llamador traduce en un aviso y un código de salida 0.
     *
     * @return array<int, int>|null
     */
    private function subscriptionIds(?int $onlySubscription): ?array
    {
        if ($onlySubscription !== null) {
            // La suscripción pedida se purga aunque no tenga fila de ajustes: el
            // operador lo ha pedido explícitamente y la retención cae al valor
            // por defecto.
            return [$onlySubscription];
        }

        try {
            return SmartPosSetting::query()
                ->orderBy('subscription_id')
                ->pluck('subscription_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        } catch (QueryException $exception) {
            return null;
        }
    }

    /**
     * Días de retención del negocio: el suyo, o el valor por defecto del
     * servicio cuando no hay fila o el valor no es positivo.
     */
    private function retentionDaysFor(int $subscriptionId, SmartPosSettingsService $settings): int
    {
        $retentionDays = (int) ($settings->forSubscription($subscriptionId)->retention_days ?? 0);

        return $retentionDays > 0
            ? $retentionDays
            : SmartPosSettingsService::DEFAULT_RETENTION_DAYS;
    }

    /*
    |--------------------------------------------------------------------------
    | EVENTOS
    |--------------------------------------------------------------------------
    */

    /**
     * Borra los eventos caducados del negocio en trozos y devuelve cuántos borró.
     *
     * `orderBy('id')->limit(1000)` dentro del bucle evita un `DELETE` gigante que
     * bloquee la tabla: en MySQL cada sentencia borra mil filas y el bucle se
     * repite hasta que no queda ninguna.
     */
    private function purgeExpiredEvents(int $subscriptionId, CarbonInterface $threshold): int
    {
        $deleted = 0;

        do {
            $chunk = SmartPosDetectionEvent::query()
                ->where('subscription_id', $subscriptionId)
                ->where('occurred_at', '<', $threshold)
                ->orderBy('id')
                ->limit(self::EVENTS_PER_CHUNK)
                ->delete();

            $deleted += $chunk;
        } while ($chunk > 0);

        return $deleted;
    }

    /**
     * Eventos que se borrarían en una simulación (no toca la base de datos).
     */
    private function countExpiredEvents(int $subscriptionId, CarbonInterface $threshold): int
    {
        return SmartPosDetectionEvent::query()
            ->where('subscription_id', $subscriptionId)
            ->where('occurred_at', '<', $threshold)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | RECORTES
    |--------------------------------------------------------------------------
    */

    /**
     * Recortes caducados que se purgarían en una simulación.
     *
     * Es sólo una consulta: en modo real la purga la hace
     * `SmartCheckoutGalleryService::purgeExpiredCrops()`, que además borra los
     * archivos, y llamarla aquí con `--dry-run` tendría efectos secundarios.
     */
    private function countExpiredCrops(int $subscriptionId, CarbonInterface $threshold): int
    {
        return SmartPosProductSample::query()
            ->forSubscription($subscriptionId)
            ->whereNotNull('crop_path')
            ->where('updated_at', '<', $threshold)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | OPCIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Lee una opción que, si viene, debe ser un entero >= 1.
     *
     * @return int|null|false `null` si no se pasó, `false` si no es válida.
     */
    private function positiveIntegerOption(string $name): int|null|false
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < 1) {
            $this->error(sprintf('La opción --%s debe ser un número entero mayor o igual a 1.', $name));

            return false;
        }

        return $validated;
    }
}
