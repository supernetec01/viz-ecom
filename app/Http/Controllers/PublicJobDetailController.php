<?php

namespace App\Http\Controllers;

use App\Services\ApplicationFormToken;
use App\Services\ShowPublicJob;
use App\Services\VerifyTurnstile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicJobDetailController extends Controller
{
    public function __invoke(Request $request, string $slug, string $id, ShowPublicJob $detail, ApplicationFormToken $tokens, VerifyTurnstile $captcha): View|RedirectResponse
    {
        // Visibility is resolved before the slug comparison, so hidden jobs never reveal their canonical URL.
        $job = $detail->find($id);
        if ($slug !== $job->slug) {
            return redirect()->route('jobs.show', ['slug' => $job->slug, 'id' => $job->id], 301);
        }

        $accepting = $job->canReceiveApplications();
        $request->attributes->set('application-form', $accepting && $captcha->siteKey() !== '');

        return view('public.jobs.show', [
            'job' => $job, 'state' => $detail->state($job), 'accepting' => $job->canReceiveApplications(),
            'similar' => $detail->similar($job),
            'submissionToken' => $accepting ? $tokens->issue($request, $job->id) : null,
            'turnstileSiteKey' => $captcha->siteKey(),
        ]);
    }
}
