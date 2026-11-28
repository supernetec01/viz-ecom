<?php

use App\Exceptions\PaymentConfirmationRejected;
use App\Models\User;
use App\Services\BoostOrders;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$application = require __DIR__.'/../../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
if (! $application->environment('testing') || DB::connection()->getDatabaseName() !== 'viz_ecom_test') {
    exit(2);
}
try {
    [, $actorId, $id, $payloadPath, $tag, $barrier, $stage, $action] = $argv;
    Carbon::setTestNow(CarbonImmutable::parse(getenv('T40_TEST_NOW')));
    CarbonImmutable::setTestNow(CarbonImmutable::parse(getenv('T40_TEST_NOW')));
    DB::select("select set_config('application_name', ?, false)", [$tag]);
    $held = false;
    DB::listen(function ($query) use (&$held, $barrier, $stage): void {
        if (! $held && (($stage === 'job' && str_contains($query->sql, 'from "jobs"') && str_contains($query->sql, 'for update')) || ($stage === 'bank' && str_starts_with($query->sql, 'update "orders"')))) {
            $held = true;
            file_put_contents($barrier.'/locked', '1');
            $deadline = microtime(true) + 12;
            while (! file_exists($barrier.'/release') && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! file_exists($barrier.'/release')) {
                throw new RuntimeException('Test barrier timed out.');
            }
        }
    });
    $actor = User::findOrFail($actorId);
    $input = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
    $request = Request::create('/test-lifecycle', 'POST');
    if ($action === 'request') {
        $order = app(BoostOrders::class)->request($actor, $id, $input);
        echo json_encode(['order_id' => $order->id]);
    } else {
        $result = app(BoostOrders::class)->confirm($actor, $id, $input, $request);
        echo json_encode(['boost_id' => $result['boost']->id, 'created' => $result['created']]);
    }
} catch (PaymentConfirmationRejected $exception) {
    echo json_encode(['rejected' => $exception->reason]);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    echo json_encode(['error' => get_class($exception)]);
    exit(1);
}
