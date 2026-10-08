<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

const props = defineProps({
    email: String,
    justSent: Boolean,
    resendIn: { type: Number, default: 0 },
});

const CODE_LENGTH = 6;

const digits = ref(Array(CODE_LENGTH).fill(''));
const cooldown = ref(0);
const hasError = ref(false);
const errorMessage = ref('');

const form = useForm({ code: '' });
const resendForm = useForm({});

// Editar el correo si se escribió mal (la cuenta aún no está verificada).
const editingEmail = ref(false);
const emailForm = useForm({ email: props.email });

const code = computed(() => digits.value.join(''));
const showSent = computed(() => props.justSent);
const canResend = computed(() => cooldown.value <= 0 && !resendForm.processing);
const formattedCooldown = computed(() => {
    const minutes = Math.floor(cooldown.value / 60);
    const seconds = cooldown.value % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

let timer = null;
let submitTimer = null;

// Server-side validation errors (e.g. "código incorrecto") come back through the
// form after an Inertia round-trip, so surface them reactively here.
watch(() => form.errors.code || resendForm.errors.code, (serverError) => {
    if (serverError) {
        hasError.value = true;
        errorMessage.value = serverError;
        clearDigits();
        focusInput(0);
    }
});

const otpInput = (index) => document.getElementById(`otp-${index}`);

function focusInput(index) {
    nextTick(() => otpInput(index)?.focus());
}

function startCountdown(seconds) {
    stopCountdown();
    cooldown.value = Math.max(0, seconds);
    timer = setInterval(() => {
        if (cooldown.value > 0) {
            cooldown.value -= 1;
        } else {
            stopCountdown();
        }
    }, 1000);
}

function stopCountdown() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function clearError() {
    hasError.value = false;
    errorMessage.value = '';
}

function onInput(event, index) {
    const clean = (event.target.value || '').replace(/\D/g, '').slice(-1);
    digits.value[index] = clean;
    clearError();

    if (clean && index < CODE_LENGTH - 1) {
        focusInput(index + 1);
    } else if (clean && index === CODE_LENGTH - 1) {
        scheduleSubmit();
    }
}

function onKeydown(event, index) {
    if (event.key === 'Backspace') {
        if (!digits.value[index] && index > 0) {
            focusInput(index - 1);
        }
        return;
    }

    const isPrintable = event.key.length === 1;
    if (isPrintable && !/\d/.test(event.key) && !event.ctrlKey && !event.metaKey) {
        event.preventDefault();
    }
}

function onPaste(event, index) {
    event.preventDefault();
    const pasted = (event.clipboardData?.getData('text') || '')
        .replace(/\D/g, '')
        .slice(0, CODE_LENGTH);

    if (!pasted) return;

    pasted.split('').forEach((char, i) => {
        digits.value[i] = char;
    });
    clearError();

    focusInput(Math.min(index + pasted.length, CODE_LENGTH - 1));

    if (pasted.length === CODE_LENGTH) {
        scheduleSubmit();
    }
}

function clearDigits() {
    digits.value = Array(CODE_LENGTH).fill('');
}

function scheduleSubmit() {
    clearTimeout(submitTimer);
    submitTimer = setTimeout(() => {
        if (code.value.length === CODE_LENGTH && !form.processing) {
            submit();
        }
    }, 200);
}

function submit() {
    if (form.processing) return;

    if (code.value.length !== CODE_LENGTH) {
        hasError.value = true;
        errorMessage.value = 'Ingresa los 6 dígitos del código.';
        return;
    }

    clearError();
    form.code = code.value;

    form.post(route('verification.code'), {
        onError: (errors) => {
            hasError.value = true;
            errorMessage.value = errors.code || 'No se pudo verificar el código. Inténtalo de nuevo.';
            clearDigits();
            focusInput(0);
        },
    });
}

function resend() {
    if (!canResend.value || resendForm.processing) return;

    clearError();
    resendForm.post(route('verification.send'), {
        onSuccess: () => {
            clearDigits();
            focusInput(0);
        },
        onError: (errors) => {
            hasError.value = true;
            errorMessage.value = errors.code || 'No se pudo reenviar el código. Inténtalo de nuevo.';
        },
    });
}

function toggleEditEmail() {
    editingEmail.value = !editingEmail.value;
    emailForm.clearErrors();
    emailForm.email = props.email;
}

function saveEmail() {
    if (emailForm.processing) return;

    const value = (emailForm.email || '').trim().toLowerCase();

    // Mismo correo: solo cerramos el editor sin reenviar nada.
    if (value === props.email) {
        editingEmail.value = false;
        return;
    }

    emailForm.post(route('verification.change-email'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            editingEmail.value = false;
            startCountdown(60);
            clearDigits();
            clearError();
            focusInput(0);
        },
        onError: () => {
            // Mantenemos el panel abierto para que corrija el correo.
        },
    });
}

onMounted(() => {
    startCountdown(props.resendIn);
    focusInput(0);
});

onBeforeUnmount(() => {
    stopCountdown();
    clearTimeout(submitTimer);
});
</script>

<style>
/* Inyectamos tus colores primarios en las variables CSS de PrimeVue. */
:root {
    --p-primary-color: #f68c0f;
    --p-primary-color-text: #1A1A1A;
    --p-primary-500: #f68c0f;
    --p-primary-600: #e07e0e;
    --p-primary-700: #c9700d;
}

/* Animación sutil al ingresar el código incorrecto */
@keyframes otp-shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(4px); }
}

.otp-shake {
    animation: otp-shake 0.4s ease-in-out;
}
</style>

<template>
    <Head title="Verifica tu correo" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-950 p-4">
        <div class="w-full max-w-md">
            <!-- Tarjeta principal -->
            <div class="bg-white dark:bg-surface-900 shadow-2xl rounded-2xl overflow-hidden p-8 md:p-10">
                <div class="flex flex-col items-center space-y-6">
                    <AuthenticationCardLogo class="h-10 w-auto" />

                    <div class="text-center space-y-2">
                        <h2 class="text-2xl font-bold tracking-tight text-surface-900 dark:text-surface-0 m-0">
                            Verifica tu correo
                        </h2>
                        <p class="text-sm text-surface-600 dark:text-surface-400 leading-relaxed">
                            Enviamos un código de 6 dígitos a
                            <span class="font-semibold text-surface-800 dark:text-surface-200">{{ email }}</span>.
                            <br />
                            Ingrésalo para activar tu cuenta y entrar.
                        </p>
                    </div>

                    <!-- ¿Correo incorrecto? Cambiarlo antes de verificar -->
                    <div v-if="!editingEmail" class="w-full flex items-center justify-center gap-1 text-sm">
                        <span class="text-surface-500 dark:text-surface-400">¿Pusiste mal tu correo?</span>
                        <button type="button"
                            class="font-semibold text-primary-600 hover:text-primary-500 m-0"
                            @click="toggleEditEmail">
                            Cámbialo aquí
                        </button>
                    </div>

                    <!-- Editor del correo -->
                    <div v-else class="w-full space-y-3 p-4 rounded-2xl bg-surface-50 dark:bg-surface-800/40">
                        <div class="flex flex-col gap-1.5">
                            <label for="edit-email"
                                class="text-xs font-medium text-surface-700 dark:text-surface-300 m-0">
                                Correo electrónico correcto
                            </label>
                            <InputText id="edit-email" v-model="emailForm.email" type="email" fluid
                                :invalid="!!emailForm.errors.email" autofocus @keyup.enter="saveEmail" />
                            <Message v-if="emailForm.errors.email" severity="error" variant="simple" size="small"
                                :closable="false">
                                {{ emailForm.errors.email }}
                            </Message>
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <Button type="button" label="Cancelar" severity="secondary" outlined size="small"
                                :disabled="emailForm.processing" @click="toggleEditEmail" />
                            <Button type="button" label="Guardar y reenviar código" size="small"
                                :loading="emailForm.processing" :disabled="emailForm.processing" @click="saveEmail" />
                        </div>
                    </div>

                    <!-- Mensaje de éxito (código enviado / reenviado) -->
                    <Message v-if="showSent" severity="success" :closable="false" class="w-full" :pt="{ root: { class: '!text-sm' } }">
                        <template #messageicon>
                            <i class="pi pi-envelope !text-sm" />
                        </template>
                        Hemos enviado un código a tu correo. Revisa también tu carpeta de spam.
                    </Message>

                    <!-- Mensaje de error -->
                    <Message v-if="hasError" severity="error" variant="simple" :closable="false" class="w-full">
                        {{ errorMessage }}
                    </Message>

                    <!-- Casillas del código -->
                    <div class="flex items-center justify-center gap-2.5 w-full" :class="{ 'otp-shake': hasError }">
                        <InputText
                            v-for="(digit, index) in digits"
                            :id="`otp-${index}`"
                            :key="index"
                            v-model="digits[index]"
                            :maxlength="1"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            aria-label="Dígito del código"
                            class="w-12 h-14 text-center text-xl font-bold !rounded-2xl !px-0"
                            :class="hasError
                                ? '!border-red-400 focus:!border-red-500'
                                : ''"
                            @input="onInput($event, index)"
                            @keydown="onKeydown($event, index)"
                            @paste="onPaste($event, index)"
                        />
                    </div>

                    <div class="w-full">
                        <Button
                            type="button"
                            label="Verificar código"
                            class="w-full font-bold !rounded-full"
                            :loading="form.processing"
                            :disabled="form.processing"
                            @click="submit"
                        />
                    </div>

                    <!-- Reenviar código -->
                    <div class="flex items-center justify-center gap-1 text-sm w-full">
                        <span class="text-surface-500 dark:text-surface-400">¿No recibiste el código?</span>
                        <button
                            type="button"
                            class="font-semibold text-primary-600 hover:text-primary-500 disabled:opacity-50 disabled:cursor-not-allowed m-0"
                            :disabled="!canResend"
                            @click="resend"
                        >
                            {{ canResend ? 'Reenviar código' : `Reenviar en ${formattedCooldown}` }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Enlace para cerrar sesión -->
            <div class="mt-6 text-center">
                <Link :href="route('logout')" method="post" as="button"
                    class="text-sm font-medium text-surface-500 hover:text-surface-700 dark:text-surface-400 dark:hover:text-surface-200">
                    Cerrar sesión
                </Link>
            </div>
        </div>
    </div>
</template>
