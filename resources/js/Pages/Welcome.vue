<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount } from 'vue';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import AOS from 'aos';
import confetti from "canvas-confetti";
import 'aos/dist/aos.css';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
    laravelVersion: String,
    phpVersion: String,
});

const quantity1 = ref(1);
const quantity2 = ref(1);
const showScrollButton = ref(false);

// Definir la URL de WhatsApp como una propiedad computada
const scrollToTop = () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
};

const launchConfetti = () => {
    confetti({
        particleCount: 100,
        spread: 70,
        origin: { y: 0.6 },
    });
};

const scrollToElement = (id) => {
    const element = document.getElementById(id);
    element.scrollIntoView({
        behavior: 'smooth'
    });
};

const handleScroll = () => {
    showScrollButton.value = window.scrollY > 1000;
};

const openWhatsapp = () => {
    const url = 'https://api.whatsapp.com/send?phone=523321705650&text=¡Hola!%20vi%20tu%20página%20y%20me%20interesa%20el%20punto%20de%20venta'
    window.open(url, '_blank');
};

onMounted(() => {
    AOS.init();
    window.addEventListener('scroll', handleScroll);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', handleScroll);
});


</script>

<style scoped>
/* uiverse estilos */
/* From Uiverse.io by CritCoder */
.buttonupgrade {
    width: fit-content;
    display: flex;
    padding: 5px 22px;
    cursor: pointer;
    border-radius: 30px;
    text-shadow: 2px 2px 3px rgba(221, 255, 0, 0.3);
    background: linear-gradient(15deg,
            #F68C0F,
            #e6810e,
            #bb6707,
            #905209,
            #F68C0F,
            #e6810e,
            #bb6707,
            #905209) no-repeat;
    background-size: 300%;
    color: white;
    border: none;
    background-position: left center;
    box-shadow: 0 20px 10px -14px rgba(246, 140, 15, 0.5);
    transition:
        background 0.3s ease,
        color 0.3s ease;
}

.buttonupgrade:hover {
    background-size: 320%;
    background-position: right center;
    color: white;
}


/* From Uiverse.io by rahulgarg99 */
.button1 {
    line-height: 1;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    background-color: #fff;
    color: #F68C0F;
    border-radius: 10rem;
    padding: 0.75rem 1.5rem;
    padding-left: 20px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: background-color 0.3s;
}

.button1__icon-wrapper {
    flex-shrink: 0;
    width: 25px;
    height: 25px;
    position: relative;
    color: #fff;
    background-color: #F68C0F;
    border-radius: 50%;
    display: grid;
    place-items: center;
    overflow: hidden;
}

.button1:hover {
    background-color: #F68C0F;
    color: white;
}

.button1:hover .button1__icon-wrapper {
    color: #F68C0F;
    background-color: #fff;
}

.button1__icon-svg--copy {
    position: absolute;
    transform: translate(-150%, 150%);
}

.button1:hover .button1__icon-svg:first-child {
    transition: transform 0.3s ease-in-out;
    transform: translate(150%, -150%);
}

.button1:hover .button1__icon-svg--copy {
    transition: transform 0.3s ease-in-out 0.1s;
    transform: translate(0);
}

/* From Uiverse.io by ayman-ashine */
.card {
    --dark: #212121;
    --darker: #111111;
    --semidark: #2c2c2c;
    --lightgray: #e8e8e8;
    --unit: 10px;

    background-color: var(--darker);
    box-shadow: 0 0 var(--unit) var(--darker);
    border: calc(var(--unit) / 2) solid var(--darker);
    border-radius: var(--unit);
    position: relative;
    padding: var(--unit);
    overflow: hidden;
}

.card::before {
    content: "";
    position: absolute;
    width: 120%;
    height: 20%;
    top: 40%;
    left: -10%;
    background: linear-gradient(144deg, #af40ff, #5b42f3 50%, #00ddeb);
    animation: keyframes-floating-light 2.5s infinite ease-in-out;
    filter: blur(20px);
}

@keyframes keyframes-floating-light {
    0% {
        transform: rotate(-5deg) translateY(-5%);
        opacity: 0.5;
    }

    50% {
        transform: rotate(5deg) translateY(5%);
        opacity: 1;
    }

    100% {
        transform: rotate(-5deg) translateY(-5%);
        opacity: 0.5;
    }
}

.card::after {
    content: "";
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0%;
    left: 0%;
    background: linear-gradient(144deg, #af40ff, #5b42f3 50%, #00ddeb);
    filter: blur(20px);
    pointer-events: none;
    animation: keyframes-intro 1s ease-in forwards;
}

@keyframes keyframes-intro {
    100% {
        transform: translate(-100%);
        opacity: 0;
    }
}

.card .image {
    width: 600px;
    animation: keyframes-floating-img 10s ease-in-out infinite;
}

@keyframes keyframes-floating-img {
    0% {
        transform: translate(-2%, 2%) scaleY(0.95) rotate(-5deg);
    }

    50% {
        transform: translate(2%, -2%) scaleY(1) rotate(5deg);
    }

    100% {
        transform: translate(-2%, 2%) scaleY(0.95) rotate(-5deg);
    }
}

.card .heading {
    font-weight: 400;
    font-size: 17px;
    text-align: center;
    margin-top: 8px;
    margin-bottom: 8px;
    padding-block: var(--unit);
    color: var(--lightgray);
    animation: keyframes-flash-text 0.5s infinite;
}

@keyframes keyframes-flash-text {
    50% {
        opacity: 0.5;
    }
}

.card .icons {
    display: flex;
    gap: var(--unit);
}

.card .icons a {
    display: flex;
    flex-grow: 1;
    align-items: center;
    justify-content: center;
    background-color: var(--dark);
    color: var(--lightgray);
    padding: calc(var(--unit) / 2);
    border-radius: calc(var(--unit) / 2);
}

.card .icons a:hover {
    transition: 0.2s;
    background-color: var(--semidark);
}
</style>

<template>

    <Head title="Ezy Ventas" />
    <div class="bg-black2 relative overflow-x-hidden">
        <img class="object-contain select-none absolute right-0 top-0" :draggable="false"
            src="@/../../public/imagesLanding/bg-01.webp" alt="Destello neón de adorno en el fondo">
        <nav class="bg-transparent" data-aos="zoom-in" data-aos-duration="1200">
            <div class="max-w-8xl mx-auto px-4 md:px-7 py-3">
                <div class="flex justify-between items-center h-14 bg-[#404040]/30 rounded-full pl-6 pr-2">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="shrink-0 flex items-center">
                            <ApplicationMark class="block h-10 md:h-12 w-auto" />
                        </div>
                    </div>
                    <div class="flex sm:items-center space-x-12 sm:ms-6">
                        <button @click="scrollToElement('features')" class="text-white focus:border-none focus:ring-0"
                            type="button">Funcionalidades</button>
                        <!-- <button @click="scrollToElement('prices')" class="text-white focus:border-none focus:ring-0"
                            type="button">Precios</button>
                        <button @click="$inertia.get(route('landing.create-partner'))"
                            class="text-white focus:border-none focus:ring-0" type="button">Recomienda y gana</button>
                        <a href="https://ezyventas.com/mx/ezyventas" target="_blank" as="button"
                            class="text-white focus:border-none focus:ring-0" type="button">Productos</a> -->
                        <Link :href="$page.props.auth?.user ? route('dashboard') : route('login')">
                        <button class="buttonupgrade">
                            Iniciar sesión
                        </button>
                        </Link>
                    </div>
                </div>
            </div>
        </nav>
        <main class="bg-transparent selection:bg-primary selection:text-white pb-24 relative">
            <!-- hero -->
            <section class="flex relative">
                <figure class="w-[24%] mx-12 mt-10" data-aos="fade-right" data-aos-duration="1200" data-aos-delay="200">
                    <img class="object-contain select-none" :draggable="false"
                        src="@/../../public/imagesLanding/landing-01.webp"
                        alt="Letrero en neón que dice: Punto de venta. 30 dias gratis">
                    <img class="object-contain select-none mt-4 ml-auto" :draggable="false"
                        src="@/../../public/imagesLanding/price.webp" alt="Letrero en neón que dice: desde $199 al mes">
                </figure>
                <figure class="w-[27%] ml-20 mt-20 z-10" data-aos="fade-down" data-aos-duration="1200"
                    data-aos-delay="1000">
                    <img class="object-contain select-none" :draggable="false"
                        src="@/../../public/imagesLanding/landing-02.webp" alt="Tablet que muestra un carrito de compras">
                </figure>
                <img class="object-contain select-none absolute -right-[5%] top-28" :draggable="false"
                    src="@/../../public/imagesLanding/landing-03.webp"
                    alt="cuerdas de neón de colores con iconos dentro de un extremo" data-aos="fade-left"
                    data-aos-duration="1200" data-aos-delay="600">
                <div class="absolute bottom-4 right-[22%]" data-aos="fade-up" data-aos-duration="1200"
                    data-aos-delay="1500" data-aos-offset="500">
                    <button @click="$inertia.visit('register')" class="button1" style="--clr: #7808d0">
                        <span class="button1__icon-wrapper">
                            <svg viewBox="0 0 14 15" fill="none" xmlns="http://www.w3.org/2000/svg"
                                class="button1__icon-svg" width="10">
                                <path
                                    d="M13.376 11.552l-.264-10.44-10.44-.24.024 2.28 6.96-.048L.2 12.56l1.488 1.488 9.432-9.432-.048 6.912 2.304.024z"
                                    fill="currentColor"></path>
                            </svg>
                            <svg viewBox="0 0 14 15" fill="none" width="10" xmlns="http://www.w3.org/2000/svg"
                                class="button1__icon-svg button1__icon-svg--copy">
                                <path
                                    d="M13.376 11.552l-.264-10.44-10.44-.24.024 2.28 6.96-.048L.2 12.56l1.488 1.488 9.432-9.432-.048 6.912 2.304.024z"
                                    fill="currentColor"></path>
                            </svg>
                        </span>
                        Probar ahora
                    </button>
                </div>
            </section>
            <!-- funcionalidades -->
            <section class="mt-56 relative">
                <img class="object-contain select-none absolute left-0 -top-72" :draggable="false"
                    src="@/../../public/imagesLanding/bg-02.webp" alt="Destello neón de adorno en el fondo"
                    data-aos="fade-right" data-aos-duration="1200" data-aos-delay="800">
                <p id="features" class="text-5xl text-center font-extrabold text-white" data-aos="flip-left"
                    data-aos-duration="700">FUNCIONALIDAD DE LOS MÓDULOS</p>
                <!-- Punto de venta -->
                <article class="mx-40 flex space-x-32 relative">
                    <figure class="w-[40%]">
                        <img class="object-contain select-none" :draggable="false"
                            src="@/../../public/imagesLanding/features-01.webp"
                            alt="Carrito de compras con aparatos electronicos entrando y brillando" data-aos="fade-left"
                            data-aos-duration="1400" data-aos-delay="200">
                    </figure>
                    <div class="w-1/2 text-white mt-52" data-aos="fade-right" data-aos-duration="1200"
                        data-aos-delay="800">
                        <h2 class="font-bold text-4xl text-white">Punto de venta</h2>
                        <p class="text-justify text-3xl mt-8">
                            Integración con básculas, impresoras de tickets, lectores de códigos de barra y más.
                        </p>
                        <div class="flex justify-between mt-16">
                            <figure class="w-[30%] flex flex-col" data-aos="fade-up" data-aos-duration="500"
                                data-aos-delay="1500">
                                <img class="w-[36%] mx-auto object-contain select-none" :draggable="false"
                                    src="@/../../public/imagesLanding/wifi.webp"
                                    alt="Simbolo de wifi con diagonal que indica sin conexion a internet">
                                <p class="text-center text-sm mt-4">No pierdas ventas: opera sin conexión y sincroniza
                                    después.</p>
                            </figure>
                            <img class="w-[24%] object-contain select-none mt-1" :draggable="false" data-aos="fade-up"
                                data-aos-duration="300" data-aos-delay="1900" src="@/../../public/imagesLanding/printer.webp"
                                alt="Impresora de tikets pequeña con un tiket surgiendo de ella">
                        </div>
                        <p class="text-center text-3xl mt-12" style="font-family: 'Licorice';" data-aos="fade-up"
                            data-aos-duration="240" data-aos-delay="1950">
                            ¡Simplifica tus ventas, todo en uno!
                        </p>
                    </div>
                </article>
                <!-- Registro de venta -->
                <article class="mt-20 relative">
                    <img class="object-contain select-none absolute right-0 -top-40" :draggable="false"
                        src="@/../../public/imagesLanding/bg-03.webp" alt="Destello neón de adorno en el fondo"
                        data-aos="fade-left" data-aos-duration="1200" data-aos-delay="800">
                    <div class="mx-40 flex space-x-32">
                        <div class="w-1/2 text-white" data-aos="fade-right" data-aos-duration="800">
                            <h2 class="font-bold text-4xl text-white">Registro de ventas</h2>
                            <p class="text-justify text-3xl mt-8">Accede al historial completo de transacciones en
                                segundos.
                            </p>
                            <ul class="text-3xl mt-8 *:mt-3 *:flex *:items-center *:space-x-2">
                                <li>
                                    <svg width="30" height="30" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_44)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#410B69" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#7113B5" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_44" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_44" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Edita ventas</span>
                                </li>
                                <li>
                                    <svg width="30" height="30" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_44)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#410B69" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#7113B5" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_44" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_44" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Haz reembolsos</span>
                                </li>
                                <li>
                                    <svg width="30" height="30" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_44)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#410B69" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#7113B5" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_44" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_44" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Imprime tickets</span>
                                </li>
                            </ul>
                        </div>
                        <figure class="w-[38%] z-10" data-aos="fade-left" data-aos-duration="800" data-aos-delay="400">
                            <img class="object-contain select-none" :draggable="false"
                                src="@/../../public/imagesLanding/features-02.webp"
                                alt="Carrito de compras con aparatos electronicos entrando y brillando">
                        </figure>
                    </div>
                </article>
                <!-- Clientes -->
                <article class="mt-32 relative">
                    <img class="object-contain select-none absolute left-[5%] -top-40" :draggable="false"
                        src="@/../../public/imagesLanding/bg-04.webp" alt="Destello neón de adorno en el fondo"
                        data-aos="fade-right" data-aos-duration="1200" data-aos-delay="800">
                    <div class="mx-40 flex space-x-32 text-end">
                        <figure class="w-[65%] z-10" data-aos="fade-right" data-aos-duration="800" data-aos-delay="300">
                            <img class="object-contain select-none" :draggable="false"
                                src="@/../../public/imagesLanding/features-03.webp"
                                alt="Carrito de compras con aparatos electronicos entrando y brillando">
                        </figure>
                        <div class="w-[36%] text-white mt-36" data-aos="fade-left" data-aos-duration="800">
                            <h2 class="font-bold text-4xl text-white">Clientes</h2>
                            <p class="text-justify text-3xl mt-8">
                                Gestiona toda la información de tus clientes en un solo lugar.
                            </p>
                            <ul class="text-2xl mt-8 *:mt-3">
                                <li class="flex items-center justify-end space-x-2">
                                    <svg width="28" height="28" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_47)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#0B6965" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#13B5AD" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_47" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_47" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Historial de compras</span>
                                </li>
                                <li class="flex items-center justify-end space-x-2">
                                    <svg width="28" height="28" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_47)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#0B6965" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#13B5AD" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_47" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_47" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Ventas a crédito y al contado</span>
                                </li>
                                <li class="flex items-center justify-end space-x-2">
                                    <svg width="28" height="28" viewBox="0 0 13 13" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16318_47)">
                                            <circle cx="6.5" cy="6.5" r="2.5" fill="#0B6965" />
                                        </g>
                                        <circle cx="6.5" cy="6.5" r="2.5" fill="#13B5AD" />
                                        <circle cx="5.5" cy="5.5" r="2.5" fill="#D9D9D9" />
                                        <defs>
                                            <filter id="filter0_f_16318_47" x="0" y="0" width="13" height="13"
                                                filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="2"
                                                    result="effect1_foregroundBlur_16318_47" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Cotizaciones</span>
                                </li>
                                <li>y mucho más...</li>
                            </ul>
                        </div>
                    </div>
                </article>
                <!-- Tienda en linea -->
                <article
                    class="mt-32 flex space-x-32 bg-[url('/imagesLanding/features-04.webp')] bg-no-repeat bg-center px-40 pt-80 h-[900px]"
                    data-aos="zoom-in" data-aos-duration="1800">
                    <div class="w-[45%] -mt-24">
                        <div class="w-[66%] text-white self-start" data-aos="fade-down" data-aos-duration="1600"
                            data-aos-delay="900">
                            <h2 class="font-bold text-4xl text-white">Tienda en línea</h2>
                            <p class="text-justify text-2xl mt-8">
                                Lleva tu negocio al mundo digital con una tienda en línea integrada.
                            </p>
                            <p class="text-lg mt-4 text-end">
                                Sincronización con inventario
                            </p>
                            <p class="text-lg text-end">
                                Seguimiento de pedidos
                            </p>
                        </div>
                        <img class="w-[20%] object-contain select-none mt-24" :draggable="false"
                            src="@/../../public/imagesLanding/qr-code.webp"
                            alt="Código QR con el logo de EzyVentas en el centro" data-aos="fade-right"
                            data-aos-duration="400" data-aos-delay="1900">
                    </div>
                    <div class="flex space-x-10">
                        <div class="bg-[#c6c6c6]/60 rounded-[10px] h-80 w-72 relative pt-52 px-6 mt-32"
                            data-aos="fade-down" data-aos-duration="600" data-aos-delay="2000">
                            <img class="w-[60%] object-contain select-none absolute left-[calc(50%-80px)] -top-10"
                                :draggable="false" src="@/../../public/imagesLanding/robot-01.webp"
                                alt="Robot pequeño, blanco con 4 ruedas y 2 ojos grandes">
                            <div>
                                <p class="text-[#1D1D1D] text-sm font-medium">Robot de entrega</p>
                                <p class="text-[#414141] text-sm font-normal">MXN</p>
                                <p class="text-[#1D1D1D] text-lg font-bold">${{ quantity1 * 90 }} K</p>
                            </div>
                            <div class="flex items-center justify-between">
                                <InputNumber v-model="quantity1" size="small" :min="1" :max="5" />
                                <button @click="launchConfetti"
                                    class="size-8 group flex items-center justify-center rounded-full border border-[#3e3e3e] hover:border-white hover:bg-primary hover:border-transparent hover:text-white transition-all ease-linear duration-200 disabled:bg-gray-300 disabled:cursor-not-allowed disabled:text-black">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor"
                                        class="size-5 text-[#3e3e3e] group-hover:text-white transition-all ease-linear duration-200">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="bg-[#c6c6c6]/60 rounded-[10px] h-[28rem] w-72 relative pt-[21rem] px-6"
                            data-aos="fade-up" data-aos-duration="600" data-aos-delay="2100">
                            <img class="w-[60%] object-contain select-none absolute left-[calc(50%-90px)] -top-28"
                                :draggable="false" src="@/../../public/imagesLanding/robot-02.webp"
                                alt="Robot Alto, blanco con forma humanoide">
                            <div>
                                <p class="text-[#1D1D1D] text-sm font-medium">Robot asistente</p>
                                <p class="text-[#414141] text-sm font-normal">MXN</p>
                                <p class="text-[#1D1D1D] text-lg font-bold">${{ quantity2 * 180 }} K</p>
                            </div>
                            <div class="flex items-center justify-between">
                                <InputNumber v-model="quantity2" size="small" :min="1" :max="5" />
                                <button @click="launchConfetti"
                                    class="size-8 group flex items-center justify-center rounded-full border border-[#3e3e3e] hover:border-white hover:bg-primary hover:border-transparent hover:text-white transition-all ease-linear duration-200 disabled:bg-gray-300 disabled:cursor-not-allowed disabled:text-black">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor"
                                        class="size-5 text-[#3e3e3e] group-hover:text-white transition-all ease-linear duration-200">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </article>
                <!-- Reportes -->
                <article class="relative mt-32">
                    <img class="object-contain select-none absolute right-20 -top-60" :draggable="false"
                        src="@/../../public/imagesLanding/bg-05.webp" alt="Destello neón de adorno en el fondo"
                        data-aos="zoom-in" data-aos-duration="1600" data-aos-delay="800">
                    <img class="object-contain select-none absolute left-0 top-20" :draggable="false"
                        src="@/../../public/imagesLanding/bg-06.webp" alt="Destello neón de adorno en el fondo"
                        data-aos="fade-riht" data-aos-duration="1600" data-aos-delay="800">
                    <div class="flex mx-32" data-aos="fade-left" data-aos-duration="700" data-aos-offset="450">
                        <div class="w-[30%] text-white">
                            <h2 class="font-bold text-4xl text-white">Reportes</h2>
                            <p class="text-justify text-3xl mt-8">
                                Toma decisiones inteligentes con reportes en tiempo real y analisis detallados
                            </p>
                            <p class="w-[60%] text-center ml-auto mt-32">
                                Identifica tendencias y oportunidades de crecimiento
                            </p>
                        </div>
                        <figure class="w-[45%] mt-10 z-10">
                            <img class="object-contain select-none" :draggable="false"
                                src="@/../../public/imagesLanding/features-05.webp"
                                alt="Grafica de barras con colores llamativos neón">
                        </figure>
                        <figure class="w-[17%] z-10">
                            <img class="object-contain select-none" :draggable="false"
                                src="@/../../public/imagesLanding/features-06.webp"
                                alt="Graficas circulares y una flecha que indica crecimiento">
                        </figure>
                    </div>
                </article>
                <!-- ventajas -->
                <article class="relative mt-32 mx-40">
                    <p class="text-white text-2xl font-bold text-center">
                        ¿Por qué elegir Ezy Ventas?
                    </p>
                    <div class="flex space-x-24 mt-8">
                        <!-- From Uiverse.io by ayman-ashine -->
                        <div class="card w-[30%] self-center mt-6">
                            <img class="image mx-auto" alt="" src="@/../../public/imagesLanding/landing-08.webp" />
                            <div class="heading pt-4">
                                El futuro de las ventas está en tus manos
                            </div>
                            <button @click="openWhatsapp"
                                class="w-full flex items-center justify-center space-x-2 text-center bg-[#212121] text-white rounded-[10px] py-2">
                                <i class="fa-brands fa-whatsapp"></i>
                                <span class="font-bold">Contáctanos</span>
                            </button>
                        </div>
                        <div class="w-2/3">
                            <ol class="text-white text-lg font-normal *:flex *:items-center *:space-x-2 *:mt-7">
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Compatible con cualquier dispositivo (Computadoras, tablets y
                                        smartphones)</span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Ingresa desde cualquier lugar </span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Gestión de inventario y seguimiento de ventas en tiempo real</span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Productos pre-cargados para tiendas de abarrotes y papelerías</span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>No requiere de instalaciones </span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Soporte todo el año</span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>Actualizaciones y mejoras constantes</span>
                                </li>
                                <li>
                                    <svg width="24" height="24" viewBox="0 0 14 14" fill="none" class="shrink-0"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_f_16350_254)">
                                            <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        </g>
                                        <circle cx="7.36293" cy="7.36293" r="3.63636" fill="#D3FF0F" />
                                        <circle cx="6.63636" cy="6.63636" r="3.63636" fill="white" />
                                        <defs>
                                            <filter id="filter0_f_16350_254" x="0.817472" y="0.817472" width="13.0916"
                                                height="13.0916" filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feBlend mode="normal" in="SourceGraphic" in2="BackgroundImageFix"
                                                    result="shape" />
                                                <feGaussianBlur stdDeviation="1.45455"
                                                    result="effect1_foregroundBlur_16350_254" />
                                            </filter>
                                        </defs>
                                    </svg>
                                    <span>No ocupa espacio de almacenamiento, ya que toda la información se guarda en la
                                        nube.</span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </article>
            </section>
            <!-- Simulador -->
            <!-- <section id="prices" class="mt-36 text-center relative">
                <img class="object-contain select-none absolute left-0 -top-40" :draggable="false"
                    src="@/../../public/imagesLanding/bg-07.webp" alt="Destello neón de adorno en el fondo"
                    data-aos="fade-right" data-aos-duration="1200" data-aos-delay="800">
                <img class="object-contain select-none absolute right-0 bottom-10" :draggable="false"
                    src="@/../../public/imagesLanding/bg-08.webp" alt="Destello neón de adorno en el fondo" data-aos="fade-left"
                    data-aos-duration="1200" data-aos-delay="800">
                <h2 class="text-white text-2xl font-bold">SIMULADOR</h2>
                <p class="text-white text-lg mt-3">Personaliza tu suscripción con los módulos que necesitas.</p>
                <img class="object-contain select-none mx-auto" :draggable="false"
                    src="@/../../public/imagesLanding/sim-01.webp"
                    alt="Letras con color azul neón que dicen: Con 30 dias gratis de prueba">
                <div class="mt-14 text-left xl:w-[75%] xl:mx-auto mx-5">
                    <Simulator id="simulator" />
                </div>
            </section> -->
            <button v-if="showScrollButton" @click="scrollToTop"
                class="fixed bottom-10 right-4 flex items-center justify-center size-10 rounded-full bg-grayD9">
                <i class="pi pi-arrow-up animate-bounce text-gray37"></i>
            </button>
        </main>
        <!-- footer -->
        <footer class="bg-black1 p-5">
            <div class="border-b border-[#373737] w-full"></div>
            <div class="flex items-center md:justify-between text-white text-sm my-3">
                <p>Copyright &copy; 2024-2025 | Todos los derechos reservador por Ezy Ventas</p>
                <div class="flex space-x-3">
                    <a class="underline" target="_blank" :href="route('terms.show')">Términos y condiciones</a>
                    <a class="underline" target="_blank" :href="route('policy.show')">Política de privacidad</a>
                </div>
            </div>
            <div class="flex items-center justify-between">
                <figure class="mt-4">
                    <img class="w-20 lg:w-[40%]" src="@/../../public/imagesLanding/white_logo.png" alt="">
                </figure>
                <figure class="mt-4 cursor-pointer">
                    <a class="flex justify-end items-center" href="https://app.dtw.com.mx/" target="_blank">
                        <p class="text-white text-xl">BY</p>
                        <img class="w-20 lg:w-[10%]" src="@/../../public/imagesLanding/DTW_logo_blanco.png" alt="">
                    </a>
                </figure>
            </div>
        </footer>
    </div>
</template>
