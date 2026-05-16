import { ref, onMounted, onUnmounted } from 'vue';

export function useScrollspy(sectionIds, options = { rootMargin: '-20% 0px -50% 0px', threshold: 0 }) {
    const activeSection = ref(sectionIds[0]);
    let observer = null;
    let isManualScrolling = false;

    const scrollTo = (id) => {
        isManualScrolling = true;
        activeSection.value = id;
        const element = document.getElementById(id);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth' });
            // Pausamos el observer brevemente para evitar parpadeos
            setTimeout(() => { isManualScrolling = false; }, 800);
        }
    };

    onMounted(() => {
        observer = new IntersectionObserver((entries) => {
            if (isManualScrolling) return; 
            
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    activeSection.value = entry.target.id;
                }
            });
        }, options);

        setTimeout(() => {
            sectionIds.forEach(id => {
                const el = document.getElementById(id);
                if (el) observer.observe(el);
            });
        }, 300);
    });

    onUnmounted(() => {
        if (observer) observer.disconnect();
    });

    return {
        activeSection,
        scrollTo
    };
}