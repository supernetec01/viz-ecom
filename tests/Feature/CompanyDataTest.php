<?php

use App\Enums\CompanyType;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects unknown hosts unsafe schemes and misleading domain suffixes', function (string $url) {
    Company::create(['name' => 'Test'])->channels()->create(['type' => 'shopee', 'display_name' => 'Test', 'url' => $url]);
})->with(['https://evil.test/a', 'javascript:alert(1)', 'ftp://shopee.vn/a', 'https://shopee.vn.evil.test/a', 'https://evil.test@shopee.vn/a'])->throws(ValidationException::class);

it('accepts the approved channel domains including subdomains', function (string $type, string $url) {
    $company = Company::create(['name' => 'Test', 'type' => CompanyType::Agency]);
    $channel = $company->channels()->create(['type' => $type, 'display_name' => 'Test', 'url' => $url]);
    expect($channel->fresh()->company_id)->toBe($company->id);
})->with([
    ['shopee', 'https://shopee.vn/shop'], ['tiktok', 'https://www.tiktok.com/@shop'],
    ['lazada', 'https://www.lazada.vn/shop'], ['youtube', 'https://youtu.be/video'],
    ['facebook', 'https://www.facebook.com/shop'],
]);

it('requires explicit approval for a company website domain', function () {
    config(['company-channels.website' => ['company.example']]);
    $company = Company::create(['name' => 'Test']);
    $company->channels()->create(['type' => 'website', 'display_name' => 'Website', 'url' => 'https://company.example/about']);
    expect($company->channels()->count())->toBe(1);
    $company->channels()->create(['type' => 'website', 'display_name' => 'Website', 'url' => 'https://evil.example']);
})->throws(ValidationException::class);

it('enforces direct or agency at the model boundary', function () {
    Company::create(['name' => 'Test', 'type' => 'other']);
})->throws(ValueError::class);

it('enforces company types at the database boundary', function () {
    DB::table('companies')->insert(['name' => 'Test', 'type' => 'other']);
})->throws(QueryException::class);
