<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class JobHtml
{
    public static function clean(string $html): string
    {
        // The form/model limit is 50,000 characters; UTF-8 uses up to four bytes per character.
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['http', 'https'])->withMaxInputLength(200000);
        foreach (['p', 'br', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'ul', 'ol', 'li', 'blockquote'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href'])->forceAttribute('a', 'rel', 'noopener noreferrer');

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
