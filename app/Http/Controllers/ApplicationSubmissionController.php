<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Policies\ApplicationPolicy;
use App\Services\ApplicationFormToken;
use App\Services\SubmitApplication;
use App\Services\VerifyTurnstile;
use App\Support\ApplicationSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApplicationSubmissionController extends Controller
{
    public function __invoke(Request $request, string $id, ApplicationPolicy $policy, ApplicationFormToken $tokens, VerifyTurnstile $captcha, SubmitApplication $submit): RedirectResponse
    {
        abort_if(strlen($id) > 18, 404);
        $job = Job::publiclyViewable()->findOrFail($id);
        $policy->assertMaySubmit($job);
        $input = $request->only(['full_name', 'phone', 'email', 'portfolio_url', 'consent', 'submission_token', 'cf-turnstile-response']);
        if (isset($input['phone']) && is_string($input['phone'])) {
            $phone = preg_replace('/[\s().-]+/', '', $input['phone']);
            $input['phone'] = preg_replace('/^\+?84/', '0', $phone);
        }
        $data = Validator::make([...$input, 'cv' => $request->file('cv'), 'portfolio_file' => $request->file('portfolio_file')], [
            'full_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'regex:/^0[35789][0-9]{8}$/D'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'portfolio_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'cv' => ['nullable', 'file', 'max:10240'], 'portfolio_file' => ['nullable', 'file', 'max:10240', 'prohibits:portfolio_url'],
            'consent' => ['accepted'], 'submission_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'cf-turnstile-response' => ['required', 'string', 'max:2048'],
        ], attributes: __('applications.fields'))->validate();
        $tokens->submit($request, $job->id, $data['submission_token'], function () use ($captcha, $submit, $data, $request, $job): void {
            $captcha->verify($data['cf-turnstile-response']);
            $submit->submit($job->id, $data, $request->file('cv'), ApplicationSource::current($request->session()), $request->file('portfolio_file'));
        });

        return redirect()->route('applications.thanks');
    }
}
