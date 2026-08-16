import { ref, watch, onMounted, onUnmounted } from 'vue';

/**
 * Scroll-based scrollspy composable.
 *
 * Unlike IntersectionObserver (which creates a narrow "activation band" that short or
 * bottom-of-page sections may never reach), this approach tracks the actual scroll
 * position and determines which section the user is viewing based on a configurable
 * offset line from the top of the viewport.
 *
 * The active section is the LAST one whose top edge has passed the offset line.
 * This matches user expectation: the section heading they're reading is the one
 * whose top just scrolled past the sticky header.
 *
 * @param {string[]|Function} sectionIds - Ordered list of section element IDs
 *        (top → bottom). A getter function may be passed instead so the section
 *        list can change at runtime (e.g. dynamic form sections).
 * @param {Object} options - { offset: number } — px from viewport top where
 *        a section is considered "active" (default: 120).
 */
export function useScrollspy(sectionIds, options = {}) {
    const offset = options.offset ?? 120;

    // Support both a static array and a reactive getter (function).
    const resolveIds = () => (typeof sectionIds === 'function' ? sectionIds() : sectionIds);

    const activeSection = ref(resolveIds()[0] || null);

    let ticking = false;
    let isManualScrolling = false;
    let manualTimer = null;

    // ── Determine which section is currently in view ──
    const updateActive = () => {
        const ids = resolveIds();
        if (ids.length === 0) return;

        const scrollY = window.scrollY + offset;
        const docHeight = document.documentElement.scrollHeight;
        const viewBottom = window.scrollY + window.innerHeight;

        let current = ids[0];

        for (const id of ids) {
            const el = document.getElementById(id);
            if (!el) continue;
            // A section is "active" if its top edge is at or above the offset line
            if (el.offsetTop <= scrollY) {
                current = id;
            } else {
                break;
            }
        }

        // Edge case: scrolled to the very bottom of the page — the last section
        // may still not have its top above the offset line (if it's short).
        // If we're within 2px of the bottom, force the last section active.
        if (viewBottom >= docHeight - 2) {
            current = ids[ids.length - 1];
        }

        activeSection.value = current;
    };

    // ── Scroll handler (rAF-throttled) ──
    const onScroll = () => {
        if (isManualScrolling) return;
        if (!ticking) {
            requestAnimationFrame(() => {
                updateActive();
                ticking = false;
            });
            ticking = true;
        }
    };

    // ── Programmatic scroll to a section ──
    const scrollTo = (id) => {
        isManualScrolling = true;
        activeSection.value = id;

        const el = document.getElementById(id);
        if (el) {
            const top = el.getBoundingClientRect().top + window.scrollY - offset + 8;
            window.scrollTo({ top, behavior: 'smooth' });
        }

        clearTimeout(manualTimer);
        manualTimer = setTimeout(() => {
            isManualScrolling = false;
        }, 900);
    };

    // Re-evaluate the active section when the section list changes at runtime
    // (only needed when a reactive getter was passed instead of a static array).
    if (typeof sectionIds === 'function') {
        watch(sectionIds, () => {
            updateActive();
        }, { flush: 'post' });
    }

    onMounted(() => {
        window.addEventListener('scroll', onScroll, { passive: true });
        // Run once after DOM settles so offsetTop values are accurate
        setTimeout(updateActive, 350);
    });

    onUnmounted(() => {
        window.removeEventListener('scroll', onScroll);
        clearTimeout(manualTimer);
    });

    return { activeSection, scrollTo };
}