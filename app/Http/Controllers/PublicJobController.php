<?php

namespace App\Http\Controllers;

use App\Services\ListPublicJobs;
use Illuminate\Contracts\View\View;

class PublicJobController extends Controller
{
    public function __invoke(ListPublicJobs $list): View
    {
        return view('public.jobs.index', ['jobs' => $list->paginate()]);
    }
}
