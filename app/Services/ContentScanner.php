<?php

namespace App\Services;

use App\Support\ContentScanResult;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Normalizer;

final class ContentScanner
{
    public const FIELDS = ['title', 'description', 'requirements', 'benefits', 'ward_name', 'work_address'];

    public function __construct(private readonly string $siteUrl) {}

    /** @param array<string, string> $content */
    public function scan(array $content): ContentScanResult
    {
        $findings = [];
        foreach (self::FIELDS as $field) {
            [$text, $links] = $this->readHtml($content[$field] ?? '');
            $normal = Str::lower(Str::ascii($this->normalize($text.' '.implode(' ', $links))));
            if ($this->hasPhone($normal)) {
                $findings[] = ['field' => $field, 'rule' => 'phone'];
            }
            if ($this->hasEmail($normal)) {
                $findings[] = ['field' => $field, 'rule' => 'email'];
            }
            foreach (['zalo' => 'z[\W_]*a[\W_]*l[\W_]*[o0]', 'inbox' => 'i[\W_]*n[\W_]*b[\W_]*[o0][\W_]*x'] as $rule => $pattern) {
                if (preg_match('/(?<![a-z])'.$pattern.'(?![a-z])/u', $normal)) {
                    $findings[] = ['field' => $field, 'rule' => $rule];
                }
            }
            foreach (array_unique($links) as $link) {
                if (! $this->isInternal($link)) {
                    // Persist only the offending URL for the reviewer, never phone/email matches or excerpts.
                    $findings[] = ['field' => $field, 'rule' => 'external_link', 'value' => $link];
                }
            }
        }

        return new ContentScanResult($findings);
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\p{Cf}/u', '', Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text) ?? $text;
    }

    /** @return array{string, list<string>} */
    private function readHtml(string $html): array
    {
        if ($html === '') {
            return ['', []];
        }
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $text = '';
        foreach ((new DOMXPath($document))->query('//body//text()') ?: [] as $node) {
            $text .= $node->textContent.' ';
        }
        $links = [];
        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attribute) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') {
                    $links[] = $value;
                }
            }
        }
        $visible = $this->normalize($text);
        $visible = preg_replace_callback('~(?:\b[a-z][a-z0-9+.-]*://|(?<!:)//|\bwww\.)[^\s<>"\x27]+~iu', function (array $match) use (&$links): string {
            $links[] = rtrim($match[0], '.,;:!?)]}');

            return ' ';
        }, $visible) ?? $visible;
        // Alphabetic TLDs distinguish domains from salaries and decimal distances.
        preg_match_all('~(?<![\pL\pN@._/\x{005C}-])(?:[\pL\pN](?:[\pL\pN-]*[\pL\pN])?\.)+[\pL][\pL\pN-]{1,62}(?:[/:][^\s<>"\x27]*)?~u', $visible, $matches);
        foreach ($matches[0] as $link) {
            $links[] = rtrim($link, '.,;:!?)]}');
        }

        return [$text, $links];
    }

    private function hasPhone(string $text): bool
    {
        $digits = ['khong' => '0', 'mot' => '1', 'hai' => '2', 'ba' => '3', 'bon' => '4', 'tu' => '4', 'nam' => '5', 'sau' => '6', 'bay' => '7', 'tam' => '8', 'chin' => '9'];
        $text = preg_replace_callback('/(?<![a-z])(?:'.implode('|', array_keys($digits)).')(?![a-z])/u', fn (array $match): string => $digits[$match[0]], $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:[01]?\d|2[0-3])h[0-5]\d(?!\d)/', ' ', $text) ?? $text;
        $text = preg_replace('/(?:\+84|0084)\s*\(0\)/', '+84', $text) ?? $text;
        // Remove short letter inserts only when they bridge two digits, not ordinary prose.
        $text = preg_replace('/(?<=\d)[\s.\-]*[a-z]{1,3}[\s.\-]*(?=\d)/', '', $text) ?? $text;
        $separator = '[\s.()\-/]{0,5}';
        $country = '(?:\+?8'.$separator.'4|0'.$separator.'0'.$separator.'8'.$separator.'4)';
        $prefix = '(?:0'.$separator.'[35789]|'.$country.$separator.'[35789])';
        $mobile = $prefix.'(?:'.$separator.'\d){8}';
        $landline = '(?:0'.$separator.'2|'.$country.$separator.'2)(?:'.$separator.'\d){9}';

        return (bool) preg_match('~(?<!\d)(?:'.$mobile.'|'.$landline.')(?![\s.()\-/]*\d)~u', $text);
    }

    private function hasEmail(string $text): bool
    {
        $text = preg_replace('/(?:\[\s*at\s*\]|\(\s*at\s*\)|(?<![a-z])(?:at|a\s*cong)(?![a-z]))/', '@', $text) ?? $text;
        $text = preg_replace('/(?:\[\s*dot\s*\]|\(\s*dot\s*\)|(?<![a-z])(?:dot|cham)(?![a-z]))/', '.', $text) ?? $text;

        return (bool) preg_match('/[a-z0-9._%+\-]+\s*@\s*[a-z0-9\-]+(?:\s*\.\s*[a-z0-9\-]+)*\s*\.\s*[a-z]{2,63}\b/i', $text);
    }

    private function isInternal(string $link): bool
    {
        // Browsers treat backslashes as slashes in HTTP URLs; match their destination semantics.
        $url = str_replace('\\', '/', trim($this->normalize($link)));
        if (preg_match('~^(?:[?#]|/(?!/))~', $url)) {
            return true;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (! preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            if (! preg_match('/^[\pL\pN-]+(?:\.[\pL\pN-]+)+/u', $url)) {
                return true; // Relative in-site paths have no hostname.
            }
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = $this->host($parts['host']);
        $internalHost = $this->host(parse_url($this->siteUrl, PHP_URL_HOST) ?: '');

        return $host !== '' && $internalHost !== '' && $host === $internalHost;
    }

    private function host(string $host): string
    {
        $host = mb_strtolower(rtrim($host, '.'));

        return $host === '' ? '' : (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '');
    }
}
