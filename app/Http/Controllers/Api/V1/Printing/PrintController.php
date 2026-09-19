<?php

namespace App\Http\Controllers\Api\V1\Printing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Printing\BluetoothPayloadRequest;
use App\Http\Requests\Api\V1\Printing\PrintPayloadRequest;
use App\Http\Requests\Api\V1\Printing\PrintTemplatesRequest;
use App\Http\Requests\Api\V1\Printing\TicketHtmlRequest;
use App\Http\Requests\Api\V1\Printing\WhatsAppTicketRequest;
use App\Models\PrintTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PrintEncoderService;
use App\Services\Printing\PrintDataSourceResolver;
use App\Services\WhatsAppTicketService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Printing and WhatsApp tickets for the mobile app.
 *
 * The server owns the templates: the app only receives the documents already
 * encoded (ESC/POS commands, TSPL operations or HTML) or the WhatsApp payload,
 * so a ticket looks the same whichever client prints it.
 */
class PrintController extends Controller
{
    public function __construct(
        private readonly PrintDataSourceResolver $dataSources,
        private readonly WhatsAppTicketService $whatsAppTickets,
    ) {}

    public function templates(PrintTemplatesRequest $request): JsonResponse
    {
        $query = PrintTemplate::where('subscription_id', $request->user()->branch?->subscription_id);

        if ($context = $request->validated('context')) {
            $query->where('context_type', $context);
        }

        if ($type = $request->validated('type')) {
            $query->where('type', $type);
        }

        return response()->json(
            $query->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (PrintTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'type' => $template->type instanceof \BackedEnum ? $template->type->value : $template->type,
                    'context_type' => $template->context_type,
                    'paper_width' => $this->paperWidth($template),
                    'is_default' => (bool) $template->is_default,
                    'config' => $template->content['config'] ?? [],
                ])
                ->values()
                ->all()
        );
    }

    public function bluetoothPayload(BluetoothPayloadRequest $request): JsonResponse
    {
        $template = $this->findTemplateOrFail((int) $request->validated('template_id'), $request->user());

        return response()->json([
            'commands_base64' => PrintEncoderService::encodeEscPosToBase64(
                $template,
                $this->resolveSource($request),
                $request->options()
            ),
            'paperWidth' => $this->paperWidth($template),
        ]);
    }

    public function payload(PrintPayloadRequest $request): JsonResponse
    {
        $template = $this->findTemplateOrFail((int) $request->validated('template_id'), $request->user());

        return response()->json([
            'operations' => PrintEncoderService::encode(
                $template,
                $this->resolveSource($request),
                $request->options()
            ),
            'paperWidth' => $this->paperWidth($template),
            'feedLines' => $template->content['config']['feedLines'] ?? 0,
        ]);
    }

    public function ticketHtml(TicketHtmlRequest $request): JsonResponse
    {
        $template = $this->findTemplateOrFail((int) $request->validated('template_id'), $request->user());

        return response()->json([
            'html' => PrintEncoderService::encodeTicketToHtml($template, $this->resolveSource($request)),
            'paperWidth' => $this->paperWidth($template),
            'template_name' => $template->name,
        ]);
    }

    public function whatsappTicket(WhatsAppTicketRequest $request): JsonResponse
    {
        $dataSource = $this->resolveSource($request);

        if (!$dataSource instanceof Transaction) {
            return response()->json([
                'ticket' => null,
                'customer_phone' => null,
                'customer_id' => null,
            ]);
        }

        $customer = $dataSource->customer;

        // Orders get their own ticket (with the delivery status).
        if ($dataSource->isOrder()) {
            $contactInfo = $dataSource->contact_info;
            $contactPhone = is_array($contactInfo) ? ($contactInfo['phone'] ?? null) : null;

            return response()->json([
                'ticket' => $this->whatsAppTickets->buildOrderPayload($dataSource),
                'customer_phone' => $contactPhone ?: ($customer?->phone ?: null),
                'customer_id' => $customer?->id,
            ]);
        }

        return response()->json([
            'ticket' => $this->whatsAppTickets->buildSalePayload($dataSource),
            'customer_phone' => $customer?->phone,
            'customer_id' => $customer?->id,
        ]);
    }

    private function resolveSource(BluetoothPayloadRequest|PrintPayloadRequest|TicketHtmlRequest|WhatsAppTicketRequest $request): mixed
    {
        return $this->dataSources->resolve(
            $request->validated('data_source_type'),
            (int) $request->validated('data_source_id'),
            $request->user()
        );
    }

    private function findTemplateOrFail(int $templateId, ?User $user): PrintTemplate
    {
        $template = PrintTemplate::find($templateId);

        if (!$template || (int) $template->subscription_id !== (int) $user?->branch?->subscription_id) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return $template;
    }

    private function paperWidth(PrintTemplate $template): string
    {
        return $template->content['config']['paperWidth'] ?? '80mm';
    }
}
