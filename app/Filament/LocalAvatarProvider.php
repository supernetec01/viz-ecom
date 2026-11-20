<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initial = mb_strtoupper(mb_substr((string) $record->getAttribute('name'), 0, 1));
        $initial = htmlspecialchars($initial ?: '?', ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#0f766e"/><text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="white" font-family="sans-serif" font-size="20">'.$initial.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
