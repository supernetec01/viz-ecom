<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ApplicationFormToken
{
    public function issue(Request $request, int $jobId): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put($this->key($request, $token), $jobId, now()->addHour());

        return $token;
    }

    public function submit(Request $request, int $jobId, string $token, Closure $submit): void
    {
        $key = $this->key($request, $token);
        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) {
            $this->reject();
        }
        try {
            if (Cache::get($key) !== $jobId) {
                $this->reject();
            }
            $submit();
            Cache::forget($key);
        } finally {
            $lock->release();
        }
    }

    private function key(Request $request, string $token): string
    {
        return 'application-form:'.hash('sha256', $request->session()->getId().'|'.$token);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['submission_token' => __('applications.form_expired')]);
    }
}
