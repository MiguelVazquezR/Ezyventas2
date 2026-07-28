import { ref, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';

/**
 * Composable para impresión térmica vía Web Bluetooth API.
 *
 * Flujo:
 * 1. scanAndConnect() — escanea dispositivos BT y conecta al GATT server
 * 2. sendRawCommands(base64Data) — decodifica Base64 y envía bytes chunk por chunk
 * 3. disconnect() — cierra conexión
 *
 * Compatibilidad:
 * - Chrome / Edge / Opera / Samsung Internet (Chromium): ✅ Web Bluetooth
 * - Safari / iOS / iPadOS: ❌ No soporta Web Bluetooth → usar fallback HTML/PDF
 */

// UUIDs de servicio comunes en impresoras térmicas
const SERIAL_PORT_UUID = '00001101-0000-1000-8000-00805f9b34fb';
const PHOMEMO_IMG_UUID = '0000af30-0000-1000-8000-00805f9b34fb';
const PHOMEMO_OLD_UUID = '49535343-fe7d-4ae5-8fa9-9fafd205e455';

const logger = {
    info: (...args) => console.log('[BluetoothPrinter]', ...args),
    warn: (...args) => console.warn('[BluetoothPrinter]', ...args),
    error: (...args) => console.error('[BluetoothPrinter]', ...args),
};

export function useBluetoothPrinter() {
    const toast = useToast();

    // Estado reactivo
    const device = ref(null);
    const writableChar = ref(null);
    const isScanning = ref(false);
    const isConnecting = ref(false);
    const error = ref(null);
    const isSupported = ref(false);
    const isSecure = ref(false);

    onMounted(() => {
        isSecure.value = window.isSecureContext ?? false;
        isSupported.value = typeof navigator !== 'undefined' && !!navigator.bluetooth;
        if (!isSupported.value) {
            logger.info('Web Bluetooth no soportado. Se usará fallback HTML/PDF.');
        }
    });

    // --- Búsqueda de característica escribible en el GATT server ---
    async function findWritableCharacteristic(server) {
        const services = await server.getPrimaryServices();
        logger.info(`Servicios GATT encontrados: ${services.length}`);

        let writeChar = null;
        let writeNoRespChar = null;

        for (const service of services) {
            let characteristics;
            try {
                characteristics = await service.getCharacteristics();
            } catch (e) {
                logger.warn(`No se pudieron leer características de ${service.uuid}`);
                continue;
            }

            for (const char of characteristics) {
                if (char.properties.writeWithoutResponse) {
                    logger.info(`Característica writeWithoutResponse: ${char.uuid}`);
                    writeNoRespChar = char;
                    break;
                }
                if (char.properties.write && !writeChar) {
                    logger.info(`Característica write: ${char.uuid}`);
                    writeChar = char;
                }
            }

            if (writeNoRespChar) break;
        }

        const found = writeNoRespChar || writeChar;
        if (!found) {
            throw new Error('No se encontró característica escribible en el dispositivo.');
        }

        logger.info(`Usando característica: ${found.uuid}`);
        return found;
    }

    // --- Escanear y conectar ---
    async function scanAndConnect() {
        error.value = null;

        if (!isSupported.value) {
            const msg = 'Web Bluetooth no es soportado en este navegador. Use Chrome, Edge, Opera o Samsung Internet.';
            error.value = msg;
            toast.add({ severity: 'error', summary: 'Bluetooth no soportado', detail: msg, life: 6000 });
            return;
        }

        if (!isSecure.value) {
            const msg = 'Web Bluetooth requiere HTTPS o localhost.';
            error.value = msg;
            toast.add({ severity: 'error', summary: 'Contexto inseguro', detail: msg, life: 6000 });
            return;
        }

        isScanning.value = true;

        // Si ya hay un dispositivo conectado, desconectar antes
        if (device.value?.gatt?.connected) {
            disconnect();
        }

        try {
            logger.info('Solicitando dispositivo Bluetooth...');
            const btDevice = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: [SERIAL_PORT_UUID, PHOMEMO_IMG_UUID, PHOMEMO_OLD_UUID],
            });

            logger.info(`Dispositivo seleccionado: ${btDevice.name ?? 'Sin nombre'}`);
            device.value = btDevice;
            isConnecting.value = true;

            btDevice.addEventListener('gattserverdisconnected', onDisconnected);

            logger.info('Conectando al servidor GATT...');
            const server = await btDevice.gatt.connect();
            logger.info('Servidor GATT conectado.');

            writableChar.value = await findWritableCharacteristic(server);

            toast.add({
                severity: 'success',
                summary: 'Impresora conectada',
                detail: `${btDevice.name ?? 'Impresora'} lista para imprimir.`,
                life: 3000,
            });
        } catch (e) {
            logger.error('Error en conexión Bluetooth:', e);
            let userMsg = e.message;
            if (e.name === 'NotFoundError') userMsg = 'Búsqueda cancelada por el usuario.';
            if (e.name === 'NotAllowedError') userMsg = 'Permiso de Bluetooth denegado.';
            error.value = userMsg;
            toast.add({ severity: 'error', summary: 'Error Bluetooth', detail: userMsg, life: 6000 });
            disconnect();
        } finally {
            isScanning.value = false;
            isConnecting.value = false;
        }
    }

    // --- Enviar comandos crudos (Base64 decodificado a bytes) ---
    async function sendRawCommands(base64Data) {
        if (!writableChar.value) {
            throw new Error('Impresora Bluetooth no conectada.');
        }

        // Decodificar Base64 a Uint8Array
        const binaryString = atob(base64Data);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }

        const char = writableChar.value;
        const method = char.properties.writeWithoutResponse
            ? 'writeValueWithoutResponse'
            : 'writeValue';

        const CHUNK_SIZE = 20; // Seguro para la mayoría de impresoras térmicas BT

        logger.info(`Enviando ${bytes.byteLength} bytes vía Bluetooth (método: ${method}, chunks: ${CHUNK_SIZE})`);

        let offset = 0;
        while (offset < bytes.byteLength) {
            const chunk = bytes.slice(offset, offset + CHUNK_SIZE);
            offset += chunk.byteLength;
            try {
                await char[method](chunk);
                // Pausa vital para impresoras térmicas lentas
                await new Promise(resolve => setTimeout(resolve, 25));
            } catch (e) {
                logger.error('Error enviando chunk Bluetooth:', e);
                throw new Error(`Error al enviar datos: ${e.message}`);
            }
        }

        logger.info('Todos los datos enviados correctamente.');
    }

    // --- Desconexión ---
    function onDisconnected(event) {
        const deviceName = event.target?.name ?? 'Dispositivo';
        logger.warn(`Bluetooth desconectado: ${deviceName}`);
        toast.add({
            severity: 'warn',
            summary: 'Impresora desconectada',
            detail: 'Se perdió la conexión Bluetooth.',
            life: 4000,
        });
        disconnect();
    }

    function disconnect() {
        if (device.value) {
            device.value.removeEventListener('gattserverdisconnected', onDisconnected);
            if (device.value.gatt?.connected) {
                try {
                    device.value.gatt.disconnect();
                } catch (e) {
                    logger.error('Error al desconectar GATT:', e);
                }
            }
        }
        device.value = null;
        writableChar.value = null;
        error.value = null;
    }

    onUnmounted(() => {
        disconnect();
    });

    return {
        // Estado
        device,
        isScanning,
        isConnecting,
        error,
        isSupported,
        isSecure,
        isConnected: () => !!device.value && !!writableChar.value,

        // Acciones
        scanAndConnect,
        sendRawCommands,
        disconnect,
    };
}