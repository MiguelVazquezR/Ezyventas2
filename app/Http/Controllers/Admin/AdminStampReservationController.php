<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ConfirmManualReviewAction;
use App\Actions\Billing\ReleaseManualReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmManualReviewRequest;
use App\Models\Billing\StampReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AdminStampReservationController
 *
 * Panel for manual review of stamp reservations that exhausted their
 * automatic retries. The admin decides deliberately — there is NEVER an
 * automatic retry button here.
 */
class AdminStampReservationController extends Controller
{
    /**
     * List stamp reservations in manual_review.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->integer('rows', 25) ?: 25;
        $sortField = $request->input('sortField', 'updated_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        $sortable = ['created_at', 'updated_at', 'attempts'];
        $orderBy = in_array($sortField, $sortable, true) ? $sortField : 'updated_at';
        $direction = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

        $reservations = StampReservation::with([
                'fiscalProfile.subscription',
                'reference', // normalmente Invoice
            ])
            ->where('status', 'manual_review')
            ->orderBy($orderBy, $direction)
            ->paginate($perPage);

        return Inertia::render('Admin/StampReservations/Index', [
            'reservations' => $reservations,
        ]);
    }

    /**
     * Confirm that the stamping actually happened (verified externally).
     */
    public function confirm(
        ConfirmManualReviewRequest $request,
        StampReservation $stampReservation,
        ConfirmManualReviewAction $action,
    ): RedirectResponse {
        if (! $stampReservation->isManualReview()) {
            return back()->with('error', 'Esta reserva ya no está en revisión manual.');
        }

        try {
            $action->execute(
                $stampReservation,
                $request->validated('uuid'),
                $request->validated('cfdi_xml'),
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Timbrado confirmado. La factura quedó certificada.');
    }

    /**
     * Release the reservation because the stamping never happened.
     * The invoice returns to DRAFT so the user can retry with a new customid.
     */
    public function release(
        StampReservation $stampReservation,
        ReleaseManualReviewAction $action,
    ): RedirectResponse {
        if (! $stampReservation->isManualReview()) {
            return back()->with('error', 'Esta reserva ya no está en revisión manual.');
        }

        try {
            $action->execute($stampReservation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reserva liberada. La factura volvió a borrador para reintentar.');
    }
}
