<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordAudit
{
    public function record(User $actor, string $action, string $id, int $companyId, Request $request, array $metadata = []): void
    {
        $status = ['new', 'viewed', 'interview', 'hired', 'rejected'];
        if (! in_array($action, ['application.viewed_detail', 'application.status_changed', 'application.note_updated'], true)
            || ($action === 'application.status_changed'
                ? (array_keys($metadata) !== ['old', 'new'] || ! in_array($metadata['old'], $status, true) || ! in_array($metadata['new'], $status, true))
                : $metadata !== [])) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $current = User::query()->findOrFail($actor->id, ['id', 'role']);
        DB::table('audit_logs')->insert([
            'actor_id' => $current->id, 'actor_role' => $current->role->value, 'action' => $action,
            'subject_type' => 'application', 'subject_id' => $id, 'company_id' => $companyId,
            'ip' => $request->ip(),
            // Raw user agents can contain attacker supplied contact data: retain only a coarse family.
            'user_agent' => preg_match('/Firefox|Edg|Chrome|Safari/', $request->userAgent() ?? '', $match) ? $match[0] : 'Other',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
