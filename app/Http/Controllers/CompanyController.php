<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    public function show(Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        return response()->json($company->only(['id', 'name', 'type', 'address']));
    }
}
