import './bootstrap';
import '../css/app.css';
// import '../css/fonts.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import "primeicons/primeicons.css";

import '@/assets/styles.scss';
import Ezyventas from './presets/ezyventas';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(PrimeVue, {
                            locale: {
                                selectionMessage: '{0} elementos seleccionados',
                                choose: 'Seleccionar',
                                cancel: 'Cancelar',
                                accept: 'Si',
                                reject: 'No',
                                dateFormat: 'dd/mm/yy',
                                dayNames: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
                                dayNamesShort: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
                                dayNamesMin: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá'],
                                monthNames: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
                                monthNamesShort: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
                                today: 'Hoy',
                                weekHeader: 'Sm',
                                weak: 'Semana',
                                passwordPrompt: 'Ingrese una contraseña',
                                emptyMessage: 'No se encontraron resultados',
                                pending: 'Pendiente'
                            },
                            theme: {
                                preset: Ezyventas,
                                options: {
                                    darkModeSelector: '.app-dark'
                                }
                            }
                        })
                        .use(ToastService)
                        .use(ConfirmationService)
            .mount(el);
    },
    progress: {
        color: '#F68C0F',
    },
});
