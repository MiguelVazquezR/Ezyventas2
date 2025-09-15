import Aura from '@primeuix/themes/aura';
import { definePreset } from '@primeuix/themes';

const Ezyventas = definePreset(Aura, {
    semantic: {
        primary: {
            50: '#FEF4E7',
            100: '#FDE6C8',
            200: '#FBD3A0',
            300: '#F9B96F',
            400: '#F79F43',
            500: '#F68C0F', // Tu color primario
            600: '#E47909',
            700: '#BB610A',
            800: '#984E0F',
            900: '#7C4112',
            950: '#422007'
        },
        // Usaremos una paleta neutral para el modo claro
        colorScheme: {
            light: {
                surface: {
                    0: '#ffffff',
                    50: '{zinc.50}',
                    100: '{zinc.100}',
                    200: '{zinc.200}',
                    300: '{zinc.300}',
                    400: '{zinc.400}',
                    500: '{zinc.500}',
                    600: '{zinc.600}',
                    700: '{zinc.700}',
                    800: '{zinc.800}',
                    900: '{zinc.900}',
                    950: '{zinc.950}'
                }
            },
            // Y una paleta oscura basada en tu mainblack para el modo oscuro
            dark: {
                primary: { // Aseguramos que el primario también se defina para el modo oscuro
                    50: '#FEF4E7',
                    100: '#FDE6C8',
                    200: '#FBD3A0',
                    300: '#F9B96F',
                    400: '#F79F43',
                    500: '#F68C0F',
                    600: '#E47909',
                    700: '#BB610A',
                    800: '#984E0F',
                    900: '#7C4112',
                    950: '#422007'
                },
                surface: {
                    0: '#ffffff',
                    50: '#4a4a4a',
                    100: '#5a5a5a',
                    200: '#6b6b6b',
                    300: '#7c7c7c',
                    400: '#8d8d8d',
                    500: '#9e9e9e',
                    600: '#afafaf',
                    700: '#c0c0c0',
                    800: '#3a3a3a',
                    900: '#232323', // Tu mainblack
                    950: '#1a1a1a'
                }
            }
        }
    }
});

export default Ezyventas;