<script setup>
import { Link } from '@inertiajs/vue3';

// Classes from the caller (e.g. "h-10 w-auto") must reach the <img> so they
// actually size the logo — otherwise the browser renders it at natural size.
defineOptions({ inheritAttrs: false });
</script>

<template>
    <Link :href="'/'">
        <figure class="mx-auto w-fit">
            <img
                v-bind="$attrs"
                class="object-contain select-none"
                src="@/../../public/images/black_logo.png"
                alt="logo"
            />
        </figure>
    </Link>
</template>
