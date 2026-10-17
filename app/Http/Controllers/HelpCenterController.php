<?php

namespace App\Http\Controllers;

use App\Services\HelpCenter\HelpCenterContentService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscriber-facing help center: written guides and videos grouped by module.
 */
class HelpCenterController extends Controller
{
    public function index(HelpCenterContentService $service): Response
    {
        return Inertia::render('HelpCenter/Index', [
            'modules' => $service->modules(),
        ]);
    }

    public function show(string $module, HelpCenterContentService $service): Response
    {
        $contents = $service->module($module);

        abort_if($contents === null, 404);

        return Inertia::render('HelpCenter/Show', [
            'module' => $contents,
        ]);
    }
}
