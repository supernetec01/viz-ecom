<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentConfirmationRejected;
use App\Models\Order;
use App\Models\User;
use App\Services\AccessGrantLifecycle;
use App\Services\ConfirmAccessOrder;
use App\Support\GrantDuration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PaymentConfirmationController
{
    public function show(Request $request, string $id, ConfirmAccessOrder $payments): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $this->form($request, $id, $payments);
    }

    public function store(Request $request, string $id, ConfirmAccessOrder $payments): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        try {
            $result = $payments->confirm($actor, $id, $request->only(['ends_at', 'starts_at', 'received_amount', 'transaction_reference', 'default_ends_at', 'default_signature']), $request);
            if ($request->expectsJson()) {
                return response()->json(['order_id' => $result['order']->id, 'grant_id' => $result['grant']->id, 'already_confirmed' => ! $result['created']])
                    ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
            }

            return redirect()->route('payments.show', ['id' => $id]);
        } catch (ValidationException $exception) {
            return $request->expectsJson() ? response()->json(['message' => __('payments.invalid'), 'errors' => $exception->errors()], 422)
                ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
                : $this->form($request, $id, $payments, 422, $exception->errors());
        } catch (PaymentConfirmationRejected $exception) {
            $message = __('payments.rejections.'.$exception->reason, array_map(fn ($amount) => number_format($amount, 0, ',', '.'), $exception->details));

            return $request->expectsJson() ? response()->json(['message' => $message], 409)
                ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
                : $this->form($request, $id, $payments, 409, ['confirmation' => [$message]]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return response()->view('payments.unavailable', [], 503)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    private function form(Request $request, string $id, ConfirmAccessOrder $payments, int $status = 200, array $errors = []): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $order = $payments->order($actor, $id);
        $duration = app(GrantDuration::class);
        $suggested = $duration->suggested($order, $order->status === 'paid' ? CarbonImmutable::now('UTC') : app(AccessGrantLifecycle::class)->start($order, CarbonImmutable::now('UTC')));
        $default = $suggested?->setTimezone(GrantDuration::TIMEZONE)->format('Y-m-d\TH:i:s') ?? '';
        $signature = $duration->signDefault($actor->id, $order->id, $default);
        $values = $status === 200 ? [] : array_map(fn ($value): string => is_string($value) || is_int($value) ? (string) $value : '',
            $request->only(['ends_at', 'received_amount', 'transaction_reference']));
        $priorDefault = $request->input('default_ends_at');
        $priorSignature = $request->input('default_signature');
        if ($status !== 200 && is_string($priorDefault) && is_string($priorSignature)
            && ($values['ends_at'] ?? null) === $priorDefault
            && hash_equals($duration->signDefault($actor->id, $order->id, $priorDefault), $priorSignature)) {
            // Preserve manual edits, but refresh an untouched suggestion on error rerenders.
            $values['ends_at'] = $default;
        }
        $bounds = $duration->bounds();

        return response()->view('payments.confirm', compact('order', 'default', 'signature', 'errors', 'values', 'bounds'), $status)
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
