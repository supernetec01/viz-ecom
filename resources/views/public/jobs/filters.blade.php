<form class="job-filters" action="{{ route('jobs.index') }}" method="get" aria-label="{{ __('public.filters') }}">
    <div class="filter-grid">
        <label class="search-field">{{ __('public.search') }}
            <input type="search" name="q" maxlength="200" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('public.search_placeholder') }}">
        </label>
        <label>{{ __('ui.jobs.location') }}
            <select name="dia_diem"><option value="">{{ __('public.all_locations') }}</option>
                @foreach ($locations as $id => $name)<option value="{{ $id }}" @selected((string)($filters['dia_diem'] ?? '') === (string)$id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('ui.jobs.category') }}
            <select name="vi_tri"><option value="">{{ __('public.all_categories') }}</option>
                @foreach ($categories as $id => $name)<option value="{{ $id }}" @selected((string)($filters['vi_tri'] ?? '') === (string)$id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('public.filter_salary') }}
            <select name="luong_tu"><option value="">{{ __('public.all_salaries') }}</option>
                @foreach (\App\Support\PublicJobFilters::SALARIES as $amount)<option value="{{ $amount }}" @selected((string)($filters['luong_tu'] ?? '') === (string)$amount)>{{ __('public.salary_from', ['amount' => number_format($amount, 0, ',', '.')]) }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('public.filter_experience') }}
            <select name="kinh_nghiem"><option value="">{{ __('public.all_experience') }}</option>
                @foreach (\App\Support\PublicJobFilters::EXPERIENCE as $years)<option value="{{ $years }}" @selected((string)($filters['kinh_nghiem'] ?? '') === (string)$years)>{{ $years ? __('public.experience_up_to', ['years' => $years]) : __('public.no_experience') }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('ui.companies.type') }}
            <select name="loai_cong_ty"><option value="">{{ __('public.all_company_types') }}</option>
                @foreach (__('ui.companies.types') as $value => $label)<option value="{{ $value }}" @selected(($filters['loai_cong_ty'] ?? '') === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>
    <p class="filter-help">{{ __('public.filter_salary_help') }}</p>
    <div class="filter-actions"><button class="button button-primary" type="submit">{{ __('public.apply_filters') }}</button><a class="button button-outline" href="{{ route('jobs.index') }}">{{ __('public.clear_filters') }}</a></div>
</form>
