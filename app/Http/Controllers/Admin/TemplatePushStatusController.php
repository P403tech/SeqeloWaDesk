<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WaTemplateSample;
use App\Services\TemplatePushLifecycle;
use Illuminate\Http\Request;

class TemplatePushStatusController extends Controller
{
    public function index(Request $request, TemplatePushLifecycle $lifecycle)
    {
        $sampleId = $request->filled('sample_id') ? (int) $request->input('sample_id') : null;
        $stage = (string) $request->query('stage', 'all');

        $samples = WaTemplateSample::query()->orderBy('slug')->get(['id', 'slug', 'title']);

        return view('admin.template-samples.push-status', [
            'stats'    => $lifecycle->adminSummary($sampleId),
            'rows'     => $lifecycle->adminRows($sampleId, $stage === 'all' ? null : $stage),
            'samples'  => $samples,
            'sampleId' => $sampleId,
            'stage'    => $stage,
            'lifecycle'=> $lifecycle,
        ]);
    }
}
