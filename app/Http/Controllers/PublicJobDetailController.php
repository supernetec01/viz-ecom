<?php

namespace App\Http\Controllers;

use App\Services\ShowPublicJob;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PublicJobDetailController extends Controller
{
    public function __invoke(string $slug, string $id, ShowPublicJob $detail): View|RedirectResponse
    {
        // Visibility is resolved before the slug comparison, so hidden jobs never reveal their canonical URL.
        $job = $detail->find($id);
        if ($slug !== $job->slug) {
            return redirect()->route('jobs.show', ['slug' => $job->slug, 'id' => $job->id], 301);
        }

        return view('public.jobs.show', [
            'job' => $job, 'state' => $detail->state($job), 'accepting' => $job->canReceiveApplications(),
            'similar' => $detail->similar($job),
        ]);
    }
}
