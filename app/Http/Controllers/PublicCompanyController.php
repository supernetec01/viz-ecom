<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Rules\AllowedChannelUrl;
use App\Services\ListPublicJobs;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class PublicCompanyController
{
    public function __invoke(string $id, ListPublicJobs $listing): View
    {
        abort_if(strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) > 0), 404);
        $company = Company::query()->whereNotNull('verified_at')->whereNotNull('verified_by')
            ->select(['id', 'name', 'type', 'address', 'introduction', 'industry', 'products'])
            ->with('channels:id,company_id,type,display_name,url')->findOrFail($id);
        // Revalidate legacy rows too; never fetch or embed an external URL.
        $channels = $company->channels->filter(fn ($channel) => Validator::make(
            ['url' => $channel->url], ['url' => ['required', 'url:http,https', new AllowedChannelUrl($channel->type->value)]],
        )->passes());

        return view('public.companies.show', ['company' => $company, 'channels' => $channels, 'jobs' => $listing->paginate(companyId: $company->id)]);
    }
}
