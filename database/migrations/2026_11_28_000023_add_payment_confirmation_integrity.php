<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->string('reason_code', 80)->nullable());
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('duration_days')->nullable();
            // Snapshot permits configurable upper limits enforced by a row CHECK.
            $table->unsignedInteger('duration_max_days')->nullable();
            $table->string('payment_bank_key', 64)->nullable();
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_duration_check CHECK ((duration_days IS NULL AND duration_max_days IS NULL) OR (duration_days IS NOT NULL AND duration_max_days IS NOT NULL AND duration_days > 0 AND duration_days <= duration_max_days))');
        // Versioned migration normalization: no app service dependency or guessed bank data.
        $normalize = fn (string $value): string => preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', mb_strtoupper($value, 'UTF-8')) ?? '';
        DB::table('orders')->where('status', 'paid')->where('method', 'bank_transfer')
            ->whereNotNull('payment_instructions')->whereNotNull('transaction_reference')->orderBy('id')->chunkById(100, function ($rows) use ($normalize): void {
                foreach ($rows as $row) {
                    $bank = json_decode($row->payment_instructions, true);
                    if (! is_array($bank) || ! is_string($bank['bank'] ?? null) || ! is_string($bank['account'] ?? null)
                        || $normalize($bank['bank']) === '' || $normalize($bank['account']) === '' || $normalize($row->transaction_reference) === '') {
                        continue;
                    }
                    $key = hash('sha256', json_encode([$normalize($bank['bank']), $normalize($bank['account'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    DB::table('orders')->where('id', $row->id)->update(['payment_bank_key' => $key]);
                }
            });
        // Explicit Unicode whitespace set matches the PHP normalizer, independent of locale.
        $reference = "upper(regexp_replace(transaction_reference, U&'[\\0009-\\000D\\0020\\0085\\00A0\\1680\\2000-\\200B\\2028\\2029\\202F\\205F\\3000\\FEFF]+', '', 'g'))";
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_key_check CHECK (payment_bank_key IS NULL OR (payment_bank_key ~ '^[0-9a-f]{64}$' AND transaction_reference IS NOT NULL AND length($reference) > 0))");
        try {
            DB::statement("CREATE UNIQUE INDEX orders_paid_bank_transaction_unique ON orders (payment_bank_key, ($reference)) WHERE status = 'paid' AND method = 'bank_transfer' AND payment_bank_key IS NOT NULL");
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new RuntimeException('Historical paid bank transactions overlap; reconcile them before deploying T38.');
            }
            throw $exception;
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('duration_days')->orWhereNotNull('payment_bank_key')->exists() || DB::table('audit_logs')->whereNotNull('reason_code')->exists()) {
            throw new RuntimeException('Preserve duration and bank reconciliation data before rolling back T38.');
        }
        DB::statement('DROP INDEX orders_paid_bank_transaction_unique');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_duration_check, DROP CONSTRAINT orders_payment_key_check');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['duration_days', 'duration_max_days', 'payment_bank_key']));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('reason_code'));
    }
};
