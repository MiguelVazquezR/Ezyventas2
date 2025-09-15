import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
import PrimeUI from 'tailwindcss-primeui';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['selector', '[class*="app-dark"]'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],
    theme: {
        screens: {
            sm: '576px',
            md: '768px',
            lg: '992px',
            xl: '1200px',
            '2xl': '1920px'
        },
        extend: {
            fontFamily: {
                // sans: ['Livvic', ...defaultTheme.fontFamily.sans],
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: '#F68C0F',
                primarylight: '#FCDCB5',
                secondary: '#E9A527',
                secondarylight: '#F8E2BA',
                redDanger: '#F80505',
                grayD9: '#D9D9D9',
                gray99: '#999999',
                gray9A: '#9A9A9A',
                grayF2: '#F2F2F2',
                gray66: '#666666',
                gray77: '#777777',
                gray37: '#373737',
                black1: '#1A1A1A',
                black2: '#0D0D0D',
            },
        },
    },
    plugins: [forms, typography, PrimeUI],
};
