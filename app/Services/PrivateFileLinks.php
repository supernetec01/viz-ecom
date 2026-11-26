<?php

namespace App\Services;

use App\Models\User;
use App\Policies\ApplicationPolicy;
use Illuminate\Support\Facades\URL;

class PrivateFileLinks
{
    public function temporary(User $actor, string $id, int $file, string $mode): string
    {
        abort_unless(in_array($mode, ['inline', 'download'], true), 403);
        app(ApplicationPolicy::class)->fileRecord($actor, $id, $file, $mode === 'inline');

        return URL::temporarySignedRoute('applications.file', now()->addMinutes(5), [
            'id' => $id, 'file' => $file, 'mode' => $mode, 'actor' => $actor->id,
        ]);
    }
}
