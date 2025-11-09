import { ref, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';

// --- Logger de depuración ---
const logger = {
    info: (...args) => console.log('[useWebBluetooth Info]', ...args),
    warn: (...args) => console.warn('[useWebBluetooth Warn]', ...args),
    error: (...args) => console.error('[useWebBluetooth Error]', ...args),
};

// --- UUIDs de Servicio Conocidos ---

// 1. UUID de Puerto Serial estándar (Usado por muchas impresoras)
const SERIAL_PORT_PROFILE_UUID = '00001101-0000-1000-8000-00805f9b34fb';
// 2. UUID de tus imágenes nRF Connect (Anunciado por Phomemo Q30)
const PHOMEMO_IMG_UUID = '0000af30-0000-1000-8000-00805f9b34fb';
// 3. UUID de tu código antiguo funcional (PrintTicket.vue)
const PHOMEMO_OLD_CODE_UUID = '49535343-fe7d-4ae5-8fa9-9fafd205e455';


export function useWebBluetooth() {
    const toast = useToast();
    const bluetoothDevice = ref(null);
    const writableCharacteristic = ref(null);
    const isConnectingBluetooth = ref(false);
    const isScanningBluetooth = ref(false);
    const bluetoothError = ref(null);
    const isSecureContext = ref(false);

    onMounted(() => {
        isSecureContext.value = window.isSecureContext;
        if (!isSecureContext.value) {
            logger.error("La página no se sirve en un contexto seguro (HTTPS o localhost). Web Bluetooth será deshabilitado.");
        }
    });

    /**
     * Esta es la función clave. En lugar de buscar un servicio/característica
     * específicos, explorará TODOS los servicios del dispositivo hasta
     * encontrar uno que permita la escritura.
     */
    async function findWritableCharacteristic(server) {
        logger.info("Buscando servicios primarios...");
        
        let services;
        try {
            // Obtener todos los servicios primarios
            services = await server.getPrimaryServices();
            if (!services || services.length === 0) {
                throw new Error("No se encontraron servicios primarios en el dispositivo.");
            }
        } catch (e) {
            logger.error("Error obteniendo servicios primarios:", e);
            throw new Error(`No se pudieron obtener servicios: ${e.message}`);
        }
        
        logger.info(`Servicios GATT encontrados: ${services.length}`, services.map(s => s.uuid));

        // Priorizamos 'writeWithoutResponse' (más rápido para imprimir)
        let writeChar = null;
        let writeWithoutResponseChar = null;

        // Recorrer todos los servicios
        for (const service of services) {
            logger.info(`Inspeccionando servicio: ${service.uuid}`);
            let characteristics;
            try {
                characteristics = await service.getCharacteristics();
            } catch (e) {
                logger.warn(`No se pudieron obtener características para el servicio ${service.uuid}`, e.message);
                continue; // Saltar al siguiente servicio
            }
            
            // Recorrer todas las características de este servicio
            for (const characteristic of characteristics) {
                const props = characteristic.properties;
                // logger.info(`-- Característica: ${characteristic.uuid}`, props);

                // Encontrar la primera característica que soporte 'writeWithoutResponse'
                if (props.writeWithoutResponse) {
                    logger.info(`¡Característica 'writeWithoutResponse' encontrada!: ${characteristic.uuid}`);
                    writeWithoutResponseChar = characteristic;
                    break; // Salir del bucle de características
                }
                // Si no, guardar la primera que soporte 'write'
                if (props.write && !writeChar) {
                    logger.info(`Característica 'write' encontrada: ${characteristic.uuid}`);
                    writeChar = characteristic;
                }
            }
            // Si ya encontramos la ideal (WithoutResponse), no necesitamos seguir buscando
            if (writeWithoutResponseChar) {
                break; // Salir del bucle de servicios
            }
        }

        // Devolver la característica priorizada
        const foundChar = writeWithoutResponseChar || writeChar;
        if (foundChar) {
            logger.info(`Usando característica: ${foundChar.uuid} (Método: ${writeWithoutResponseChar ? 'writeWithoutResponse' : 'write'})`);
            return foundChar;
        }

        // Si se llega aquí, no se encontró nada
        throw new Error("No se encontró ninguna característica con propiedad 'write' o 'writeWithoutResponse'.");
    }

    // Escanear y conectar
    const scanAndConnectBluetooth = async () => {
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
        
        if (bluetoothDevice.value?.gatt?.connected) {
            disconnectBluetooth();
        }
        bluetoothDevice.value = null;
        writableCharacteristic.value = null;

        try {
            logger.info("Solicitando dispositivo Bluetooth...");
            const device = await navigator.bluetooth.requestDevice({
                // 1. Aceptar todos los dispositivos. (Esto es lo que muestra la Phomemo)
                acceptAllDevices: true,
                
                // 2. Pedir permiso para TODOS los servicios que sospechamos.
                // Esta es la clave para que 'getPrimaryServices()' funcione.
                optionalServices: [
                    SERIAL_PORT_PROFILE_UUID, // El estándar
                    PHOMEMO_IMG_UUID,         // El de las imágenes nRF
                    PHOMEMO_OLD_CODE_UUID     // El de tu código antiguo
                ]
            });

            logger.info('Dispositivo seleccionado:', device.name);
            bluetoothDevice.value = device;
            isConnectingBluetooth.value = true;

            // Escuchar desconexiones
            device.addEventListener('gattserverdisconnected', onDisconnected);

            logger.info('Conectando al servidor GATT...');
            const server = await device.gatt.connect();
            logger.info('Servidor GATT conectado.');

            logger.info('Buscando característica escribible (método exploratorio)...');
            writableCharacteristic.value = await findWritableCharacteristic(server);

            logger.info('¡Impresora Bluetooth lista!');
            toast.add({ severity: 'success', summary: 'Conectado', detail: `Impresora "${device.name}" conectada.`, life: 3000 });

        } catch (e) {
            logger.error("Error en el proceso de conexión de Web Bluetooth:", e);
            let userMessage = `Error: ${e.message}. Verifique impresora y permisos.`;
            if (e.name === 'NotFoundError') userMessage = 'Búsqueda cancelada.';
            if (e.name === 'NotAllowedError') userMessage = 'Permiso denegado para Bluetooth.';
            bluetoothError.value = userMessage;
            toast.add({ severity: 'error', summary: 'Error Bluetooth', detail: userMessage, life: 6000 });
            disconnectBluetooth(); // Limpiar
        } finally {
            isScanningBluetooth.value = false;
            isConnectingBluetooth.value = false;
        }
    };

    // Listener de desconexión
    const onDisconnected = (event) => {
         const deviceName = event.target?.name || 'Dispositivo';
         logger.warn(`Bluetooth desconectado: ${deviceName}`);
        if (bluetoothDevice.value) {
            toast.add({ severity: 'warn', summary: 'Desconectado', detail: 'Se perdió la conexión Bluetooth.', life: 4000 });
            disconnectBluetooth();
        }
    };

    // Desconectar manualmente
    const disconnectBluetooth = () => {
        if (bluetoothDevice.value) {
            bluetoothDevice.value.removeEventListener('gattserverdisconnected', onDisconnected);
            if (bluetoothDevice.value.gatt?.connected) {
                try {
                    bluetoothDevice.value.gatt.disconnect();
                    logger.info("Desconexión GATT solicitada.");
                } catch (e) { logger.error("Error al desconectar GATT:", e) }
            }
        }
        const wasConnected = !!bluetoothDevice.value;
        bluetoothDevice.value = null;
        writableCharacteristic.value = null;
        bluetoothError.value = null;
        if(wasConnected) logger.info("Estado Bluetooth limpiado.");
    };

    /**
     * Envia datos crudos (raw data) a la impresora.
     * @param {ArrayBuffer | Uint8Array} data Los datos crudos a enviar.
     */
    async function sendViaWebBluetooth(data) {
        if (!writableCharacteristic.value) {
             throw new Error("Característica Bluetooth no conectada.");
        }
        
        const characteristic = writableCharacteristic.value;
        
        // Determinar el método de escritura (priorizar sin respuesta)
        const method = characteristic.properties.writeWithoutResponse 
            ? 'writeValueWithoutResponse' 
            : 'writeValue';

        // Determinar tamaño de paquete (chunk)
        // Tu código antiguo usaba 20, lo cual es muy seguro.
        // El MTU - 3 es lo ideal, pero 20 es una apuesta segura para Phomemo.
        const MAX_CHUNK_SIZE = 20; 
        
        // Asegurarnos de que 'data' es un buffer
        let dataBuffer;
        if (data instanceof ArrayBuffer) {
            dataBuffer = new Uint8Array(data);
        } else if (data instanceof Uint8Array) {
            dataBuffer = data;
        } else {
            logger.error("Tipo de dato incorrecto. 'sendViaWebBluetooth' espera un ArrayBuffer o Uint8Array.");
            throw new Error("Datos de entrada no son un buffer.");
        }
        
        logger.info(`Enviando ${dataBuffer.byteLength} bytes por Bluetooth...`);
        logger.info(`Usando método: ${method} con chunks de ${MAX_CHUNK_SIZE} bytes.`);

        let offset = 0;
        while (offset < dataBuffer.byteLength) {
            const chunk = dataBuffer.slice(offset, offset + MAX_CHUNK_SIZE);
            offset += chunk.byteLength;
            try {
                await characteristic[method](chunk);
                logger.info(`Chunk BT enviado (${chunk.byteLength} bytes), offset: ${offset}`);
                // Pausa corta (vital para algunas impresoras)
                await new Promise(resolve => setTimeout(resolve, 20)); 
            } catch (e) {
                logger.error("Error al escribir chunk BT:", e);
                throw new Error(`Error enviando datos BT: ${e.message}`);
            }
        }
        logger.info('Todos los datos enviados por Bluetooth.');
    }

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

