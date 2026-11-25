<section class="application-section detail-section" id="ung-tuyen" aria-labelledby="application-heading">
    <h2 id="application-heading">{{ __('applications.heading') }}</h2>
    @if (session('application_received'))<p class="job-status-notice" role="status">{{ __('applications.received') }}</p>@endif
    @if ($errors->any())<div class="job-status-notice" role="alert"><p>{{ __('applications.correct_errors') }}</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('applications.store', ['id' => $job->id]) }}" enctype="multipart/form-data" data-application-form>
        @csrf
        <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
        <div class="application-fields">
            @foreach (['full_name' => 'text', 'phone' => 'tel', 'email' => 'email', 'portfolio_url' => 'url'] as $field => $type)
                <div><label for="application-{{ $field }}">{{ __('applications.fields.'.$field) }} {{ in_array($field, ['full_name', 'phone']) ? '*' : __('applications.optional') }}</label>
                    <input id="application-{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field) }}" maxlength="{{ ['full_name' => 255, 'phone' => 40, 'email' => 254, 'portfolio_url' => 2048][$field] }}" @required(in_array($field, ['full_name', 'phone'])) aria-describedby="error-{{ $field }}" autocomplete="{{ ['full_name' => 'name', 'phone' => 'tel', 'email' => 'email', 'portfolio_url' => 'off'][$field] }}">
                    <p class="field-error" id="error-{{ $field }}">@error($field){{ $message }}@enderror</p>
                </div>
            @endforeach
        </div>
        <label for="application-cv">{{ __('applications.fields.cv') }} {{ __('applications.optional') }}</label>
        <input id="application-cv" type="file" name="cv" accept=".pdf,.docx" aria-describedby="cv-help error-cv">
        <p id="cv-help">{{ __('applications.cv_help') }} @if (! config('pdf-inspection.enabled') || (app()->isProduction() && ! config('pdf-inspection.production_enabled'))){{ __('applications.pdf_inspection_pending') }}@endif</p>
        <p class="field-error" id="error-cv">@error('cv'){{ $message }}@enderror</p>
        <label for="application-portfolio-file">{{ __('applications.fields.portfolio_file') }} {{ __('applications.optional') }}</label>
        <input id="application-portfolio-file" type="file" name="portfolio_file" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="portfolio-help error-portfolio-file">
        <p id="portfolio-help">{{ __('applications.portfolio_help') }}</p>
        <p class="field-error" id="error-portfolio-file">@error('portfolio_file'){{ $message }}@enderror</p>
        <label class="application-consent"><input type="checkbox" name="consent" value="1" @checked(old('consent')) required> <span>{{ __('applications.consent') }} <a href="{{ route('data-policy') }}" target="_blank" rel="noopener noreferrer">{{ __('applications.policy') }}</a></span></label>
        <p class="field-error">@error('consent'){{ $message }}@enderror</p>
        <p>{{ __('applications.captcha_disclosure') }}</p>
        @if (app()->environment('local', 'testing'))<p>{{ __('applications.demo_captcha') }}</p>@endif
        @if ($turnstileSiteKey !== '')
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-action="apply_job" data-language="vi"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" async defer></script>
        @else <p role="alert">{{ __('applications.captcha_failed') }}</p> @endif
        <p class="field-error">@error('cf-turnstile-response'){{ $message }}@enderror</p>
        <button type="submit" class="button button-primary" data-submitting="{{ __('applications.submitting') }}" @disabled($turnstileSiteKey === '')>{{ __('applications.send') }}</button>
    </form>
</section>
