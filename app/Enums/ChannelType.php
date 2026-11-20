<?php

namespace App\Enums;

enum ChannelType: string
{
    case Shopee = 'shopee';
    case TikTok = 'tiktok';
    case Lazada = 'lazada';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Website = 'website';
}
