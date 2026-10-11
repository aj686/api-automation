<?php

namespace App\Livewire;

use App\Models\Run;
use App\Models\RunRequest;
use App\Models\RunResult;
use App\Services\RunService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One run: summary, per-assertion rows and the redacted execution log
 * (plan section 9, master prompt sections 79–80). Everything shown was
 * redacted before it was stored (D-025).
 */
class RunShow extends Component
{
    use WithPagination;

    /**
     * Result filters (plan section 9). "Failed" means the API answered and an
     * assertion did not hold; "Errors" means no response arrived at all
     * (unreachable host, timeout), so nothing about the API was tested.
     *
     * @var array<string, string>
     */
    public const FILTERS = [
        'all' => 'All',
        'passed' => 'Passed',
        'failed' => 'Failed',
        'errors' => 'Errors',
    ];

    public const PER_PAGE = 100;

    /** Request cards carry payloads (up to 64 KB each), so fewer per page (ui-reviewer). */
    public const CARDS_PER_PAGE = 25;

    public Run $run;

    #[Url(as: 'show', except: 'all')]
    public string $resultFilter = 'all';

    public function mount(Run $run): void
    {
        $this->run = $run;
    }

    public function updatedResultFilter(): void
    {
        $this->resetPage();
    }

    public function cancelRun(RunService $runs): void
    {
        $runs->cancel($this->run);
    }

    /**
     * @param  Builder<RunResult>  $query
     * @return Builder<RunResult>
     */
    private function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'passed' => $query->where('passed', true),
            'failed' => $query->where('passed', false)->whereNotNull('response_code'),
            'errors' => $query->where('passed', false)->whereNull('response_code'),
            default => $query,
        };
    }

    /**
     * The same filters, per request card (D-037): "Errors" = the request itself
     * failed (unreachable, timed out, script error); "Failed" = it answered and
     * an assertion did not hold.
     *
     * @param  Builder<RunRequest>  $query
     * @return Builder<RunRequest>
     */
    private function applyRequestFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'passed' => $query->whereNull('error_message')->whereDoesntHave('results', fn ($r) => $r->where('passed', false)),
            'failed' => $query->whereNull('error_message')->whereHas('results', fn ($r) => $r->where('passed', false)),
            'errors' => $query->whereNotNull('error_message'),
            default => $query,
        };
    }

    public function render(): View
    {
        $filter = array_key_exists($this->resultFilter, self::FILTERS) ? $this->resultFilter : 'all';

        // Runs stored before payloads existed have no request cards: keep the table for them.
        $hasRequests = $this->run->requests()->exists();
        $filtered = fn (string $key) => $hasRequests
            ? $this->applyRequestFilter($this->run->requests()->getQuery(), $key)
            : $this->applyFilter($this->run->results()->getQuery(), $key);

        $counts = [];
        foreach (array_keys(self::FILTERS) as $key) {
            $counts[$key] = $filtered($key)->count();
        }

        return view('livewire.run-show', [
            'hasRequests' => $hasRequests,
            'requests' => $hasRequests ? $filtered($filter)->with('results')->paginate(self::CARDS_PER_PAGE) : null,
            'results' => $hasRequests ? null : $filtered($filter)->paginate(self::PER_PAGE),
            'counts' => $counts,
            'filter' => $filter,
        ])->title('Run · '.$this->run->collection_name);
    }
}
