<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\SmartPosDetectionEvent;
use App\Models\SmartPosProductSample;
use App\Models\SmartPosSetting;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutCatalogGalleryService;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Test transversal de aislamiento entre negocios del "Modo Caja Inteligente"
 * (Fase 5, hueco 4 del HANDOFF-fase-4-5.md §7).
 *
 * Las pruebas de cada fase ya comprueban el aislamiento del endpoint que
 * construyen, pero ninguna recorre **las 13 rutas** del módulo con dos negocios
 * completos. Esta lo hace: el negocio A (el del fixture compartido) crea un
 * producto con foto, una muestra con vector y recorte, un evento de detección y
 * su configuración; el negocio B recorre todas las rutas y nunca debe leer ni
 * escribir nada de A. Además se afirman dos cosas que no dependen de la base de
 * datos:
 *
 *  - **el contrato de las 13 rutas**: `auth` + `can:pos.access` + exactamente el
 *    `throttle` documentado (o ninguno, en las cinco excepciones de configuración
 *    del handoff §5 más `PUT /preferences`, que es el interruptor del negocio y
 *    nunca debe bloquearse por un limitador). Si alguien añade una ruta nueva sin
 *    candado, el inventario exacto de rutas hace fallar esta prueba;
 *  - **ningún permiso propio**: todas las rutas exigen `pos.access` y ninguna
 *    exige un permiso distinto (decisión «Sin permisos propios» de
 *    `docs/caja-inteligente/decisiones.md`).
 *
 * El throttle se desactiva en `setUp()` porque estas pruebas afirman la
 * **presencia** del middleware, no su comportamiento: el limitador va por usuario
 * y, con `RefreshDatabase` reutilizando ids dentro del mismo proceso PHP, el
 * presupuesto de 30/60/120 por minuto se filtraría entre pruebas (gotcha 11 del
 * handoff). También se aíslan los discos: los recortes en el disco privado y las
 * fotos del catálogo en el público.
 *
 * Nota sobre datos de A: la muestra se inserta con el modelo y no con
 * `POST /samples` para que la prueba no dependa de la extensión GD (el recorte
 * solo tiene que existir como archivo; su validación de JPEG ya la cubre
 * `SmartCheckoutGalleryTest`). El resto de datos de A (ajustes, báscula y evento)
 * se crean por la API real, que es justo el camino que B debe respetar.
 */
class SmartCheckoutTenantIsolationTest extends SmartCheckoutTestCase
{
    /**
     * JPEG mínimo (1x1) en base64. Se usa tal cual para las fotos del catálogo y
     * los recortes: el objetivo es que exista un archivo real y hermético, no que
     * sea una foto representativa.
     */
    private const TINY_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    /**
     * Las 13 rutas del módulo, por nombre: método, URI y `throttle` exacto.
     *
     * `null` significa «sin limitador, por decisión consciente»: los ajustes del
     * modo y de la báscula, el catálogo pesable y el interruptor del negocio no se
     * limitan porque son lecturas/escrituras de configuración poco frecuentes y,
     * en el caso de `PUT /preferences`, porque es el propio ON/OFF: bloquearlo
     * dejaría al negocio sin forma de apagar el módulo si un cliente se desboca.
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const ROUTES = [
        'pos.smart-checkout.preferences.update' => ['PUT', 'pos/smart-checkout/preferences', null],
        'pos.smart-checkout.scale.settings' => ['GET', 'pos/smart-checkout/scale/settings', null],
        'pos.smart-checkout.scale.settings.update' => ['PUT', 'pos/smart-checkout/scale/settings', null],
        'pos.smart-checkout.scale.products' => ['GET', 'pos/smart-checkout/scale/weighable-products', null],
        'pos.smart-checkout.settings' => ['GET', 'pos/smart-checkout/settings', null],
        'pos.smart-checkout.settings.update' => ['PUT', 'pos/smart-checkout/settings', null],
        'pos.smart-checkout.events.store' => ['POST', 'pos/smart-checkout/events', 'throttle:120,1'],
        'pos.smart-checkout.metrics.today' => ['GET', 'pos/smart-checkout/metrics/today', 'throttle:120,1'],
        'pos.smart-checkout.gallery' => ['GET', 'pos/smart-checkout/gallery', 'throttle:30,1'],
        'pos.smart-checkout.samples.store' => ['POST', 'pos/smart-checkout/samples', 'throttle:60,1'],
        'pos.smart-checkout.samples.destroy' => ['DELETE', 'pos/smart-checkout/samples/{sample}', 'throttle:60,1'],
        'pos.smart-checkout.gallery.candidates' => ['GET', 'pos/smart-checkout/gallery/candidates', 'throttle:120,1'],
        'pos.smart-checkout.gallery.product' => ['GET', 'pos/smart-checkout/gallery/products/{product}', 'throttle:120,1'],
    ];

    /** Negocio B y su usuario (el negocio A es `$this->branch` / `$this->user`). */
    private Branch $branchB;

    private User $userB;

    /** Producto de A con foto, muestra y recorte: objeto central del aislamiento. */
    private Product $productA;

    /** Producto a granel de A, para el catálogo pesable. */
    private Product $bulkProductA;

    /** Producto con foto de B (su único candidato a sembrar la galería). */
    private Product $candidateB;

    /** Producto a granel de B, para su catálogo pesable. */
    private Product $bulkProductB;

    private SmartPosProductSample $sampleA;

    /** Ruta del recorte de A dentro del disco privado, para comprobar que no se borra. */
    private string $cropPathA;

    protected function setUp(): void
    {
        parent::setUp();

        // Aquí se afirma el middleware, no su comportamiento (gotcha 11 del
        // handoff: el limitador por usuario se filtra entre pruebas).
        $this->withoutMiddleware(ThrottleRequests::class);

        // Discos herméticos: nada se escribe en storage/app durante las pruebas.
        Storage::fake(SmartCheckoutGalleryService::CROP_DISK);
        Storage::fake('public');

        $this->branchB = $this->makeTenant();
        $this->userB = User::factory()->create(['branch_id' => $this->branchB->id]);

        // El fixture crea los permisos una sola vez y el usuario de B se crea
        // después: por el `Gate::before` de AppServiceProvider un usuario sin
        // roles (el dueño) pasa `pos.access` si el permiso pertenece a un módulo
        // contratado, y la suscripción de B contrata `module_pos`. Se comprueba
        // explícitamente y, si algún día dejara de cumplirse, se le asigna el rol
        // como hacen los demás tests del repositorio.
        if (! $this->userB->can('pos.access')) {
            $role = Role::create(['name' => 'Cajero de B', 'branch_id' => $this->branchB->id]);
            $role->givePermissionTo(Permission::query()->where('name', 'pos.access')->firstOrFail());
            $this->userB->assignRole($role);
            $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->assertTrue(
            $this->userB->can('pos.access'),
            'El usuario de B necesita pos.access: es el único gate del módulo (decisiones.md).',
        );

        // Modo y báscula encendidos en los dos negocios: sin esto las rutas
        // contestarían 403 y el test no probaría el aislamiento de datos.
        $this->enableSmartCheckout();
        $this->enableSmartCheckout($this->branchB->subscription_id);
        $this->enableScaleFor((int) $this->branch->subscription_id);
        $this->enableScaleFor((int) $this->branchB->subscription_id);

        /*
        |--------------------------------------------------------------------------
        | DATOS DEL NEGOCIO A (el fixture): producto, muestra con recorte y evento
        |--------------------------------------------------------------------------
        */

        $this->productA = $this->makeProduct($this->branch);
        $this->addImage($this->productA, 'candidato-a.jpg');

        $this->bulkProductA = $this->makeProduct($this->branch, [
            'is_bulk' => true,
            'measure_unit' => 'kg',
        ]);

        $this->sampleA = $this->addSample($this->productA, $this->branch, 'muestra-a.jpg');
        $this->cropPathA = (string) $this->sampleA->crop_path;

        // La configuración de A es distinta de la de B a propósito: así un GET de
        // B que devolviera los ajustes de A se detecta sin ambigüedad.
        $this->putJson(route('pos.smart-checkout.settings.update'), $this->validSmartCheckoutPayload())->assertOk();
        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->scalePayloadA())->assertOk();

        // Un evento de detección de A de hoy: las métricas de B deben seguir a cero.
        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())->assertCreated();

        /*
        |--------------------------------------------------------------------------
        | DATOS DEL NEGOCIO B (propios, para que el vacío no sea trivial)
        |--------------------------------------------------------------------------
        */

        $this->candidateB = $this->makeProduct($this->branchB);
        $this->addImage($this->candidateB, 'candidato-b.jpg');

        $this->bulkProductB = $this->makeProduct($this->branchB, [
            'is_bulk' => true,
            'measure_unit' => 'kg',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RECORRIDO DE LAS 13 RUTAS CON EL USUARIO B
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_second_tenant_walks_the_thirteen_routes_without_reading_or_writing_the_first_ones_data(): void
    {
        $this->actingAs($this->userB);

        /*
        | GET /gallery
        | La galería se acota por suscripción: A tiene una muestra con recorte,
        | así que el lote de B debe llegar vacío (sin items y sin vectores).
        */
        $galleryB = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();

        $this->assertSame(0, $galleryB->json('count'), 'La galería de B no puede contener la muestra de A.');
        $this->assertSame(0, $galleryB->json('total'), 'El total de la galería de B debe ser cero.');
        $this->assertSame('', $galleryB->json('vectors'), 'B no puede recibir el buffer de vectores de A.');
        $this->assertSame([], $galleryB->json('items'), 'B no puede recibir los items de la galería de A.');
        $this->assertSame('0-0-0', $galleryB->json('gallery_version'), 'La versión de la galería de B no puede delatar la de A.');

        /*
        | GET /gallery/candidates
        | B tiene un candidato propio con foto; el producto de A (de otra sucursal)
        | no puede aparecer, ni siquiera con filter=all.
        */
        $candidatesB = $this->getJson(route('pos.smart-checkout.gallery.candidates', ['filter' => 'all']))->assertOk();
        $candidateIdsB = array_map(static fn ($id): int => (int) $id, array_column($candidatesB->json('items') ?? [], 'product_id'));

        $this->assertContains($this->candidateB->id, $candidateIdsB, 'El candidato propio de B debe aparecer: el vacío no puede ser la razón del test.');
        $this->assertNotContains($this->productA->id, $candidateIdsB, 'Los candidatos de B no pueden incluir el producto de A.');

        /*
        | GET /gallery/products/{product}
        | Un producto de otra sucursal contesta 404 (nunca 403): el endpoint no
        | debe confirmar que el id ajeno existe.
        */
        $foreignProduct = $this->getJson(route('pos.smart-checkout.gallery.product', $this->productA))->assertNotFound();

        $this->assertSame(
            'El producto indicado no existe en la sucursal del cajero.',
            $foreignProduct->json('message'),
            'El 404 de un producto ajeno debe ser el del contrato del módulo.',
        );

        /*
        | DELETE /samples/{sample}
        | La muestra de A no se puede borrar desde B y debe seguir existiendo, con
        | su recorte intacto en el disco privado.
        */
        $this->deleteJson(route('pos.smart-checkout.samples.destroy', $this->sampleA->id))->assertNotFound();

        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $this->sampleA->id]);
        $this->assertTrue(
            Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($this->cropPathA),
            'El DELETE fallido de B no puede borrar el recorte de A.',
        );

        /*
        | POST /samples
        | Un `product_id` de la sucursal de A se rechaza con 422 (producto de otra
        | sucursal) y no crea ninguna fila nueva.
        */
        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($this->productA))
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->assertDatabaseCount('smartpos_product_samples', 1);
        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $this->sampleA->id]);

        /*
        | GET /metrics/today
        | A registró un evento hoy; B no tiene ninguno, así que sus indicadores
        | deben ser ceros (no la suma de los dos negocios).
        */
        $metricsB = $this->getJson(route('pos.smart-checkout.metrics.today'))->assertOk();
        $todayB = $metricsB->json('today');

        $this->assertSame(0, $todayB['analyses'], 'B no puede contar los análisis de A.');
        $this->assertSame(0, $todayB['detected'], 'B no puede sumar los objetos detectados por A.');
        $this->assertNull($todayB['last_event_at'], 'B no puede ver la fecha del último evento de A.');

        /*
        | POST /events
        | B registra su propio evento: la fila debe llevar la suscripción y la
        | sucursal de B (nunca las del payload ni las de A) y cada negocio debe
        | quedarse con exactamente un evento.
        */
        $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())
            ->assertCreated()
            ->assertJsonPath('event_type', 'analysis');

        $eventB = SmartPosDetectionEvent::query()->where('user_id', $this->userB->id)->firstOrFail();

        $this->assertSame((int) $this->branchB->subscription_id, (int) $eventB->subscription_id, 'El evento de B debe pertenecer a la suscripción de B.');
        $this->assertSame((int) $this->branchB->id, (int) $eventB->branch_id, 'El evento de B debe pertenecer a la sucursal de B.');
        $this->assertSame(
            1,
            SmartPosDetectionEvent::query()->where('subscription_id', $this->branch->subscription_id)->count(),
            'El evento de B no puede tocar los eventos de A.',
        );
        $this->assertSame(
            1,
            SmartPosDetectionEvent::query()->where('subscription_id', $this->branchB->subscription_id)->count(),
        );

        // Ahora B tiene un evento propio: sus métricas suman 1, no 2 (si sumaran
        // los de A, el aislamiento de `todayMetrics()` estaría roto).
        $this->assertSame(
            1,
            $this->getJson(route('pos.smart-checkout.metrics.today'))->json('today.analyses'),
            'Las métricas de B deben contar sólo su evento.',
        );

        /*
        | GET /settings y PUT /settings
        | B ve los valores por defecto (no los de A) y su PUT sólo escribe su fila.
        */
        $settingsB = $this->getJson(route('pos.smart-checkout.settings'))->assertOk();

        $this->assertSame(0.85, $settingsB->json('settings.thresholds.green_min'), 'B debe ver el umbral por defecto, no el 0.9 de A.');
        $this->assertTrue($settingsB->json('settings.auto_accept'), 'B debe ver su default, no el auto_accept=false de A.');
        $this->assertSame('mock', $settingsB->json('settings.model_version'), 'B debe ver su versión de modelo por defecto, no la de A.');

        $this->putJson(route('pos.smart-checkout.settings.update'), $this->smartCheckoutPayloadB())
            ->assertOk()
            ->assertJsonPath('settings.thresholds.green_min', 0.8)
            ->assertJsonPath('settings.model_version', 'modelo-b');

        $rowA = $this->settingsRow((int) $this->branch->subscription_id);
        $rowB = $this->settingsRow((int) $this->branchB->subscription_id);

        $this->assertSame(0.9, (float) $rowA->smart_checkout_settings['thresholds']['green_min'], 'El PUT de B no puede cambiar los ajustes de A.');
        $this->assertSame('mock-v2', (string) $rowA->smart_checkout_settings['model_version'], 'La versión del modelo de A no puede cambiar.');
        $this->assertSame('cam-1', (string) $rowA->smart_checkout_settings['cameras'][0]['id'], 'Las cámaras de A no pueden cambiar.');
        $this->assertSame(0.8, (float) $rowB->smart_checkout_settings['thresholds']['green_min'], 'B debe guardar su propio umbral.');
        $this->assertSame('modelo-b', (string) $rowB->smart_checkout_settings['model_version'], 'B debe guardar su propia versión de modelo.');

        /*
        | GET /scale/settings y PUT /scale/settings
        | Igual con la báscula: B lee y escribe su fila, A conserva la suya.
        */
        $scaleSettingsB = $this->getJson(route('pos.smart-checkout.scale.settings'))->assertOk();

        $this->assertNull($scaleSettingsB->json('settings.active_profile_id'), 'B no puede ver el perfil de báscula de A.');
        $this->assertSame([], $scaleSettingsB->json('settings.profiles'), 'B no puede ver los perfiles de báscula de A.');
        $this->assertSame(5, $scaleSettingsB->json('settings.stability.readings'), 'B debe ver sus lecturas por defecto, no las 7 de A.');

        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->scalePayloadB())
            ->assertOk()
            ->assertJsonPath('settings.active_profile_id', 'bascula-b')
            ->assertJsonPath('settings.stability.readings', 2);

        $rowA = $this->settingsRow((int) $this->branch->subscription_id);
        $rowB = $this->settingsRow((int) $this->branchB->subscription_id);

        $this->assertSame('bascula-a', (string) $rowA->scale_settings['active_profile_id'], 'El PUT de báscula de B no puede cambiar el perfil activo de A.');
        $this->assertSame(7, (int) $rowA->scale_settings['stability']['readings'], 'La estabilidad de A no puede cambiar.');
        $this->assertSame('bascula-b', (string) $rowB->scale_settings['active_profile_id'], 'B debe guardar su propio perfil.');
        $this->assertSame(2, (int) $rowB->scale_settings['stability']['readings'], 'B debe guardar su propia estabilidad.');

        /*
        | GET /scale/weighable-products
        | Sólo el producto a granel de B; el de A es de otra sucursal.
        */
        $weighableB = $this->getJson(route('pos.smart-checkout.scale.products'))->assertOk();
        $weighableIdsB = array_map(static fn ($id): int => (int) $id, array_column($weighableB->json('data') ?? [], 'id'));

        $this->assertSame([$this->bulkProductB->id], $weighableIdsB, 'El catálogo pesable de B debe tener sólo su producto a granel.');
        $this->assertNotContains($this->bulkProductA->id, $weighableIdsB, 'B no puede ver el producto pesable de A.');

        /*
        | PUT /preferences
        | El interruptor de B cambia su fila y nunca la de A. Se deja para el final
        | a propósito: apagar la báscula de B antes de las lecturas las haría 403.
        */
        $this->putJson(route('pos.smart-checkout.preferences.update'), [
            'scale_enabled' => false,
            'smart_checkout_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('scale_enabled', false)
            ->assertJsonPath('smart_checkout_enabled', true);

        $rowA = $this->settingsRow((int) $this->branch->subscription_id);
        $rowB = $this->settingsRow((int) $this->branchB->subscription_id);

        $this->assertTrue((bool) $rowA->scale_enabled, 'Las preferencias de A no pueden cambiar desde B.');
        $this->assertTrue((bool) $rowA->smart_checkout_enabled, 'El modo de A no puede cambiar desde B.');
        $this->assertFalse((bool) $rowB->scale_enabled, 'B debe guardar su propio interruptor de báscula.');

        /*
        | CONTROL FINAL: A sigue viendo lo suyo después de todo el recorrido.
        | Sin esta comprobación, un fallo global (por ejemplo, que A tampoco vea
        | nada) haría pasar las aserciones anteriores por la razón equivocada.
        */
        $this->actingAs($this->user);

        $galleryA = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();

        $this->assertSame(1, $galleryA->json('count'), 'A debe seguir viendo su muestra después del recorrido de B.');
        $this->assertSame($this->productA->id, $galleryA->json('items.0.product_id'), 'La galería de A debe seguir apuntando a su producto.');
        $this->assertSame(
            1,
            $this->getJson(route('pos.smart-checkout.metrics.today'))->json('today.analyses'),
            'Las métricas de A deben seguir contando sólo su evento, no el de B.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EL FLAG APAGADO EN B NO PUEDE DEJAR 500 NI 200
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_flag_gated_routes_answer_403_while_the_mode_is_off_for_the_second_tenant(): void
    {
        $this->actingAs($this->userB);

        // El interruptor es la excepción: funciona con todo apagado (si no, el
        // negocio no podría volver a encender el módulo).
        $this->putJson(route('pos.smart-checkout.preferences.update'), [
            'scale_enabled' => false,
            'smart_checkout_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('scale_enabled', false)
            ->assertJsonPath('smart_checkout_enabled', false);

        $smartCheckoutMessage = 'El Modo Caja Inteligente no está habilitado para este negocio.';
        $scaleMessage = 'La báscula no está habilitada para este negocio.';

        // Las 12 rutas que dependen de un flag (todas menos `PUT /preferences`).
        // Los payloads son válidos a propósito: con uno inválido el FormRequest
        // contestaría 422 antes de que el controlador mire el flag, y lo que se
        // quiere probar aquí es el 403, no la validación.
        $requests = [
            'GET /gallery' => [$smartCheckoutMessage, fn () => $this->getJson(route('pos.smart-checkout.gallery'))],
            'POST /samples' => [$smartCheckoutMessage, fn () => $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($this->productA))],
            'DELETE /samples/{sample}' => [$smartCheckoutMessage, fn () => $this->deleteJson(route('pos.smart-checkout.samples.destroy', $this->sampleA->id))],
            'GET /gallery/candidates' => [$smartCheckoutMessage, fn () => $this->getJson(route('pos.smart-checkout.gallery.candidates'))],
            'GET /gallery/products/{product}' => [$smartCheckoutMessage, fn () => $this->getJson(route('pos.smart-checkout.gallery.product', $this->productA))],
            'GET /settings' => [$smartCheckoutMessage, fn () => $this->getJson(route('pos.smart-checkout.settings'))],
            'PUT /settings' => [$smartCheckoutMessage, fn () => $this->putJson(route('pos.smart-checkout.settings.update'), $this->smartCheckoutPayloadB())],
            'POST /events' => [$smartCheckoutMessage, fn () => $this->postJson(route('pos.smart-checkout.events.store'), $this->eventPayload())],
            'GET /metrics/today' => [$smartCheckoutMessage, fn () => $this->getJson(route('pos.smart-checkout.metrics.today'))],
            'GET /scale/settings' => [$scaleMessage, fn () => $this->getJson(route('pos.smart-checkout.scale.settings'))],
            'PUT /scale/settings' => [$scaleMessage, fn () => $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->scalePayloadB())],
            'GET /scale/weighable-products' => [$scaleMessage, fn () => $this->getJson(route('pos.smart-checkout.scale.products'))],
        ];

        foreach ($requests as $label => [$message, $call]) {
            $response = $call();

            $response->assertForbidden();
            $this->assertSame(
                $message,
                $response->json('message'),
                $label.' debe contestar el 403 del flag apagado, no un 500 ni un 200.',
            );
        }

        // Nada se escribió mientras estaban apagadas: A conserva su muestra, su
        // evento y su configuración.
        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $this->sampleA->id]);
        $this->assertSame(
            1,
            SmartPosDetectionEvent::query()->where('subscription_id', $this->branch->subscription_id)->count(),
        );
        $this->assertSame(0, SmartPosDetectionEvent::query()->where('subscription_id', $this->branchB->subscription_id)->count());

        // Y el interruptor vuelve a encender el módulo (sin throttle, por diseño).
        $this->putJson(route('pos.smart-checkout.preferences.update'), [
            'scale_enabled' => true,
            'smart_checkout_enabled' => true,
        ])->assertOk();

        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | CONTRATO ESTRUCTURAL DE LAS 13 RUTAS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_thirteen_routes_share_the_pos_gate_and_the_expected_rate_limit(): void
    {
        // Inventario exacto: se filtra por **URI**, no por nombre, para que una
        // ruta añadida al módulo con otro nombre (o sin nombre) también rompa la
        // prueba. Si aparece una décimo cuarta ruta sin candado, el assert falla.
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'pos/smart-checkout'))
            ->map(static fn ($route): ?string => $route->getName())
            ->sort()
            ->values()
            ->all();

        $expected = array_keys(self::ROUTES);
        sort($expected);

        $this->assertSame(
            $expected,
            $registered,
            'El módulo debe exponer exactamente las 13 rutas documentadas; una ruta nueva sin candado rompe esta prueba.',
        );

        foreach (self::ROUTES as $name => [$method, $uri, $throttle]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, 'Falta la ruta '.$name.'.');

            $middleware = $route->gatherMiddleware();

            // Sin autenticación no hay negocio: el tenant sale siempre del usuario.
            $this->assertContains('auth', $middleware, $name.' debe exigir autenticación.');

            // Único permiso del módulo (decisión «Sin permisos propios»).
            $this->assertContains('can:pos.access', $middleware, $name.' debe exigir pos.access.');

            $this->assertContains($method, $route->methods(), $name.' debe aceptar '.$method.'.');
            $this->assertSame($uri, $route->uri(), 'La URI de '.$name.' no es la del contrato.');

            $throttles = array_values(array_filter(
                $middleware,
                static fn (string $middlewareName): bool => str_starts_with($middlewareName, 'throttle:'),
            ));

            if ($throttle === null) {
                $this->assertSame(
                    [],
                    $throttles,
                    $name.' no debe llevar limitador: es una de las excepciones conscientes del módulo.',
                );
            } else {
                $this->assertSame([$throttle], $throttles, $name.' debe llevar exactamente '.$throttle.'.');
            }

            // Ninguna ruta puede exigir un permiso o un rol distinto de pos.access:
            // si aparece otro `can:`/`permission:`, el módulo estaría introduciendo
            // permisos propios (lo que decisiones.md prohíbe).
            $permissions = array_values(array_filter(
                $middleware,
                static fn (string $middlewareName): bool => str_starts_with($middlewareName, 'can:')
                    || str_starts_with($middlewareName, 'permission:'),
            ));

            $this->assertSame(
                ['can:pos.access'],
                $permissions,
                $name.' sólo puede exigir pos.access; el módulo no introduce permisos nuevos.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AISLAMIENTO DEL ETAG
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_gallery_etag_is_never_shared_between_tenants(): void
    {
        $this->actingAs($this->user);

        $galleryA = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();
        $etagA = $galleryA->headers->get('ETag');

        $this->assertSame(1, $galleryA->json('count'));
        $this->assertNotNull($etagA, 'La galería de A debe traer ETag.');

        $this->actingAs($this->userB);

        $galleryB = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();
        $etagB = $galleryB->headers->get('ETag');

        $this->assertSame(0, $galleryB->json('count'));
        $this->assertNotNull($etagB, 'La galería vacía de B también debe traer ETag.');

        // Aunque el cuerpo de B estuviera vacío, su validador no puede coincidir
        // con el de A: el ETag incorpora la suscripción justamente para que un
        // caché compartido entre terminales no sirva datos de otro negocio.
        $this->assertNotSame(
            $etagA,
            $etagB,
            'La galería de A y la de B no pueden compartir ETag.',
        );
        $this->assertNotSame($galleryA->json('gallery_version'), $galleryB->json('gallery_version'));

        // Revalidar en B con el ETag de A no puede contestar 304: si lo hiciera,
        // B se quedaría con el cuerpo cacheado de A.
        $this->withHeaders(['If-None-Match' => $etagA])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 0);

        // Y al revés: A no revalida contra el ETag de B.
        $this->actingAs($this->user)
            ->withHeaders(['If-None-Match' => $etagB])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | CONTROL POSITIVO: A VE Y BORRA LO SUYO
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_first_tenant_can_read_its_product_and_delete_its_sample(): void
    {
        $this->actingAs($this->user);

        // 1) La galería de A sí tiene su muestra (el vacío de B no es un fallo global).
        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.product_id', $this->productA->id);

        // 2) El producto de A sí es visible para A, con su muestra y su recorte.
        $this->getJson(route('pos.smart-checkout.gallery.product', $this->productA))
            ->assertOk()
            ->assertJsonPath('product_id', $this->productA->id)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('samples.0.id', $this->sampleA->id)
            ->assertJsonPath('samples.0.has_crop', true);

        // 3) A lo ve en candidatos y en el catálogo pesable de su sucursal.
        $candidatesA = $this->getJson(route('pos.smart-checkout.gallery.candidates', ['filter' => 'all']))->assertOk();
        $candidateIdsA = array_map(static fn ($id): int => (int) $id, array_column($candidatesA->json('items') ?? [], 'product_id'));

        $this->assertContains($this->productA->id, $candidateIdsA);

        $weighableA = $this->getJson(route('pos.smart-checkout.scale.products'))->assertOk();
        $weighableIdsA = array_map(static fn ($id): int => (int) $id, array_column($weighableA->json('data') ?? [], 'id'));

        $this->assertContains($this->bulkProductA->id, $weighableIdsA);

        // 4) Sus métricas, ajustes y evento son los suyos.
        $this->assertSame(
            1,
            $this->getJson(route('pos.smart-checkout.metrics.today'))->json('today.analyses'),
        );
        $this->getJson(route('pos.smart-checkout.settings'))
            ->assertOk()
            ->assertJsonPath('settings.thresholds.green_min', 0.9);

        // 5) Y sí puede borrar su propia muestra, con su recorte.
        $this->deleteJson(route('pos.smart-checkout.samples.destroy', $this->sampleA->id))->assertNoContent();

        $this->assertDatabaseMissing('smartpos_product_samples', ['id' => $this->sampleA->id]);
        $this->assertFalse(
            Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($this->cropPathA),
            'El borrado propio debe eliminar también el recorte de la muestra.',
        );

        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('gallery_version', '0-0-0');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Producto de la sucursal indicada, vinculado a su pivote `branch_product`
     * (el criterio del catálogo del POS, de los candidatos y del pesaje).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(Branch $branch, array $overrides = []): Product
    {
        return Product::factory()
            ->withStock($branch->id)
            ->create(array_merge([
                'branch_id' => $branch->id,
                'show_in_pos' => true,
            ], $overrides));
    }

    /**
     * Foto general del producto en el disco público falso.
     */
    private function addImage(Product $product, string $fileName): void
    {
        $product->addMediaFromString($this->tinyJpeg())
            ->usingFileName($fileName)
            ->toMediaCollection(SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION);
    }

    /**
     * Muestra de la galería de una sucursal, con su recorte en el disco privado.
     */
    private function addSample(Product $product, Branch $branch, string $cropFileName): SmartPosProductSample
    {
        $cropPath = SmartCheckoutGalleryService::CROP_DIRECTORY
            .'/'.$branch->subscription_id
            .'/'.$product->id
            .'/'.$cropFileName;

        Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->put($cropPath, $this->tinyJpeg());

        return SmartPosProductSample::create([
            'subscription_id' => $branch->subscription_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'model_version' => 'mock',
            'embedding' => $this->unitEmbedding(),
            'dim' => SmartCheckoutGalleryService::DIM_DEFAULT,
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
            'crop_path' => $cropPath,
            'quality' => 0.91,
        ]);
    }

    /**
     * Habilita la báscula de una suscripción sin tocar su flag de Caja
     * Inteligente: `enableSmartCheckout()` ya creó la fila y aquí sólo se
     * actualiza `scale_enabled`.
     */
    private function enableScaleFor(int $subscriptionId): void
    {
        SmartPosSetting::query()->updateOrCreate(
            ['subscription_id' => $subscriptionId],
            ['scale_enabled' => true],
        );
    }

    private function settingsRow(int $subscriptionId): SmartPosSetting
    {
        return SmartPosSetting::query()->where('subscription_id', $subscriptionId)->firstOrFail();
    }

    /**
     * Payload de `POST /samples` válido para el modelo simulado.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function samplePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'model_version' => 'mock',
            'dim' => SmartCheckoutGalleryService::DIM_DEFAULT,
            'vector' => base64_encode($this->unitEmbedding()),
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
        ], $overrides);
    }

    /**
     * Payload de `POST /events` válido (un análisis del día).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => (string) Str::uuid(),
            'event_type' => 'analysis',
            'objects' => 3,
            'auto_accepted' => 1,
            'confirmed' => 1,
            'corrected' => 1,
            'unknown' => 0,
            'latency_ms' => 120,
            'model_version' => 'mock',
        ], $overrides);
    }

    /**
     * Ajustes de báscula de A, distintos de los de B para detectar fugas.
     *
     * @return array<string, mixed>
     */
    private function scalePayloadA(): array
    {
        return [
            'active_profile_id' => 'bascula-a',
            'profiles' => [
                [
                    'id' => 'bascula-a',
                    'name' => 'Báscula de A',
                    'baudRate' => 9600,
                    'dataBits' => 8,
                    'stopBits' => 1,
                    'parity' => 'none',
                    'terminator' => 'lf',
                    'pattern' => '/^(-?\d+(?:\.\d+)?)\s*(kg|g)/i',
                    'weightGroup' => 1,
                    'unitGroup' => 2,
                    'stableGroup' => 3,
                    'unit' => 'kg',
                    'notes' => 'Báscula del negocio A',
                ],
            ],
            'stability' => ['readings' => 7, 'tolerance_g' => 5],
            'unit' => 'kg',
            'driver' => 'manual',
        ];
    }

    /**
     * Ajustes de báscula de B: sin perfiles, para que el PUT de B no pueda
     * confundirse con el de A.
     *
     * @return array<string, mixed>
     */
    private function scalePayloadB(): array
    {
        return [
            'active_profile_id' => 'bascula-b',
            'profiles' => [],
            'stability' => ['readings' => 2, 'tolerance_g' => 1],
            'unit' => 'g',
            'driver' => 'simulated',
        ];
    }

    /**
     * Ajustes del modo de B, con valores distintos a los que A guardó en
     * `setUp()` a través de `validSmartCheckoutPayload()`.
     *
     * @return array<string, mixed>
     */
    private function smartCheckoutPayloadB(): array
    {
        return [
            'thresholds' => [
                'green_min' => 0.8,
                'green_margin' => 0.1,
                'yellow_min' => 0.6,
            ],
            'auto_accept' => true,
            'cameras' => [
                [
                    'id' => 'cam-b',
                    'label' => 'Cámara del negocio B',
                    'mode' => 'overlap',
                    'resolution' => '640x480',
                ],
            ],
            'max_cameras_per_cycle' => 1,
            'save_crops' => true,
            'model_version' => 'modelo-b',
        ];
    }

    /**
     * Vector unitario de 384 componentes empaquetado como float32 little-endian,
     * el formato binario que guarda la galería.
     */
    private function unitEmbedding(): string
    {
        $dim = SmartCheckoutGalleryService::DIM_DEFAULT;

        // El spread es imprescindible: `pack('g*', $array)` empaqueta un solo
        // float (convierte el array a 1.0) y el servicio descartaría la fila por
        // longitud incorrecta al servir la galería.
        return pack('g*', ...array_fill(0, $dim, 1 / sqrt($dim)));
    }

    private function tinyJpeg(): string
    {
        return (string) base64_decode(self::TINY_JPEG_BASE64, true);
    }
}
