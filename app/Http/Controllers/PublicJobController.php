<?php

namespace App\Http\Controllers;

use App\Models\JobCategory;
use App\Models\Location;
use App\Services\ListPublicJobs;
use App\Support\PublicJobFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicJobController extends Controller
{
    public function __invoke(Request $request, ListPublicJobs $list): View|Response
    {
        $validator = PublicJobFilters::validator($request->query());
        if ($validator->fails()) {
            return response()->view('public.jobs.invalid-filters', ['messages' => $validator->errors()->all()], 422);
        }
        $filters = PublicJobFilters::normalized($validator->validated());

        return view('public.jobs.index', [
            'jobs' => $list->paginate($filters), 'filters' => $filters,
            'locations' => Location::whereNull('parent_id')->orderBy('name')->pluck('name', 'id'),
            'categories' => JobCategory::orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
