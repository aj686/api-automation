<?php

namespace App\Livewire;

use App\Models\CaptureBucket;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Capture URLs of one project and what they received (plan section 13, D-032).
 */
class Captures extends Component
{
    public Project $project;

    // New bucket
    public string $name = '';

    public string $slug = '';

    public ?int $environmentId = null;

    public int $responseStatus = 200;

    public int $expiresAfterHours = 24;

    /** The full capture path of a just-created or re-issued token; shown once. */
    #[Locked]
    public ?string $revealedPath = null;

    #[Locked]
    public ?int $revealedBucketId = null;

    #[Locked]
    public ?int $viewingId = null;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->environmentId = $this->allowedEnvironments()->first()?->id;
        $this->viewingId = $project->captureBuckets()->orderBy('name')->value('id');
    }

    /**
     * Production environments are never offered (loophole 40).
     *
     * @return Collection<int, Environment>
     */
    private function allowedEnvironments()
    {
        return $this->project->environments()->orderBy('name')->get()->reject->isProduction()->values();
    }

    public function createBucket(): void
    {
        $this->name = trim($this->name);
        $this->slug = trim($this->slug) !== '' ? trim($this->slug) : Str::slug($this->name);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('capture_buckets', 'slug')->where('project_id', $this->project->id)],
            'environmentId' => ['required', Rule::in($this->allowedEnvironments()->pluck('id')->all())],
            'responseStatus' => ['required', 'integer', 'between:200,599'],
            'expiresAfterHours' => ['required', 'integer', 'between:1,168'],
        ], [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'environmentId.in' => 'Choose a non-production environment of this project.',
        ], ['environmentId' => 'environment', 'responseStatus' => 'response status', 'expiresAfterHours' => 'retention']);

        $bucket = new CaptureBucket([
            'environment_id' => $data['environmentId'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'response_status' => $data['responseStatus'],
            'expires_after_hours' => $data['expiresAfterHours'],
            'enabled' => false,
        ]);
        $token = $bucket->issueToken();
        $this->project->captureBuckets()->save($bucket);

        $this->reveal($bucket, $token);
        $this->viewingId = $bucket->id;
        $this->reset('name', 'slug');
        session()->now('status', "Created {$bucket->name}. It is disabled until you enable it.");
    }

    public function reissueToken(int $id): void
    {
        $bucket = $this->bucket($id);
        $token = $bucket->issueToken();
        $bucket->save();

        $this->reveal($bucket, $token);
        session()->now('status', "New URL for {$bucket->name}. The old one stopped working.");
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->bucket($id)->update(['enabled' => $enabled]);
    }

    public function view(int $id): void
    {
        $this->viewingId = $this->bucket($id)->id;
    }

    public function clearRequests(int $id): void
    {
        $this->bucket($id)->requests()->delete();
    }

    public function deleteBucket(int $id): void
    {
        $bucket = $this->bucket($id);
        $bucket->delete();

        if ($this->revealedBucketId === $id) {
            $this->reset('revealedPath', 'revealedBucketId');
        }
        if ($this->viewingId === $id) {
            $this->viewingId = null;
        }
        session()->now('status', "Deleted {$bucket->name} and everything it received.");
    }

    private function reveal(CaptureBucket $bucket, string $token): void
    {
        $this->revealedPath = $bucket->capturePath($token);
        $this->revealedBucketId = $bucket->id;
    }

    private function bucket(?int $id): CaptureBucket
    {
        return $this->project->captureBuckets()->findOrFail($id);
    }

    public function render(): View
    {
        $buckets = $this->project->captureBuckets()->with('environment')->withCount('requests')->orderBy('name')->get();
        $viewing = $buckets->firstWhere('id', $this->viewingId);
        $viewing?->pruneExpired();

        return view('livewire.captures', [
            'buckets' => $buckets,
            'environments' => $this->allowedEnvironments(),
            'viewing' => $viewing,
            'requests' => $viewing ? $viewing->requests()->orderByDesc('received_at')->orderByDesc('id')->limit(20)->get() : collect(),
        ])->title('Captures · '.$this->project->name);
    }
}
