<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\AccessGrantLifecycle;
use App\Support\GrantDuration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccessGrantLifecycleController
{
    public function show(Request $request, string $id, AccessGrantLifecycle $lifecycle): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $grant = $lifecycle->grant($actor, $id);
        $grants = $lifecycle->affected($grant);
        $signature = $lifecycle->previewSignature($actor, $grant, $grants);
        $bounds = app(GrantDuration::class)->bounds();

        return response()->view('grants.manage', compact('grant', 'grants', 'signature', 'bounds'))
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function revoke(Request $request, string $id, AccessGrantLifecycle $lifecycle): Response
    {
        return $this->mutate($request, $id, $lifecycle, false);
    }

    public function renew(Request $request, string $id, AccessGrantLifecycle $lifecycle): Response
    {
        return $this->mutate($request, $id, $lifecycle, true);
    }

    private function mutate(Request $request, string $id, AccessGrantLifecycle $lifecycle, bool $renew): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        try {
            if ($renew) {
                $order = $lifecycle->renew($actor, $id, $request->only(['method', 'reason', 'duration_days']));

                return redirect()->route('payments.show', ['id' => $order->id]);
            }
            $count = $lifecycle->revoke($actor, $id, $request->only(['reason', 'preview_signature']), $request);

            return $request->expectsJson() ? response()->json(['revoked' => $count])->header('Cache-Control', 'no-store, private')
                : redirect()->route('grants.show', ['id' => $id])->with('status', __('grants.revoked', ['count' => $count]));
        } catch (ValidationException $exception) {
            return $request->expectsJson() ? response()->json(['errors' => $exception->errors()], 422)->header('Cache-Control', 'no-store, private')
                : redirect()->route('grants.show', ['id' => $id])->withErrors($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 503], true)) {
                throw $exception;
            }

            return response()->view('grants.error', ['message' => $exception->getMessage() ?: __('grants.unavailable')], $exception->getStatusCode())
                ->header('Cache-Control', 'no-store, private');
        } catch (Throwable) {
            return response()->view('grants.error', ['message' => __('grants.unavailable')], 503)->header('Cache-Control', 'no-store, private');
        }
    }
}
