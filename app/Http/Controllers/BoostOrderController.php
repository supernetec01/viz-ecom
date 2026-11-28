<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentConfirmationRejected;
use App\Models\Job;
use App\Models\JobBoost;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\BoostOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BoostOrderController
{
    public function showJob(Request $request, string $id, BoostOrders $orders): Response
    {
        $actor = $this->actor($request);
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $job = $orders->jobs($actor)->find($id);
        abort_unless($job !== null, 403);
        $plans = Plan::query()->where('is_active', true)->orderBy('duration_days')->orderBy('id')->get();

        return $this->page('boosts.request', compact('job', 'plans'));
    }

    public function store(Request $request, string $id, BoostOrders $orders): Response
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Job::class);
        try {
            $order = $orders->request($actor, $id, $request->only(['plan_id', 'method', 'reason']));

            return redirect()->route('boost-orders.show', ['id' => $order->id]);
        } catch (Throwable $exception) {
            return $this->failure($request, $exception);
        }
    }

    public function showOrder(Request $request, string $id, BoostOrders $orders): Response
    {
        $order = $orders->order($this->actor($request), $id);
        $boost = JobBoost::query()->where('order_id', $order->id)->first();
        $canConfirm = Gate::forUser($this->actor($request))->allows('viewAny', Order::class);

        return $this->page('boosts.order', compact('order', 'boost', 'canConfirm'));
    }

    public function confirm(Request $request, string $id, BoostOrders $orders): Response
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        try {
            $result = $orders->confirm($actor, $id, $request->only(['received_amount', 'transaction_reference', 'starts_at', 'ends_at']), $request);

            return $request->expectsJson() ? response()->json(['order_id' => $result['order']->id, 'boost_id' => $result['boost']->id, 'already_confirmed' => ! $result['created']])
                ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
                : redirect()->route('boost-orders.show', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->failure($request, $exception);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function page(string $view, array $data, int $status = 200): Response
    {
        return response()->view($view, $data, $status)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function failure(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() !== 503) {
            throw $exception;
        }
        $status = 503;
        $message = __('boosts.unavailable');
        $errors = [];
        if ($exception instanceof ValidationException) {
            $status = 422;
            $message = __('payments.invalid');
            $errors = $exception->errors();
        } elseif ($exception instanceof PaymentConfirmationRejected) {
            $status = 409;
            $message = __('payments.rejections.'.$exception->reason, array_map(fn ($amount) => number_format($amount, 0, ',', '.'), $exception->details));
        }

        return $request->expectsJson() ? response()->json(compact('message', 'errors'), $status)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')
            : $this->page('boosts.error', compact('message', 'errors'), $status);
    }
}
