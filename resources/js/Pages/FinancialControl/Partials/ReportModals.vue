<script setup>
import { format } from 'date-fns';

const props = defineProps({
    detailedTransactions: Array,
    detailedPayments: Array,
    detailedExpenses: Array,
});

const isSalesVisible = defineModel('isSalesVisible', { type: Boolean });
const isPaymentsVisible = defineModel('isPaymentsVisible', { type: Boolean });
const isExpensesVisible = defineModel('isExpensesVisible', { type: Boolean });
const isHelpVisible = defineModel('isHelpVisible', { type: Boolean });

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => format(new Date(dateString), 'dd/MM/yyyy');
const formatDateTime = (dateString) => format(new Date(dateString), 'dd/MM/yyyy HH:mm');

const getPaymentMethodDetails = (method) => {
    const details = {
        efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'bg-[#37672B]', textColor: 'text-green-600' },
        tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'bg-[#063C53]', textColor: 'text-blue-600' },
        transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'bg-[#D2D880]', textColor: 'text-orange-500' },
        saldo: { name: 'Saldo a favor', icon: 'pi pi-wallet', color: 'bg-purple-500', textColor: 'text-purple-500' },
        default: { name: method || 'Otro', icon: 'pi pi-question-circle', color: 'bg-gray-500', textColor: 'text-gray-500' }
    };
    return details[method] || details.default;
};

const getChannelDetails = (channel) => {
    const details = {
        punto_de_venta: { name: 'Punto de Venta', icon: 'pi pi-shopping-cart', verb: 'Ventas realizadas' },
        tienda_en_linea: { name: 'Tienda en Línea', icon: 'pi pi-mobile', verb: 'Ventas realizadas' },
        orden_de_servicio: { name: 'Orden de Servicio', icon: 'pi pi-wrench', verb: 'Órdenes completadas' },
        cotizacion: { name: 'Cotización', icon: 'pi pi-file', verb: 'Cotizaciones aceptadas' },
        manual: { name: 'Manual', icon: 'pi pi-pencil', verb: 'Ventas registradas' },
        abono_a_saldo: { name: 'Abono a Saldo', icon: 'pi pi-wallet', verb: 'Abonos recibidos' }
    };
    return details[channel] || { name: channel || 'Desconocido', icon: 'pi pi-question-circle', verb: 'Transacciones' };
};

const getTransactionStatusTagSeverity = (status) => {
    switch (status) {
        case 'completada': return 'success';
        case 'pendiente': return 'warning';
        case 'cancelada': return 'danger';
        case 'reembolsada': return 'info';
        default: return 'secondary';
    }
};
</script>

<template>
    <!-- MODAL: Detalle de Ventas -->
    <Dialog v-model:visible="isSalesVisible" header="Detalle de ventas del periodo" modal class="w-full max-w-5xl mx-4">
        <DataTable :value="detailedTransactions" paginator :rows="15" class="p-datatable-sm" sortMode="multiple"
            :multiSortMeta="[{ field: 'created_at', order: -1 }]"
            emptyMessage="No hay ventas registradas en este periodo." responsiveLayout="scroll">
            <Column field="folio" header="Folio" sortable></Column>
            <Column field="created_at" header="Fecha" sortable> <template #body="{ data }"> {{ formatDateTime(data.created_at) }} </template> </Column>
            <Column field="customer.name" header="Cliente" sortable> <template #body="{ data }"> {{ data.customer?.name || 'Público General' }} </template> </Column>
            <Column field="channel" header="Canal" sortable> <template #body="{ data }"> {{ getChannelDetails(data.channel).name }} </template> </Column>
            <Column field="total" header="Total" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.total) }}</span> </template> </Column>
            <Column field="status" header="Estado" sortable> <template #body="{ data }"> <Tag :value="data.status" :severity="getTransactionStatusTagSeverity(data.status)" /> </template> </Column>
            <template #empty>
                <div class="p-4 text-center text-gray-500"> No hay ventas registradas en este periodo. </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL: Detalle de Pagos -->
    <Dialog v-model:visible="isPaymentsVisible" header="Detalle de pagos recibidos" modal class="w-full max-w-5xl mx-4">
        <DataTable :value="detailedPayments" paginator :rows="15" class="p-datatable-sm" sortMode="multiple"
            :multiSortMeta="[{ field: 'payment_date', order: -1 }]"
            emptyMessage="No hay pagos (excepto saldo) registrados en este periodo." responsiveLayout="scroll">
            <Column field="payment_date" header="Fecha" sortable> <template #body="{ data }"> {{ formatDateTime(data.payment_date) }} </template> </Column>
            <Column field="transaction.folio" header="Venta folio" sortable></Column>
            <Column field="transaction.customer.name" header="Cliente" sortable> <template #body="{ data }"> {{ data.transaction?.customer?.name || 'Público General' }} </template> </Column>
            <Column field="payment_method" header="Método" sortable>
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2"> 
                            <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i>
                            <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> 
                        </div>
                        <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                            class="text-xs text-gray-500 dark:text-gray-400 pl-6" v-tooltip.bottom="`${data.bank_account.bank_name}`"> 
                            ↳ {{ data.bank_account.account_name }} 
                        </div>
                    </div>
                </template>
            </Column>
            <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template> </Column>
            <template #empty>
                <div class="p-4 text-center text-gray-500"> No hay pagos registrados en este periodo. </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL: Detalle de Gastos Totales -->
    <Dialog v-model:visible="isExpensesVisible" header="Detalle de gastos totales del periodo" modal class="w-full max-w-5xl mx-4">
        <DataTable :value="detailedExpenses" paginator :rows="10" class="p-datatable-sm" sortMode="multiple"
            :multiSortMeta="[{ field: 'expense_date', order: -1 }]"
            emptyMessage="No hay gastos registrados en este periodo." responsiveLayout="scroll">
            <Column field="folio" header="Folio" sortable></Column>
            <Column field="expense_date" header="Fecha" sortable> <template #body="{ data }"> {{ formatDate(data.expense_date) }} </template> </Column>
            <Column field="category.name" header="Categoría" sortable></Column>
            <Column field="description" header="Descripción"></Column>
            <Column field="payment_method" header="Método de Pago" sortable>
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2"> 
                            <i :class="`${getPaymentMethodDetails(data.payment_method).icon} ${getPaymentMethodDetails(data.payment_method).textColor}`"></i>
                            <span>{{ getPaymentMethodDetails(data.payment_method).name }}</span> 
                        </div>
                        <div v-if="(data.payment_method === 'tarjeta' || data.payment_method === 'transferencia') && data.bank_account"
                            class="text-xs text-gray-500 dark:text-gray-400 pl-6" v-tooltip.bottom="`${data.bank_account.bank_name}`"> 
                            ↳ {{ data.bank_account.account_name }} 
                        </div>
                    </div>
                </template>
            </Column>
            <Column field="amount" header="Monto" sortable> <template #body="{ data }"> <span class="font-mono font-semibold">{{ formatCurrency(data.amount) }}</span> </template> </Column>
            <template #empty>
                <div class="p-4 text-center text-gray-500"> No hay gastos registrados en este periodo. </div>
            </template>
        </DataTable>
    </Dialog>

    <!-- MODAL DE AYUDA -->
    <Dialog v-model:visible="isHelpVisible" header="Glosario de métricas financieras" modal class="w-full max-w-3xl mx-4">
        <Accordion value="0">
            <AccordionPanel value="0">
                <AccordionHeader>Ganancia neta</AccordionHeader>
                <AccordionContent>
                    <div class="p-4 space-y-3">
                        <p class="text-lg m-0"> Mide la <strong>rentabilidad</strong> de tu negocio después de restar todos los gastos de tus ventas totales. </p>
                        <div class="text-center"> <Tag severity="warn" class="!text-lg !bg-teal-100 !text-teal-600 font-mono"> (Ventas Totales) - (Total de Gastos) </Tag> </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                            <p> Responde a la pregunta: <strong>"¿Mi negocio es rentable?"</strong>. </p>
                            <ul class="list-disc pl-5 mt-2 space-y-1">
                                <li> Te dice si tus precios de venta son suficientes para cubrir tus costos operativos y aún dejar un margen de ganancia. </li>
                                <li> <strong>Importante:</strong> Se basa en las <Tag class="!bg-purple-100 !text-purple-600">Ventas</Tag>, no en los pagos. Una venta a crédito cuenta aquí, aunque no hayas recibido el dinero. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
            
            <AccordionPanel value="3">
                <AccordionHeader>% Margen de utilidad</AccordionHeader>
                <AccordionContent>
                    <div class="p-4 space-y-3">
                        <p class="text-lg m-0"> Indica qué porcentaje de tus ventas se convierte en ganancia real. </p>
                        <div class="text-center"> <Tag severity="warn" class="!text-lg !bg-orange-100 !text-orange-600 font-mono"> (Ganancia Neta / Ventas Totales) * 100 </Tag> </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Ejemplo:</p>
                            <p> Si vendes $1,000 y gastas $800, tu ganancia es $200. Tu margen es del <strong>20%</strong>. Significa que de cada $1 peso que vendes, te quedas con 20 centavos de ganancia. </p>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>

            <AccordionPanel value="1">
                <AccordionHeader>Flujo de dinero neto</AccordionHeader>
                <AccordionContent>
                    <div class="p-4 space-y-3">
                        <p class="text-lg m-0"> Mide la <strong>liquidez</strong> real de tu negocio. Es la cantidad de dinero que entró y salió. </p>
                        <div class="text-center"> <Tag severity="success" class="!text-lg font-mono"> (Total de Pagos Recibidos) - (Total de Gastos Pagados) </Tag> </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                            <p> Responde a la pregunta: <strong>"¿Tengo dinero para operar y pagar mis cuentas?"</strong>. </p>
                            <ul class="list-disc pl-5 mt-2 space-y-1">
                                <li> Un negocio puede ser "rentable" (Ganancia Neta positiva) pero quebrar por falta de liquidez (Flujo de Dinero negativo) si los clientes no pagan a tiempo. </li>
                                <li> Este indicador es vital para la operación diaria. Te aseguras de tener efectivo en tus cuentas bancarias. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>

            <AccordionPanel value="2">
                <AccordionHeader>Monto promedio por venta (Ticket promedio)</AccordionHeader>
                <AccordionContent>
                    <div class="p-4 space-y-3">
                        <p class="text-lg m-0"> Mide cuánto gasta un cliente en promedio en cada transacción que realiza. </p>
                        <div class="text-center"> <Tag severity="info" class="!text-lg font-mono"> (Ventas Totales) / (Número Total de Ventas) </Tag> </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 m-0">Utilidad para el negocio:</p>
                            <p> Responde a la pregunta: <strong>"¿Cuánto gastan mis clientes en promedio por compra?"</strong>. </p>
                            <ul class="list-disc pl-5 mt-2 space-y-1">
                                <li> Es un indicador clave para el crecimiento. Aumentar el ticket promedio (con estrategias de *upselling* o paquetes) puede ser más fácil que conseguir nuevos clientes. </li>
                                <li> Te ayuda a entender el poder adquisitivo de tus clientes y a probar el impacto de nuevas estrategias de precios o promociones. </li>
                            </ul>
                        </div>
                    </div>
                </AccordionContent>
            </AccordionPanel>
        </Accordion>
    </Dialog>
</template>