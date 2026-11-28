<?php

use App\Exceptions\PaymentConfirmationRejected;
use App\Models\User;
use App\Services\ConfirmAccessOrder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// CLI-only test worker: never available as a production entry point.
require __DIR__.'/../../vendor/autoload.php';
$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('testing') || DB::connection()->getDatabaseName() !== 'viz_ecom_test') {
    exit(2);
}
try {
    [, $actorId, $orderId, $payloadPath, $tag, $barrier, $stage] = $argv;
    CarbonImmutable::setTestNow(CarbonImmutable::parse(getenv('T38_TEST_NOW')));
    DB::select("select set_config('application_name', ?, false)", [$tag]);
    $held = false;
    DB::listen(function ($query) use (&$held, $barrier, $stage): void {
        $match = $stage === 'job' ? str_contains($query->sql, 'from "jobs"') && str_contains($query->sql, 'for update')
            : $stage === 'bank' && str_starts_with($query->sql, 'update "orders"');
        if (! $held && $match) {
            $held = true;
            file_put_contents($barrier.'/locked', '1');
            $deadline = microtime(true) + 12;
            while (! file_exists($barrier.'/release') && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! file_exists($barrier.'/release')) {
                throw new RuntimeException('Test lock barrier timed out.');
            }
        }
    });
    $request = Request::create('/test-confirm', 'POST');
    $result = app(ConfirmAccessOrder::class)->confirm(User::findOrFail($actorId), $orderId,
        json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR), $request);
    echo json_encode(['order_id' => $result['order']->id, 'grant_id' => $result['grant']->id, 'created' => $result['created']]);
} catch (PaymentConfirmationRejected $exception) {
    echo json_encode(['rejected' => $exception->reason]);
} catch (Throwable $exception) {
    echo json_encode(['error' => get_class($exception)]);
    exit(1);
}
