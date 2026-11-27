<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessPaymentUnavailable;
use App\Models\User;
use App\Services\RequestApplicationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccessOrderController
{
    public function store(Request $request, string $job, RequestApplicationAccess $access): RedirectResponse|Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $order = $access->request($actor, $job);
        } catch (AccessPaymentUnavailable $exception) {
            return response()->view('orders.unavailable', ['message' => __($exception->legacyOrder ? 'orders.legacy_pending' : 'orders.unconfigured')], 503)
                ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $order ? redirect()->route('access-orders.show', ['id' => $order->id])
            : redirect('/app/mo-ho-so')->with('status', __('orders.already_unlocked'));
    }

    public function show(Request $request, string $id, RequestApplicationAccess $access): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $order = $access->pending($actor, $id);

        return response()->view('orders.pending', compact('order'))->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
