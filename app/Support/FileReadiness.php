<?php

namespace App\Support;

use App\Models\CandidateFile;

final class FileReadiness
{
    /** Readiness only; T30/T33 must additionally authorize the application on every access. */
    public static function for(CandidateFile $file): array
    {
        $clean = $file->scan_status === 'clean';

        return ['can_download' => $clean, 'can_view' => $clean && $file->preview_status === 'ready',
            'message' => ! $clean ? __('files.scan_'.$file->scan_status)
                : ($file->preview_status === 'failed' ? __('files.no_preview') : __('files.preview_'.$file->preview_status))];
    }
}
