<?php

namespace App\Enums;

enum JobStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Expired = 'expired';
    case Closed = 'closed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Draft, self::Published],
            self::Published => [self::Expired, self::Closed],
            self::Expired, self::Closed => [self::Draft],
        }, true);
    }
}
