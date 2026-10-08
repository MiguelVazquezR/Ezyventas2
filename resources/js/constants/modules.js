// Shared plan module keys and copy used across the onboarding wizard.
// FREE_MODULE_KEYS: modules included in the essential plan (always active).
// AI_MODULE_KEY: AI agent module — always active while its plan item stays free.
export const FREE_MODULE_KEYS = ['module_pos', 'module_transactions', 'module_products', 'module_expenses', 'module_cash_registers', 'module_settings'];
export const AI_MODULE_KEY = 'module_ai_agent';

// Short, friendly descriptions for the module cards. Falls back to the plan
// item description when a module has no entry here.
export const MODULE_SHORT_DESCRIPTIONS = {
    module_pos: 'Cobra en efectivo, tarjeta o a crédito',
    module_transactions: 'Historial de todas tus ventas, siempre a mano',
    module_products: 'Catálogo con precios, códigos de barras e inventario',
    module_expenses: 'Registra y clasifica los gastos del negocio',
    module_customers: 'Clientes con historial de compras, saldo y datos fiscales',
    module_services: 'Órdenes de servicio y reparaciones con seguimiento',
    module_quotes: 'Cotizaciones que se convierten en ventas con un clic',
    module_billing: 'Facturas electrónicas CFDI 4.0 ante el SAT',
    module_financial_reports: 'Reportes y gráficas de ingresos, gastos y utilidades',
    module_cash_registers: 'Abre, cierra y concilia tus cajas registradoras',
    module_settings: 'Configuración general de tu cuenta y sucursales',
    module_online_store: 'Vende en línea con tu catálogo sincronizado',
    module_ai_agent: 'Te ayuda a crear, analizar y automatizar tareas con IA',
};
