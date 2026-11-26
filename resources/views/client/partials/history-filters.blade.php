<form class="history-filters" role="search" aria-label="{{ $searchLabel }}" method="get" action="{{ $action }}">
    <label class="history-search">Search
        <span class="history-search-field">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control" type="search" name="q" value="{{ $filters->search }}" placeholder="{{ $searchPlaceholder }}">
        </span>
    </label>
    <label>Status
        <select class="form-select" name="status">
            <option value="">All statuses</option>
            @foreach ($filters->statuses as $choice)
                <option value="{{ $choice }}" @selected($choice === $filters->status)>{{ $choice }}</option>
            @endforeach
        </select>
    </label>
    <div class="history-filter-actions">
        <button class="shop-action" type="submit">Apply filters</button>
        <a class="account-text-link" href="{{ $action }}">Clear filters</a>
    </div>
</form>
