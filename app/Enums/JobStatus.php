<?php

namespace App\Enums;

enum JobStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Paused = 'paused';
    case Expired = 'expired';
    case Closed = 'closed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Pending, self::Published],
            self::Pending => [self::Draft, self::Published],
            self::Published => [self::Paused, self::Expired, self::Closed],
            self::Paused => [self::Published, self::Closed, self::Expired, self::Draft],
            self::Expired => [self::Draft],
            self::Closed => [],
        }, true);
    }
}
