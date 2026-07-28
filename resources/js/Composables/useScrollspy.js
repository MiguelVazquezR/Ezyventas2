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

            // Pick the intersecting section closest to the top of the viewport
            const intersecting = entries.filter(e => e.isIntersecting);
            if (intersecting.length === 0) return;

            intersecting.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            activeSection.value = intersecting[0].target.id;
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