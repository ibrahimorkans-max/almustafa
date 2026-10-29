{{-- Beside the top-bar search: opens every result on its own page, for what is typed in the box. --}}
<a href="{{ \App\Filament\Pages\SearchResultsPage::getUrl() }}" class="hidden text-xs font-medium text-gray-500 hover:text-primary-600 sm:inline"
   title="All results on one page"
   onclick="var i = document.querySelector('.fi-global-search-field input'); if (i && i.value.trim() !== '') { this.href = this.href.split('?')[0] + '?q=' + encodeURIComponent(i.value.trim()); }">
    All results
</a>
