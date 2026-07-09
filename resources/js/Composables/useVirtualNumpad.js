import { ref, computed } from 'vue';

const STORAGE_KEY = 'pos_virtual_numpad_enabled';

// Singleton state — reactive (used in templates)
const isEnabled = ref(localStorage.getItem(STORAGE_KEY) === 'true');
const activeMode = ref('currency'); // 'currency' | 'decimal' | 'integer'
const activeLabel = ref('');
const isVisible = ref(false);
const anchorRect = ref(null); // DOMRect of the focused input element

// Plain variables — NEVER wrapped in ref(), NEVER touched by Vue's reactivity proxy.
// Storing getter/setter functions inside a Vue ref causes Proxy interference
// (MutableReactiveHandler.set -> Reflect.set -> "Cannot create property 'value' on number X").
let activeGet = null;
let activeSet = null;

export function useVirtualNumpad() {
    const toggle = () => {
        isEnabled.value = !isEnabled.value;
        localStorage.setItem(STORAGE_KEY, isEnabled.value.toString());
        if (!isEnabled.value) {
            close();
        }
    };

    const open = (options) => {
        if (!isEnabled.value) return;

        // Store getter/setter as plain functions — never inside a Vue ref.
        // We only accept { get, set } because Vue auto-unwraps refs in template
        // expressions, turning `{ inputRef: someRef }` into `{ inputRef: 42 }`.
        activeGet = options.get;
        activeSet = options.set;

        // Store the focused element's position for smart placement
        if (options.element) {
            anchorRect.value = options.element.getBoundingClientRect();
        } else if (options.rect) {
            anchorRect.value = options.rect;
        }

        activeMode.value = options.mode || 'currency';
        activeLabel.value = options.label || '';
        isVisible.value = true;
    };

    const close = () => {
        isVisible.value = false;
        activeGet = null;
        activeSet = null;
        activeLabel.value = '';
        anchorRect.value = null;
    };

    const handleKey = (key) => {
        if (!activeGet || !activeSet) return;

        const currentValue = activeGet();
        const str = currentValue != null ? String(currentValue) : '';

        switch (key) {
            case 'backspace': {
                const newVal = str.slice(0, -1);
                activeSet(newVal === '' || newVal === '-' ? null : parseFloat(newVal) || 0);
                break;
            }
            case 'clear':
                activeSet(null);
                break;
            case '.':
                if (activeMode.value === 'integer') break;
                if (!str.includes('.')) {
                    activeSet(str + '.');
                }
                break;
            case '00':
                if (str && str !== '0') {
                    activeSet(parseFloat(str + '00') || 0);
                }
                break;
            default: {
                let nextValue;
                if (str === '0' || str === '') {
                    nextValue = key;
                } else {
                    nextValue = str + key;
                }

                if (activeMode.value === 'integer') {
                    activeSet(parseInt(nextValue, 10) || 0);
                } else {
                    activeSet(parseFloat(nextValue) || 0);
                }
                break;
            }
        }
    };

    // Props to pass to numeric inputs when numpad is enabled
    const inputProps = computed(() => {
        if (!isEnabled.value) return {};
        return {
            readonly: true,
            inputmode: 'none',
        };
    });

    return {
        isEnabled,
        isVisible,
        activeLabel,
        activeMode,
        anchorRect,
        toggle,
        open,
        close,
        handleKey,
        inputProps,
    };
}
