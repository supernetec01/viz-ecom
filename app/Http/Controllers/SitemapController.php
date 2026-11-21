<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Job;
use App\Support\PublicSeo;
use Illuminate\Http\Response;
use XMLWriter;

class SitemapController
{
    public const PER_PAGE = 1000;

    public function index(): Response
    {
        $entries = [['loc' => PublicSeo::url('sitemap.pages')]];
        foreach (['jobs', 'companies'] as $type) {
            $query = $type === 'jobs' ? Job::publiclyVisible() : Company::whereNotNull('verified_at')->whereNotNull('verified_by');
            for ($page = 1, $last = max(1, (int) ceil($query->count() / self::PER_PAGE)); $page <= $last; $page++) {
                $entries[] = ['loc' => PublicSeo::url('sitemap.entries', ['type' => $type, 'page' => $page])];
            }
        }

        return $this->xml($entries, true);
    }

    public function pages(): Response
    {
        return $this->xml(array_map(fn (string $route): array => ['loc' => PublicSeo::url($route)], ['home', 'jobs.index']));
    }

    public function entries(string $type, string $page): Response
    {
        abort_if(strlen($page) > 9, 404);
        $query = $type === 'jobs' ? Job::publiclyVisible()->select(['id', 'slug', 'updated_at']) : Company::whereNotNull('verified_at')->whereNotNull('verified_by')->select(['id', 'updated_at']);
        $records = $query->orderBy('id')->paginate(self::PER_PAGE, page: (int) $page);
        abort_if((int) $page > $records->lastPage(), 404);
        $entries = $records->map(fn ($record): array => [
            'loc' => PublicSeo::url($type === 'jobs' ? 'jobs.show' : 'companies.show', $type === 'jobs' ? ['slug' => $record->slug, 'id' => $record->id] : ['id' => $record->id]),
            'lastmod' => $record->updated_at?->toIso8601String(),
        ])->all();

        return $this->xml($entries);
    }

    private function xml(array $entries, bool $index = false): Response
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, $index ? 'sitemapindex' : 'urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($entries as $entry) {
            $xml->startElement($index ? 'sitemap' : 'url');
            $xml->writeElement('loc', $entry['loc']);
            if (! empty($entry['lastmod'])) {
                $xml->writeElement('lastmod', $entry['lastmod']);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
