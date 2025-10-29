import { ref, watch, computed } from 'vue';
import axios from 'axios';

// URL base del plugin (podría hacerse configurable si es necesario)
const PLUGIN_BASE_URL = 'http://localhost:8000';

export function usePrintPlugin() {
    const printers = ref([]);
    const selectedTicketPrinter = ref(localStorage.getItem('selectedTicketPrinter') || null);
    const selectedLabelPrinter = ref(localStorage.getItem('selectedLabelPrinter') || null);
    const isLoadingPrinters = ref(false);
    const pluginError = ref(null); // Renombrado para evitar conflicto

    const fetchPrinters = async () => {
        isLoadingPrinters.value = true;
        pluginError.value = null;
        printers.value = [];
        try {
            const response = await fetch(`${PLUGIN_BASE_URL}/impresoras`);
            if (!response.ok) {
                 const errorData = await response.text(); // Leer cuerpo para más detalles
                 throw new Error(`Error ${response.status}: ${errorData || 'No se pudo conectar con el plugin.'}`);
            }
            const printerList = await response.json();
             // Asegurarse de que sea un array
            if (!Array.isArray(printerList)) {
                 throw new Error("La respuesta del plugin no es una lista válida de impresoras.");
            }
            printers.value = printerList;

            // Seleccionar por defecto si no hay selección o la selección no existe
            if (printerList.length > 0) {
                if (!selectedTicketPrinter.value || !printerList.includes(selectedTicketPrinter.value)) {
                    selectedTicketPrinter.value = printerList[0];
                }
                if (!selectedLabelPrinter.value || !printerList.includes(selectedLabelPrinter.value)) {
                    selectedLabelPrinter.value = printerList[0];
                }
            } else {
                 // Si no hay impresoras, limpiar selecciones
                 selectedTicketPrinter.value = null;
                 selectedLabelPrinter.value = null;
            }
        } catch (e) {
            pluginError.value = `Error al obtener impresoras: ${e.message}. Asegúrate de que el plugin esté en ejecución.`;
            console.error("Error en fetchPrinters:", e);
             // Limpiar selecciones en caso de error
             selectedTicketPrinter.value = null;
             selectedLabelPrinter.value = null;
        } finally {
            isLoadingPrinters.value = false;
        }
    };

    // Función para enviar datos al plugin
    const sendToPlugin = async (printerName, operations, paperWidth) => {
        try {
             console.log(`Enviando al plugin -> Impresora: ${printerName}, Ancho: ${paperWidth}, Operaciones:`, operations); // Log detallado
            const pluginResponse = await fetch(`${PLUGIN_BASE_URL}/imprimir`, {
                method: "POST",
                body: JSON.stringify({
                    nombreImpresora: printerName,
                    operaciones: operations,
                    anchoImpresora: paperWidth, // Asegurarse de enviar el ancho correcto
                }),
                headers: { "Content-Type": "application/json" },
            });

            const result = await pluginResponse.json();
             console.log("Respuesta del plugin:", result); // Log de respuesta

            if (!result.ok) {
                // Si el plugin devuelve un error específico, usarlo
                throw new Error(result.message || 'El plugin reportó un error desconocido.');
            }
            return result; // Devolver resultado si fue exitoso

        } catch (e) {
            // Capturar errores de red o errores lanzados desde la respuesta
            console.error(`Error enviando al plugin (${printerName}):`, e);
            throw new Error(`Error comunicándose con el plugin: ${e.message}`); // Lanzar un nuevo error estandarizado
        }
    };

    // Guardar selecciones en localStorage
    watch(selectedTicketPrinter, (newVal) => { if (newVal) localStorage.setItem('selectedTicketPrinter', newVal); });
    watch(selectedLabelPrinter, (newVal) => { if (newVal) localStorage.setItem('selectedLabelPrinter', newVal); });


    return {
        printers,
        selectedTicketPrinter,
        selectedLabelPrinter,
        isLoadingPrinters,
        pluginError,
        fetchPrinters,
        sendToPlugin,
    };
}