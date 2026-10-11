<?php

namespace App\Livewire;

use App\Models\Run;
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

    public function render(): View
    {
        $filter = array_key_exists($this->resultFilter, self::FILTERS) ? $this->resultFilter : 'all';

        $counts = [];
        foreach (array_keys(self::FILTERS) as $key) {
            $counts[$key] = $this->applyFilter($this->run->results()->getQuery(), $key)->count();
        }

        return view('livewire.run-show', [
            'results' => $this->applyFilter($this->run->results()->getQuery(), $filter)->paginate(self::PER_PAGE),
            'counts' => $counts,
            'filter' => $filter,
        ])->title('Run · '.$this->run->collection_name);
    }
}
