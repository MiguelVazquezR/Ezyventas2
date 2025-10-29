import { ref, onMounted, onUnmounted } from 'vue';

export function useDeviceDetection() {
    const isMobileOrTablet = ref(false);

    const checkDevice = () => {
        // Expresión regular robusta para detectar móviles y tablets
        const userAgent = navigator.userAgent || navigator.vendor || window.opera;
        isMobileOrTablet.value = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i.test(userAgent.toLowerCase());
    };

    onMounted(() => {
        checkDevice();
        // Opcional: Podrías re-chequear en resize si fuera necesario, pero usualmente no cambia.
        // window.addEventListener('resize', checkDevice);
    });

    // onUnmounted(() => {
    //     window.removeEventListener('resize', checkDevice);
    // });

    return { isMobileOrTablet };
}
