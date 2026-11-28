<?php

namespace App\Support;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class GrantDuration
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** @return array{min: int, max: int} */
    public function bounds(): array
    {
        $min = filter_var(config('access-payments.min_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $max = filter_var(config('access-payments.max_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        abort_if($min === false || $max === false || $max < $min, 503, __('payments.unavailable'));

        return ['min' => $min, 'max' => $max];
    }

    public function suggested(Order $order, CarbonImmutable $at): ?CarbonImmutable
    {
        return $order->duration_days === null ? null : $at->setTimezone(self::TIMEZONE)->addDays($order->duration_days)->endOfDay()->setMicrosecond(0)->utc();
    }

    public function parse(string $value): CarbonImmutable
    {
        // Browsers may omit a zero seconds component in datetime-local values.
        $format = strlen($value) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s';
        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value, self::TIMEZONE);
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->format($format) !== $value) {
            throw ValidationException::withMessages(['ends_at' => __('payments.invalid_end')]);
        }

        return $date->utc();
    }

    public function validateEnd(CarbonImmutable $end, CarbonImmutable $start): void
    {
        $bounds = $this->bounds();
        $min = $start->addDays($bounds['min']);
        // The last calendar day includes 23:59:59 Vietnam time, as the default does.
        $max = $start->setTimezone(self::TIMEZONE)->addDays($bounds['max'])->endOfDay()->setMicrosecond(0)->utc();
        if ($end->lt($min) || $end->gt($max)) {
            throw ValidationException::withMessages(['ends_at' => __('payments.end_outside_bounds', $bounds)]);
        }
    }

    public function signDefault(int $actorId, int $orderId, string $value): string
    {
        return hash_hmac('sha256', $actorId.'|'.$orderId.'|'.$value, (string) config('app.key'));
    }

    /** @return array{end: CarbonImmutable, default: ?CarbonImmutable, overridden: bool} */
    public function select(Order $order, int $actorId, array $input, CarbonImmutable $at): array
    {
        $current = $this->suggested($order, $at);
        $displayed = $current;
        if (isset($input['default_ends_at']) || isset($input['default_signature'])) {
            $raw = $input['default_ends_at'] ?? '';
            if (! is_string($raw) || ! is_string($input['default_signature'] ?? null)
                || ! hash_equals($this->signDefault($actorId, $order->id, $raw), $input['default_signature'])) {
                throw ValidationException::withMessages(['ends_at' => __('payments.refresh_form')]);
            }
            $displayed = $raw === '' ? null : $this->parse($raw);
        }
        $end = $this->parse($input['ends_at']);
        $overridden = $displayed !== null && ! $end->equalTo($displayed);
        // An untouched form submitted across midnight uses confirmation time, not render time.
        if (! $overridden && $current !== null) {
            $end = $current;
        }
        $this->validateEnd($end, $at);

        return ['end' => $end, 'default' => $displayed, 'overridden' => $overridden];
    }
}
