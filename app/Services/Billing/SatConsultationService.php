<?php

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SatConsultationService
 *
 * Queries the SAT's public SOAP service (ConsultaCFDIService)
 * to determine the real cancelation status of a CFDI.
 *
 * Used when a cancelation requires receiver acceptance
 * (isCancelable = "Cancelable con aceptación") to check
 * whether the receiver has accepted/rejected it yet.
 */
class SatConsultationService
{
    /**
     * Consult the SAT for the current status of a CFDI.
     *
     * Returns parsed response with keys:
     *  - estado: 'Vigente' | 'Cancelado'
     *  - esCancelable: 'Cancelable sin aceptación' | 'Cancelable con aceptación' | 'No cancelable'
     *  - estatusCancelacion: string|null
     *  - codigoEstatus: string
     *
     * @throws \RuntimeException When the SAT endpoint is unreachable.
     */
    public function consult(Invoice $invoice): array
    {
        $invoice->load('fiscalProfile');

        if (! $invoice->uuid) {
            throw new \RuntimeException('La factura no tiene UUID asignado.');
        }

        if (! $invoice->sello_cfdi) {
            throw new \RuntimeException('La factura no tiene el sello digital del CFDI guardado.');
        }

        // Last 8 chars of the SelloCFDI (sello_cfdi column)
        $sello8 = mb_substr($invoice->sello_cfdi, -8);

        $rfcs = $this->resolveRfcs($invoice);

        // Build the expresión impresa query string
        $expresionImpresa = "?re={$rfcs['emisor']}&rr={$rfcs['receptor']}&tt={$invoice->total}&id={$invoice->uuid}&fe={$sello8}";

        $endpoint = app()->environment('production')
            ? 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc'
            : 'https://pruebacfdiconsultaqr.cloudapp.net/ConsultaCFDIService.svc';

        $soapBody = $this->buildSoapEnvelope($expresionImpresa);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'text/xml;charset="utf-8"',
                'SOAPAction'   => 'http://tempuri.org/IConsultaCFDIService/Consulta',
                'Accept'       => 'text/xml',
            ])
            ->withBody($soapBody, 'text/xml')
            ->timeout(15)
            ->post($endpoint);

            if ($response->failed()) {
                Log::error('SAT consultation HTTP error', [
                    'invoice_id' => $invoice->id,
                    'uuid'       => $invoice->uuid,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);

                throw new \RuntimeException('No se pudo consultar el SAT en este momento. Intenta de nuevo más tarde.');
            }

            return $this->parseResponse($response->body(), $invoice);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('SAT consultation connection error', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            throw new \RuntimeException('El servicio de consulta del SAT no está disponible. Intenta de nuevo más tarde.');
        }
    }

    /**
     * Resolve emisor and receptor RFCs from the invoice.
     */
    private function resolveRfcs(Invoice $invoice): array
    {
        return [
            'emisor'   => $invoice->fiscalProfile?->rfc ?? '',
            'receptor' => $invoice->receiver_rfc ?? '',
        ];
    }

    /**
     * Build the SOAP envelope XML.
     */
    private function buildSoapEnvelope(string $expresionImpresa): string
    {
        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
  <soapenv:Header/>
  <soapenv:Body>
    <tem:Consulta>
      <tem:expresionImpresa>
        <![CDATA[{$expresionImpresa}]]>
      </tem:expresionImpresa>
    </tem:Consulta>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    /**
     * Parse the SAT SOAP response into a clean array.
     */
    private function parseResponse(string $xml, Invoice $invoice): array
    {
        // Suppress XML parsing warnings for malformed responses
        $internalErrors = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            $doc->loadXML($xml);

            // Try to find the namespace used
            $namespaces = $this->detectNamespaces($doc);

            $result = [
                'codigoEstatus'      => $this->getNodeValue($doc, 'CodigoEstatus', $namespaces),
                'esCancelable'       => $this->getNodeValue($doc, 'EsCancelable', $namespaces),
                'estado'             => $this->getNodeValue($doc, 'Estado', $namespaces),
                'estatusCancelacion' => $this->getNodeValue($doc, 'EstatusCancelacion', $namespaces) ?: null,
            ];

            Log::info('SAT consultation result', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
                'result'     => $result,
            ]);

            return $result;
        } finally {
            libxml_use_internal_errors($internalErrors);
        }
    }

    /**
     * Detect namespaces from the SOAP response.
     */
    private function detectNamespaces(\DOMDocument $doc): array
    {
        // SAT uses namespace "a" in the response
        return ['a' => 'http://schemas.datacontract.org/2004/07/Sat.ConsultaCFDIService'];
    }

    /**
     * Get a node value by local name, trying multiple namespace patterns.
     */
    private function getNodeValue(\DOMDocument $doc, string $localName, array $namespaces): ?string
    {
        foreach ($namespaces as $prefix => $ns) {
            $nodes = $doc->getElementsByTagNameNS($ns, $localName);
            if ($nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        // Fallback: search by tag name without namespace
        $nodes = $doc->getElementsByTagName($localName);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Apply the SAT consultation result to the invoice.
     *
     * Returns a status string for the UI flash message.
     */
    public function applyResult(Invoice $invoice, array $satResult): string
    {
        $estado = $satResult['estado'] ?? '';
        $estatusCancelacion = $satResult['estatusCancelacion'] ?? null;

        $invoice->update([
            'cancelation_status'         => $estatusCancelacion,
            'cancelation_last_checked_at' => now(),
        ]);

        if ($estado === 'Cancelado') {
            $invoice->update([
                'status'      => InvoiceStatus::CANCELED,
                'canceled_at' => $invoice->canceled_at ?? now(),
            ]);

            Log::info('CFDI cancelation confirmed by SAT', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
            ]);

            return 'canceled';
        }

        if ($estatusCancelacion === 'Solicitud rechazada') {
            // Receiver rejected — revert to certified
            $invoice->update([
                'status'                          => InvoiceStatus::CERTIFIED,
                'cancelation_requires_acceptance' => false,
            ]);

            Log::info('CFDI cancelation rejected by receiver', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
            ]);

            return 'rejected';
        }

        if ($estatusCancelacion === 'Plazo vencido') {
            // Deadline expired without resolution
            return 'expired';
        }

        // Still in process
        return 'pending';
    }
}
