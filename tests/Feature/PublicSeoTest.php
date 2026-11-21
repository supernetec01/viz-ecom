<?php

use App\Enums\JobStatus;
use App\Support\PublicSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seoDocument(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    return new DOMXPath($dom);
}

it('does not publish JobPosting by default before the application journey is implemented', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk()->assertDontSee('application/ld+json', false);
});

it('never emits JobPosting or salary metadata for closed and no JobPosting for other nonreceiving states', function (JobStatus $status) {
    config(['seo.job_posting_enabled' => true]);
    $job = managedJob(jobManager());
    $job->update(['salary_min' => 12345678, 'salary_max' => 87654321, 'description' => 'PRIVATE_SEO_DESCRIPTION']);
    publishManagedJob($job)->transitionTo($status);
    $response = $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk()
        ->assertSee('name="robots" content="noindex, follow"', false)->assertDontSee('JobPosting');
    if ($status === JobStatus::Closed) {
        $response->assertDontSee('PRIVATE_SEO_DESCRIPTION')->assertDontSee('12.345.678')->assertDontSee('87.654.321');
    }
})->with([JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

it('writes canonical and Open Graph metadata with title salary workplace and an actual preview image', function () {
    config(['app.url' => 'https://jobs.example.test']);
    $job = managedJob(jobManager());
    $job->update(['title' => 'Vận hành <Shop> "A"', 'salary_min' => 12000000]);
    publishManagedJob($job);
    $response = $this->get('/viec-lam/'.$job->slug.'-'.$job->id.'?nguon=zalo')->assertOk();
    $xpath = seoDocument($response->getContent());
    $canonical = PublicSeo::url('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    expect($xpath->evaluate('string(//link[@rel="canonical"]/@href)'))->toBe($canonical);
    expect($xpath->evaluate('string(//meta[@property="og:url"]/@content)'))->toBe($canonical);
    expect($xpath->evaluate('string(//meta[@property="og:title"]/@content)'))->toContain($job->title);
    expect($xpath->evaluate('string(//meta[@property="og:description"]/@content)'))->toContain('12.000.000', $job->workplaceAddress());
    expect($xpath->evaluate('string(//meta[@property="og:image"]/@content)'))->toBe('https://jobs.example.test/images/social-card.png');
    $image = getimagesize(public_path('images/social-card.png'));
    expect([$image[0], $image[1], $image['mime']])->toBe([1200, 630, 'image/png']);
});

it('exports safe complete JobPosting JSON with a matching CSP nonce when explicitly enabled', function () {
    config(['seo.job_posting_enabled' => true]);
    $job = publishManagedJob(managedJob(jobManager()));
    DB::table('jobs')->where('id', $job->id)->update(['description' => '<p>Complete work</p><script>seoXss()</script>']);
    $response = $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk();
    $xpath = seoDocument($response->getContent());
    $json = $xpath->evaluate('string(//script[@type="application/ld+json"])');
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($data['@type'])->toBe('JobPosting');
    expect($data['title'])->toBe($job->title);
    expect($data['description'])->toContain('Complete work', 'Yêu cầu', 'Quyền lợi')->not->toContain('seoXss()', '<script');
    expect($data['employmentType'])->toBe('FULL_TIME');
    expect($data['hiringOrganization']['name'])->toBe($job->company->name);
    expect($data['jobLocation']['address'])->toMatchArray(['addressCountry' => 'VN', 'addressLocality' => $job->ward_name, 'streetAddress' => $job->work_address, 'addressRegion' => $job->location->name]);
    expect($data['datePosted'])->toBe($job->published_at->toIso8601String());
    expect($data['validThrough'])->toBe($job->expires_at->toIso8601String());
    expect($data)->not->toHaveKeys(['baseSalary', 'directApply', 'created_by', 'content_scan_reasons']);
    expect($json)->not->toContain('<p>', '</script>');
    $nonce = $xpath->evaluate('string(//script[@type="application/ld+json"]/@nonce)');
    expect($nonce)->not->toBe('');
    expect($response->headers->get('Content-Security-Policy'))->toContain("'nonce-{$nonce}'")->not->toContain("'unsafe-eval'", "script-src 'unsafe-inline'");
});

it('omits JobPosting for incomplete legacy workplace and at the exact expiry boundary', function () {
    config(['seo.job_posting_enabled' => true]);
    $this->freezeTime();
    $job = publishManagedJob(managedJob(jobManager()));
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null]);
    $url = route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    $this->get($url)->assertOk()->assertDontSee('JobPosting');
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => $job->ward_name, 'work_address' => $job->work_address, 'published_at' => now()->subDay(), 'expires_at' => now()]);
    $this->get($url)->assertOk()->assertDontSee('JobPosting')->assertSee('noindex, follow');
});

it('serves an XML sitemap index and only open job URLs refreshing after state and verification changes', function () {
    $this->freezeTime();
    $staff = jobManager();
    $open = publishManagedJob(managedJob($staff));
    foreach ([JobStatus::Draft, JobStatus::Pending, JobStatus::Paused, JobStatus::Expired, JobStatus::Closed] as $status) {
        $job = managedJob($staff);
        if ($status === JobStatus::Pending) {
            $job->update(['description' => 'Liên hệ Zalo', 'expires_at' => now()->addDays(7)]);
            $job->transitionTo($status);
        } elseif ($status !== JobStatus::Draft) {
            publishManagedJob($job)->transitionTo($status);
        }
    }
    foreach (['overdue', 'future', 'deleted', 'unverified', 'company_deleted'] as $reason) {
        $job = publishManagedJob(managedJob($staff));
        match ($reason) {
            'overdue' => DB::table('jobs')->where('id', $job->id)->update(['published_at' => now()->subDay(), 'expires_at' => now()]),
            'future' => DB::table('jobs')->where('id', $job->id)->update(['published_at' => now()->addDay()]),
            'deleted' => $job->delete(), 'unverified' => $job->company->forceFill(['verified_at' => null])->save(),
            'company_deleted' => $job->company->delete(),
        };
    }
    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('sitemapindex');
    $response = $this->get('/sitemaps/jobs/1.xml')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $xml = simplexml_load_string($response->getContent());
    expect((string) $xml->url->loc)->toBe(PublicSeo::url('jobs.show', ['slug' => $open->slug, 'id' => $open->id]));
    expect($xml->url->count())->toBe(1);
    $open->transitionTo(JobStatus::Paused);
    expect(simplexml_load_string($this->get('/sitemaps/jobs/1.xml')->getContent())->url->count())->toBe(0);
    $this->get('/sitemaps/jobs/2.xml')->assertNotFound();
    $this->get('/sitemaps/jobs/999999999999999999.xml')->assertNotFound();
    $this->get('/sitemaps/private/1.xml')->assertNotFound();
});

it('includes only verified company URLs and static public pages and advertises the sitemap in robots', function () {
    $job = managedJob(jobManager());
    $companyUrl = PublicSeo::url('companies.show', ['id' => $job->company_id]);
    $this->get('/sitemaps/companies/1.xml')->assertOk()->assertSee($companyUrl);
    $job->company->forceFill(['verified_by' => null])->save();
    $this->get('/sitemaps/companies/1.xml')->assertOk()->assertDontSee($companyUrl);
    $this->get('/sitemaps/pages.xml')->assertOk()->assertSee(PublicSeo::url('home'))->assertSee(PublicSeo::url('jobs.index'))->assertDontSee('/login');
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.PublicSeo::url('sitemap.index'));
    $this->get('/')->assertOk()->assertSee('og:image');
});

it('splits large job sitemaps without duplicate URLs and preserves real modification dates', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $row = $job->getAttributes();
    unset($row['id']);
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = array_replace($row, ['slug' => 'sitemap-job-'.$i]);
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('jobs')->insert($chunk);
    }
    $this->get('/sitemap.xml')->assertOk()->assertSee(PublicSeo::url('sitemap.entries', ['type' => 'jobs', 'page' => 2]));
    $one = simplexml_load_string($this->get('/sitemaps/jobs/1.xml')->getContent());
    $two = simplexml_load_string($this->get('/sitemaps/jobs/2.xml')->getContent());
    expect($one->url->count())->toBe(1000);
    expect($two->url->count())->toBe(1);
    expect((string) $one->url->lastmod)->toBe($job->updated_at->toIso8601String());
    $urls = array_map(fn ($entry) => (string) $entry->loc, iterator_to_array($one->url, false));
    expect($urls)->not->toContain((string) $two->url->loc);
});

it('preserves validated search and pagination in canonical URLs but drops tracking parameters', function () {
    $response = $this->get('/viec-lam?q=ke+toan&page=2&nguon=zalo')->assertOk();
    $xpath = seoDocument($response->getContent());
    expect($xpath->evaluate('string(//link[@rel="canonical"]/@href)'))->toBe(PublicSeo::url('jobs.index', ['q' => 'ke toan', 'page' => 2]));
    $job = managedJob(jobManager());
    $response = $this->get('/cong-ty/'.$job->company_id.'?page=2&nguon=zalo')->assertOk();
    expect(seoDocument($response->getContent())->evaluate('string(//link[@rel="canonical"]/@href)'))->toBe(PublicSeo::url('companies.show', ['id' => $job->company_id, 'page' => 2]));
});
