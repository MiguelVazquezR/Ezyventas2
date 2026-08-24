<?php

namespace Database\Seeders;

use App\Enums\PlanItemType;
use App\Models\PlanItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanItemSeeder extends Seeder
{
    public function run(): void
    {
        PlanItem::query()->delete();

        // Módulos
        $modules = [
            [
                'key' => 'module_pos',
                'name' => 'Punto de Venta',
                'price' => 130,
                'icon' => 'pi pi-shop',
                'description' => 'Módulo principal del sistema. Permite registrar ventas con un flujo de caja completo: escanear productos, aplicar descuentos, seleccionar método de pago y emitir tickets de compra. Actívalo desde el primer día para comenzar a operar tu negocio en EzyVentas.',
            ],
            [
                'key' => 'module_financial_reports',
                'name' => 'Reportes financieros',
                'price' => 25,
                'icon' => 'pi pi-chart-bar',
                'description' => 'Dashboard con gráficas, KPIs y reportes exportables de ingresos, gastos, utilidades y flujo de caja. Actívalo cuando necesites analizar la salud financiera de tu negocio más allá del cierre de caja diario.',
            ],
            [
                'key' => 'module_transactions',
                'name' => 'Historial de Ventas',
                'price' => 0,
                'icon' => 'pi pi-history',
                'description' => 'Registro cronológico de todas las ventas realizadas en el sistema. Permite buscar, filtrar y consultar el detalle de cualquier venta pasada.',
            ],
            [
                'key' => 'module_products',
                'name' => 'Productos',
                'price' => 0,
                'icon' => 'pi pi-barcode',
                'description' => 'Catálogo de productos con precios, códigos de barras, inventario y categorías. Centraliza toda tu oferta comercial para usarla en el punto de venta, cotizaciones y facturación.',
            ],
            [
                'key' => 'module_expenses',
                'name' => 'Gastos',
                'price' => 0,
                'icon' => 'pi pi-arrow-up-right',
                'description' => 'Registro y categorización de egresos del negocio (insumos, renta, nómina, servicios). Permite adjuntar comprobantes y conciliar gastos contra ingresos.',
            ],
            [
                'key' => 'module_customers',
                'name' => 'Clientes',
                'price' => 30,
                'icon' => 'pi pi-users',
                'description' => 'Directorio completo de clientes con historial de compras, saldo pendiente, datos fiscales y segmentación. Actívalo cuando necesites llevar un control detallado de tus compradores, ofrecer crédito o enviar facturas a receptores frecuentes.',
            ],
            [
                'key' => 'module_services',
                'name' => 'Servicios',
                'price' => 50,
                'icon' => 'pi pi-wrench',
                'description' => 'Gestión de catálogo y órdenes de servicio/reparaciones. Permite registrar dispositivos, asignar técnicos, dar seguimiento al estatus y entregar al cliente con ticket de recepción. Actívalo si tu negocio ofrece reparaciones, instalaciones o servicios técnicos.',
            ],
            [
                'key' => 'module_quotes',
                'name' => 'Cotizaciones',
                'price' => 35,
                'icon' => 'pi pi-file-check',
                'description' => 'Genera presupuestos profesionales para tus clientes con validez configurable. Convierte cualquier cotización aprobada en una venta o factura con un solo clic. Actívalo si necesitas enviar propuestas formales antes de cerrar ventas.',
            ],
            [
                'key' => 'module_billing',
                'name' => 'Facturación',
                'price' => 20,
                'icon' => 'pi pi-receipt',
                'description' => 'Emisión de facturas electrónicas CFDI 4.0 ante el SAT a través del PAC SW Sapien. Incluye timbrado, cancelación, descarga de XML/PDF, perfiles fiscales multi-RFC y carga de certificados CSD. Actívalo cuando estés obligado a facturar o tus clientes te soliciten comprobantes fiscales.',
            ],
            [
                'key' => 'module_cash_registers',
                'name' => 'Cajas',
                'price' => 0,
                'icon' => 'pi pi-dollar',
                'description' => 'Control de cajas registradoras con apertura, cierre, cortes de caja y conciliación de efectivo. Cada caja opera de forma independiente y permite asignar usuarios autorizados.',
            ],
            [
                'key' => 'module_settings',
                'name' => 'Configuraciones',
                'price' => 0,
                'icon' => 'pi pi-cog',
                'description' => 'Panel de configuración general del sistema, personalización a nivel de usuario y sucursal.',
            ],
            [
                'key' => 'module_online_store',
                'name' => 'Tienda en línea',
                'price' => 50.00,
                'icon' => 'pi pi-globe',
                'description' => 'Publica tu catálogo de productos en una tienda digital para que tus clientes compren desde cualquier lugar. Sincroniza inventario y ventas en tiempo real con tu sistema EzyVentas. Actívalo cuando quieras vender en línea sin duplicar esfuerzos de gestión.',
            ],
            [
                'key' => 'module_ai_agent',
                'name' => 'Asistente IA',
                'price' => 0.00,
                'icon' => 'pi pi-sparkles',
                'description' => 'Agente de inteligencia artificial integrado que te ayuda a crear productos, interpretar reportes, generar descripciones y automatizar tareas repetitivas con lenguaje natural. Actívalo gratis por tiempo limitado para aumentar tu productividad.',
            ],
        ];

        foreach ($modules as $module) {
            PlanItem::create([
                'key' => $module['key'],
                'type' => PlanItemType::MODULE,
                'name' => $module['name'],
                'description' => $module['description'],
                'monthly_price' => $module['price'],
                'meta' => ['icon' => $module['icon']],
            ]);
        }

        // Límites
        $limits = [
            [
                'key' => 'limit_branches',
                'name' => 'Sucursales',
                'price' => 30,
                'quantity' => 1,
                'description' => 'Número de sucursales o ubicaciones físicas que puedes registrar en el sistema. Cada sucursal tiene su propio inventario, cajas y usuarios. Aumenta este límite cuando abras una nueva ubicación de tu negocio.',
            ],
            [
                'key' => 'limit_users',
                'name' => 'Usuarios',
                'price' => 7.5,
                'quantity' => 1,
                'description' => 'Cantidad de empleados o colaboradores que pueden acceder al sistema con su propio usuario y permisos. Aumenta este límite conforme crezca tu equipo de trabajo.',
            ],
            [
                'key' => 'limit_products',
                'name' => 'Productos',
                'price' => 1.5,
                'quantity' => 100,
                'description' => 'Cantidad máxima de productos que puedes tener registrados en tu catálogo. Aumenta este límite si tu inventario supera la capacidad actual de tu plan.',
            ],
            [
                'key' => 'limit_services',
                'name' => 'Servicios',
                'price' => 1.5,
                'quantity' => 100,
                'description' => 'Cantidad máxima de servicios o reparaciones que ofreces en tu negocio. Aumenta este límite si tu oferta de servicios supera la capacidad actual de tu plan.',
            ],
            [
                'key' => 'limit_cash_registers',
                'name' => 'Cajas Registradoras',
                'price' => 7.5,
                'quantity' => 1,
                'description' => 'Número de cajas registradoras que pueden operar simultáneamente en tu negocio. Aumenta este límite cuando necesites más puntos de cobro para atender a más clientes a la vez.',
            ],
            [
                'key' => 'limit_print_templates',
                'name' => 'Plantillas personalizadas',
                'price' => 3,
                'quantity' => 1,
                'description' => 'Cantidad de plantillas de impresión personalizadas que puedes diseñar para tus tickets, cotizaciones y facturas. Aumenta este límite si necesitas distintos formatos de impresión para diferentes tipos de comprobante.',
            ],
        ];

        foreach ($limits as $limit) {
            PlanItem::create([
                'key' => $limit['key'],
                'type' => PlanItemType::LIMIT,
                'name' => $limit['name'],
                'description' => $limit['description'],
                'monthly_price' => $limit['price'],
                'meta' => ['quantity' => $limit['quantity']],
            ]);
        }
    }
}