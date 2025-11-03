import { ref, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';

// Helper de Logging (opcional, para depuración)
const logger = {
    info: (...args) => console.log('[useWebBluetooth Info]', ...args),
    warn: (...args) => console.warn('[useWebBluetooth Warn]', ...args),
    error: (...args) => console.error('[useWebBluetooth Error]', ...args),
};


export function useWebBluetooth() {
    const toast = useToast();
    const bluetoothDevice = ref(null); // Almacena el objeto BluetoothDevice
    const writableCharacteristic = ref(null); // Almacena la característica GATT para escribir
    const isConnectingBluetooth = ref(false); // Loading para la conexión BT
    const isScanningBluetooth = ref(false); // Loading para el escaneo BT
    const bluetoothError = ref(null); // Errores específicos de BT
    const isSecureContext = ref(false); // True si es HTTPS o localhost

    // Verificar contexto seguro al montar
    onMounted(() => {
        isSecureContext.value = window.isSecureContext;
        logger.info(`Contexto seguro (HTTPS/localhost): ${isSecureContext.value}`);
    });

    // Intenta encontrar la característica correcta para enviar datos
    async function findWritableCharacteristic(server) { // <-- Quitamos serviceUuid
        try {
            // +++ INICIO CORRECCIÓN (Estrategia Exploratoria) +++
            // Volvemos a pedir TODOS los servicios, ya que el UUID '0xAF30'
            // parece ser solo de anuncio, pero no el de conexión real.
            logger.info("Buscando todos los servicios primarios...");
            const services = await server.getPrimaryServices();
            logger.info('Servicios GATT encontrados:', services.map(s => s.uuid));
            // +++ FIN CORRECCIÓN +++


            // Recorremos todos los servicios encontrados
            for (const service of services) {
                logger.info(`Inspeccionando características del servicio: ${service.uuid}`);
                const characteristics = await service.getCharacteristics();
                // Priorizar 'writeWithoutResponse'
                const writeWithoutResponseChar = characteristics.find(c => c.properties.writeWithoutResponse);
                if (writeWithoutResponseChar) {
                    logger.info('Característica encontrada (writeWithoutResponse):', writeWithoutResponseChar.uuid);
                    return writeWithoutResponseChar;
                }
                // Luego buscar 'write'
                const writeChar = characteristics.find(c => c.properties.write);
                if (writeChar) {
                    logger.info('Característica encontrada (write):', writeChar.uuid);
                    return writeChar;
                }
            }
            throw new Error("No se encontró ninguna característica escribible en ningún servicio.");
        } catch (e) {
            logger.error("Error al buscar características:", e);
            // El error "No service found in device" del navegador será capturado aquí.
            throw new Error(`Error buscando servicio/característica: ${e.message}`);
        }
    }

    // Escanear y conectar
    const scanAndConnectBluetooth = async () => {
        // (Misma lógica que antes, usando logger)
        if (!navigator.bluetooth) {
            bluetoothError.value = "Web Bluetooth no es soportado en este navegador.";
            toast.add({ severity: 'error', summary: 'Error Compatibilidad', detail: bluetoothError.value, life: 5000 });
            return;
        }
        if (!isSecureContext.value) {
             bluetoothError.value = "Web Bluetooth requiere HTTPS o localhost.";
             toast.add({ severity: 'error', summary: 'Contexto Inseguro', detail: bluetoothError.value, life: 5000 });
             return;
        }

        isScanningBluetooth.value = true;
        bluetoothError.value = null;
        if (bluetoothDevice.value?.gatt?.connected) { // Desconectar si ya hay uno
            disconnectBluetooth();
        }
        bluetoothDevice.value = null;
        writableCharacteristic.value = null;

        // Definimos el UUID de la impresora (de nRF Connect) como constante
        const PRINTER_SERVICE_UUID = '0000af30-0000-1000-8000-00805f9b34fb';

        try {
            logger.info("Solicitando dispositivo Bluetooth...");
            const device = await navigator.bluetooth.requestDevice({
                // +++ INICIO CORRECCIÓN (Estrategia Exploratoria) +++
                // Volvemos a aceptar todos los dispositivos, ya que el filtro de servicio es erróneo.
                acceptAllDevices: true,
                // PERO, seguimos pidiendo permiso para el servicio que 'anuncia',
                // esto debería darnos permiso para explorar el dispositivo.
                optionalServices: [PRINTER_SERVICE_UUID]
                // +++ FIN CORRECCIÓN +++
            });

            logger.info('Dispositivo seleccionado:', device.name);
            bluetoothDevice.value = device;
            isConnectingBluetooth.value = true;

            device.addEventListener('gattserverdisconnected', onDisconnected);

            logger.info('Conectando al servidor GATT...');
            const server = await device.gatt.connect();
            logger.info('Servidor GATT conectado.');

            logger.info('Buscando característica escribible...');
            // +++ INICIO CORRECCIÓN (Estrategia Exploratoria) +++
            // Llamamos a la función sin el UUID específico, para que explore todos.
            writableCharacteristic.value = await findWritableCharacteristic(server);
            // +++ FIN CORRECCIÓN +++

            if (!writableCharacteristic.value) {
                throw new Error("No se pudo encontrar característica adecuada para escribir.");
            }

            logger.info('¡Impresora Bluetooth lista!');
            toast.add({ severity: 'success', summary: 'Conectado', detail: `Impresora "${device.name}" conectada.`, life: 3000 });

        } catch (e) {
            logger.error("Error de Web Bluetooth:", e);
            let userMessage = `Error: ${e.message}. Verifique impresora y permisos.`;
            if (e.name === 'NotFoundError') userMessage = 'Búsqueda cancelada o no se seleccionó impresora.';
            if (e.name === 'NotAllowedError') userMessage = 'Permiso denegado para acceder a Bluetooth.';
            bluetoothError.value = userMessage;
            toast.add({ severity: 'error', summary: 'Error Bluetooth', detail: bluetoothError.value, life: 6000 });
            disconnectBluetooth(); // Limpiar
        } finally {
            isScanningBluetooth.value = false;
            isConnectingBluetooth.value = false;
        }
    };

    // Listener de desconexión
    const onDisconnected = () => {
        // (Misma lógica que antes)
         logger.warn('Impresora Bluetooth desconectada.');
        // Evitar toast si la desconexión fue manual (bluetoothDevice ya es null)
        if (bluetoothDevice.value) {
            toast.add({ severity: 'warn', summary: 'Desconectado', detail: 'Se perdió la conexión Bluetooth.', life: 4000 });
            disconnectBluetooth(); // Limpiar estado
        }
    };

    // Desconectar manually
    const disconnectBluetooth = () => {
        // (Misma lógica que antes)
        if (bluetoothDevice.value) {
            bluetoothDevice.value.removeEventListener('gattserverdisconnected', onDisconnected);
            if (bluetoothDevice.value.gatt?.connected) {
                try {
                    bluetoothDevice.value.gatt.disconnect();
                    logger.info("Desconexión GATT solicitada.");
                } catch (e) { logger.error("Error al desconectar GATT:", e) }
            }
        }
        // Marcar como desconectado ANTES de limpiar refs para que onDisconnected no muestre toast
        const wasConnected = !!bluetoothDevice.value;
        bluetoothDevice.value = null;
        writableCharacteristic.value = null;
        bluetoothError.value = null;
        if(wasConnected) logger.info("Estado Bluetooth limpiado.");
    };

    // Enviar datos por Bluetooth
    async function sendViaWebBluetooth(data) {
        // (Misma lógica que antes, requiere 'writableCharacteristic.value' disponible)
        if (!writableCharacteristic.value) {
             throw new Error("No hay característica Bluetooth escribible disponible.");
        }
        const characteristic = writableCharacteristic.value; // Para claridad
        const MAX_CHUNK_SIZE = 500;
        let offset = 0;
        const encoder = new TextEncoder(); // UTF-8 por defecto
        const dataBuffer = encoder.encode(data);
        logger.info(`Enviando ${dataBuffer.byteLength} bytes por Bluetooth...`);

        while (offset < dataBuffer.byteLength) {
            const chunk = dataBuffer.slice(offset, offset + MAX_CHUNK_SIZE);
            offset += chunk.byteLength;
            try {
                await characteristic.writeValueWithoutResponse(chunk);
                logger.info(`Chunk BT enviado (${chunk.byteLength} bytes), offset: ${offset}`);
                // await new Promise(resolve => setTimeout(resolve, 50)); // Pausa opcional
            } catch (e) {
                logger.error("Error al escribir chunk BT:", e);
                throw new Error(`Error enviando datos BT: ${e.message}`);
            }
        }
        logger.info('Todos los datos enviados por Bluetooth.');
    }

    // Desconectar al desmontar
    onUnmounted(() => {
        disconnectBluetooth();
    });

    return {
        bluetoothDevice,
        writableCharacteristic,
        isConnectingBluetooth,
        isScanningBluetooth,
        bluetoothError,
        isSecureContext,
        scanAndConnectBluetooth,
        disconnectBluetooth,
        sendViaWebBluetooth,
    };
}

