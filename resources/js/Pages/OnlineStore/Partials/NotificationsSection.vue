<script setup>
defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
});
</script>

<template>
    <div id="notifications" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Notificaciones</h2>
        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
            <div>
                <span class="text-sm font-medium dark:text-white">Notificar por correo nuevos pedidos</span>
                <p class="text-xs text-gray-400 m-0">Recibe un correo cada vez que un cliente haga un pedido en tu tienda.</p>
            </div>
            <ToggleSwitch v-model="form.notify_email_enabled" />
        </div>
        <template v-if="form.notify_email_enabled">
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Correos para notificar (máximo 3)</label>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                    <i class="pi pi-info-circle !text-xs mr-1" />
                    Los correos recibirán los detalles del pedido: productos, total, datos del cliente.
                </p>
                <div class="flex flex-col gap-3 mt-1">
                    <div v-for="(email, i) in form.notification_emails" :key="i" class="flex items-center gap-2">
                        <InputText v-model="form.notification_emails[i]" :pt="inputPt" class="flex-1" :placeholder="`Correo ${i + 1}`" type="email" />
                        <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="form.notification_emails.splice(i, 1)"
                            :pt="{ root: { class: '!text-gray-400 hover:!text-red-500' } }" />
                    </div>
                    <Button v-if="form.notification_emails.length < 3" type="button" @click="form.notification_emails.push('')"
                        icon="pi pi-plus" label="Agregar correo" severity="secondary" outlined size="small" class="!rounded-xl !text-xs self-start" />
                </div>
                <Message v-if="form.errors.notification_emails" severity="error" variant="simple" size="small">{{ form.errors['notification_emails.0'] || form.errors.notification_emails }}</Message>
            </div>
        </template>
    </div>
</template>
