<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordAudit
{
    public function record(User $actor, string $action, string $id, int $companyId, Request $request, array $metadata = []): void
    {
        $status = ['new', 'viewed', 'interview', 'hired', 'rejected'];
        if (! in_array($action, ['application.viewed_detail', 'application.status_changed', 'application.note_updated', 'application.file_viewed', 'application.file_downloaded'], true)
            || ($action === 'application.status_changed'
                ? (array_keys($metadata) !== ['old', 'new'] || ! in_array($metadata['old'], $status, true) || ! in_array($metadata['new'], $status, true))
                : (in_array($action, ['application.file_viewed', 'application.file_downloaded'], true)
                    ? (array_keys($metadata) !== ['file_id'] || ! is_int($metadata['file_id']) || $metadata['file_id'] < 1)
                    : $metadata !== []))) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, $action, 'application', $id, $companyId, $request, $metadata);
    }

    public function login(User $actor, Request $request): void
    {
        $this->append($actor, 'auth.login', 'user', (string) $actor->id, null, $request, []);
    }

    public function processingRetry(User $actor, int $fileId, string $stage, Request $request): void
    {
        abort_unless(User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists(), 403);
        if ($fileId < 1 || ! in_array($stage, ['scan', 'preview'], true)) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, 'file.processing_retry', 'file', (string) $fileId, null, $request, ['stage' => $stage]);
    }

    public function blocked(User $actor, string $id, int $companyId, string $bucket, int $limit, Request $request): void
    {
        if (! in_array($bucket, ['file', 'details'], true) || $limit < 1) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $this->append($actor, 'application.access_blocked', 'application', $id, $companyId, $request,
            ['reason' => 'rate_limit', 'bucket' => $bucket, 'limit' => $limit]);
    }

    /** Hook for T38/T39: call inside their mutation transaction, with a stable operation ULID. */
    public function grantChange(User $actor, string $action, int $grantId, string $operation, Request $request): void
    {
        abort_unless(User::query()->whereKey($actor->id)->where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->exists(), 403);
        if (! in_array($action, ['access_grant.issued', 'access_grant.revoked'], true) || ! Str::isUlid($operation)) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        DB::transaction(function () use ($actor, $action, $grantId, $operation, $request) {
            $grant = AccessGrant::query()->whereKey($grantId)->lockForUpdate()->first();
            abort_unless($grant !== null && $grant->status === ($action === 'access_grant.issued' ? 'active' : 'revoked'), 403);
            $key = $action.':'.$operation;
            $previous = DB::table('audit_logs')->where('operation_key', $key)->first(['actor_id', 'subject_id']);
            if ($previous) {
                if ($previous->actor_id !== $actor->id || $previous->subject_id !== (string) $grantId) {
                    throw new InvalidArgumentException('Conflicting audit operation.');
                }

                return;
            }
            $this->append($actor, $action, 'access_grant', (string) $grantId, $grant->company_id, $request,
                ['job_id' => $grant->job_id, 'order_id' => $grant->order_id], $key);
        });
    }

    private function append(User $actor, string $action, string $type, string $id, ?int $companyId, Request $request, array $metadata, ?string $operation = null): void
    {
        $current = User::query()->whereKey($actor->id)->where('status', UserStatus::Active->value)->firstOrFail(['id', 'role']);
        DB::table('audit_logs')->insert([
            'actor_id' => $current->id, 'actor_role' => $current->role->value, 'action' => $action,
            'subject_type' => $type, 'subject_id' => $id, 'company_id' => $companyId, 'operation_key' => $operation,
            'ip' => $request->ip(),
            // Raw user agents can contain attacker supplied contact data: retain only a coarse family.
            'user_agent' => preg_match('/Firefox|Edg|Chrome|Safari/', $request->userAgent() ?? '', $match) ? $match[0] : 'Other',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
