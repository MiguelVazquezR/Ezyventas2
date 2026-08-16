// Tesla UI — PrimeVue Pass-Through configurations shared by the invoice form sections.
//
// One canonical look for every control of the invoice form:
//   · height 42px (h-11)
//   · radius rounded-xl
//   · hairline border slate-200 / neutral-800 (light / dark)
//   · focus: primary hairline border + 1px thin ring
//   · background slate-50/50 (light) and neutral-900/50 (dark)

const controlBase =
    'h-11 w-full min-w-0 !rounded-xl !bg-slate-50/50 dark:!bg-neutral-900/50 ' +
    '!border !border-slate-200 dark:!border-neutral-800 ' +
    'focus:!border-primary-100 focus:!ring-1 focus:!ring-primary-500 ' +
    '!transition-all !duration-200 !text-sm !text-slate-900 dark:!text-white ' +
    '!shadow-none !outline-none';

export const inputPt = {
    root: { class: controlBase },
};

export const selectPt = {
    root: { class: `${controlBase} flex items-center` },
};

export const inputNumberPt = {
    root: { class: 'w-full' },
    input: { root: { class: controlBase } },
};

export const readonlyPt = {
    root: {
        class:
            'h-11 w-full min-w-0 !rounded-xl !bg-slate-100/70 dark:!bg-neutral-800/50 ' +
            '!border !border-slate-200 dark:!border-neutral-800 ' +
            '!text-slate-500 dark:!text-neutral-400 !cursor-default !text-sm ' +
            '!shadow-none !outline-none',
    },
};

export const datePickerPt = {
    root: { class: 'w-full' },
    input: { root: { class: controlBase } },
    panel: {
        class:
            'dark:!bg-[#121212] !border-slate-200 dark:!border-neutral-800 ' +
            '!rounded-2xl shadow-xl',
    },
};

export const autoCompleteInputPt = {
    root: { class: 'w-full' },
    input: { root: { class: controlBase } },
};
