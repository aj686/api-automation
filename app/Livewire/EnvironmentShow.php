<?php

namespace App\Livewire;

use App\Enums\EnvironmentType;
use App\Exceptions\ImportException;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Services\EnvironmentImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One environment: its settings and every variable it holds (plan section 13).
 *
 * Secret values never reach the browser. Livewire round-trips public
 * properties through the page, so a stored secret is never loaded into one:
 * editing a secret starts from an empty field and empty means "keep".
 */
class EnvironmentShow extends Component
{
    use WithFileUploads;

    public Project $project;

    public Environment $environment;

    // Settings
    public string $name = '';

    public string $slug = '';

    public string $type = '';

    public string $base_url_hint = '';

    public bool $allow_automation = false;

    // New variable
    public string $newKey = '';

    public string $newValue = '';

    public bool $newSecret = false;

    // Variable being edited
    #[Locked]
    public ?int $editingId = null;

    public string $editKey = '';

    public string $editValue = '';

    public bool $editSecret = false;

    public bool $editEnabled = true;

    // Import
    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    // Delete
    public string $confirmName = '';

    public function mount(Project $project, Environment $environment): void
    {
        $this->project = $project;
        $this->environment = $environment;
        $this->fillSettings();
    }

    private function fillSettings(): void
    {
        $this->name = $this->environment->name;
        $this->slug = $this->environment->slug;
        $this->type = $this->environment->type->value;
        $this->base_url_hint = (string) $this->environment->base_url_hint;
        $this->allow_automation = $this->environment->allow_automation;
    }

    /**
     * Switching the form to production unticks automation, so allowing it
     * stays a deliberate second step.
     */
    public function updatedType(string $value): void
    {
        if ($value === EnvironmentType::Production->value) {
            $this->allow_automation = false;
        }
    }

    public function saveSettings(): void
    {
        $this->name = trim($this->name);
        $this->slug = trim($this->slug);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('environments', 'slug')->where('project_id', $this->project->id)->ignore($this->environment)],
            'type' => ['required', Rule::enum(EnvironmentType::class)],
            'base_url_hint' => ['nullable', 'url', 'max:255'],
            'allow_automation' => ['boolean'],
        ], ['slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.']);

        // Server-side too: a request that skips updatedType() still cannot
        // turn an environment into production with automation left on.
        $becomesProduction = $data['type'] === EnvironmentType::Production->value && ! $this->environment->isProduction();
        if ($becomesProduction) {
            $data['allow_automation'] = false;
            $this->allow_automation = false;
        }

        $slugChanged = $data['slug'] !== $this->environment->slug;
        $this->environment->update([...$data, 'base_url_hint' => trim($this->base_url_hint) ?: null]);

        if ($slugChanged) {
            $this->redirectRoute('projects.environments.show', [$this->project, $this->environment]);

            return;
        }

        session()->now('status', $becomesProduction
            ? 'Environment saved. It is now production, so automation was turned off.'
            : 'Environment saved.');
    }

    public function addVariable(): void
    {
        $this->newKey = trim($this->newKey);

        $this->validate([
            'newKey' => ['required', 'regex:'.EnvironmentVariable::KEY_PATTERN,
                Rule::unique('environment_variables', 'key')->where('environment_id', $this->environment->id)],
            'newValue' => ['nullable', 'string', 'max:'.EnvironmentVariable::MAX_VALUE_LENGTH],
        ], $this->keyMessages('newKey'), ['newKey' => 'key', 'newValue' => 'value']);

        $this->environment->variables()->create([
            'key' => $this->newKey,
            'value' => $this->newValue === '' ? null : $this->newValue,
            'is_secret' => $this->newSecret,
            'enabled' => true,
        ]);

        $this->reset('newKey', 'newValue', 'newSecret');
    }

    public function startEdit(int $id): void
    {
        $variable = $this->variable($id);

        $this->resetValidation();
        $this->editingId = $variable->id;
        $this->editKey = $variable->key;
        $this->editSecret = $variable->is_secret;
        $this->editEnabled = $variable->enabled;
        // Never load a secret into a property that is sent to the browser.
        $this->editValue = $variable->is_secret ? '' : (string) $variable->value;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editKey', 'editValue', 'editSecret', 'editEnabled');
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        $variable = $this->variable($this->editingId);
        $this->editKey = trim($this->editKey);

        $this->validate([
            'editKey' => ['required', 'regex:'.EnvironmentVariable::KEY_PATTERN,
                Rule::unique('environment_variables', 'key')->where('environment_id', $this->environment->id)->ignore($variable)],
            'editValue' => ['nullable', 'string', 'max:'.EnvironmentVariable::MAX_VALUE_LENGTH],
        ], $this->keyMessages('editKey'), ['editKey' => 'key', 'editValue' => 'value']);

        $unmasking = $variable->is_secret && ! $this->editSecret;
        if ($unmasking && $this->editValue === '') {
            // Otherwise un-ticking "secret" would reveal a value that was hidden on purpose.
            $this->addError('editValue', 'To stop treating this as a secret, enter a new value. The stored one is never shown.');

            return;
        }

        $changes = ['key' => $this->editKey, 'is_secret' => $this->editSecret, 'enabled' => $this->editEnabled];

        // For a secret, an empty field means "keep the stored value".
        if (! ($variable->is_secret && $this->editValue === '')) {
            $changes['value'] = $this->editValue === '' ? null : $this->editValue;
        }

        $variable->update($changes);
        $this->cancelEdit();
    }

    public function toggleEnabled(int $id): void
    {
        $variable = $this->variable($id);
        $variable->update(['enabled' => ! $variable->enabled]);
    }

    public function deleteVariable(int $id): void
    {
        $this->variable($id)->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    public function import(EnvironmentImporter $importer): void
    {
        $this->validate(['importFile' => ['required', 'file', 'max:1024']], [], ['importFile' => 'file']);

        try {
            $counts = $importer->import($this->environment, $this->importFile->get());
        } catch (ImportException $e) {
            $this->addError('importFile', $e->getMessage());

            return;
        } finally {
            $this->discardUpload();
        }

        $message = "Imported: {$counts['added']} added, {$counts['updated']} updated";
        $message .= $counts['kept'] ? ", {$counts['kept']} kept (empty in the file)." : '.';
        if ($counts['secret_by_name']) {
            $message .= " {$counts['secret_by_name']} marked secret because of their name — check them below.";
        }
        session()->now('status', $message);
    }

    public function delete(): void
    {
        if ($this->confirmName !== $this->environment->name) {
            $this->addError('confirmName', 'Type the environment name exactly to confirm.');

            return;
        }

        $name = $this->environment->name;
        $this->environment->delete();

        session()->flash('status', "Deleted environment {$name} and its variables. Run history was kept.");
        $this->redirectRoute('projects.environments', $this->project);
    }

    /**
     * The upload may hold real credentials, so it is removed at once rather
     * than left for Livewire's 24-hour cleanup. Livewire also writes a
     * `<file>.json` metadata file (original name, size, hash) beside it that
     * TemporaryUploadedFile::delete() leaves behind; remove that too.
     */
    private function discardUpload(): void
    {
        $metaFile = FileUploadConfiguration::path($this->importFile->getFilename().'.json', false);

        $this->importFile->delete();
        FileUploadConfiguration::storage()->delete($metaFile);
        $this->importFile = null;
    }

    private function variable(?int $id): EnvironmentVariable
    {
        // Scoped to this environment, so an id from another one cannot be edited here.
        return $this->environment->variables()->findOrFail($id);
    }

    /**
     * @return array<string, string>
     */
    private function keyMessages(string $field): array
    {
        return [
            "{$field}.regex" => 'Keys may use letters, numbers, dot, underscore and hyphen, up to 100 characters.',
            "{$field}.unique" => 'This environment already has a variable with that key.',
        ];
    }

    public function render(): View
    {
        return view('livewire.environment-show', [
            'variables' => $this->environment->variables()->orderBy('key')->get(),
            'types' => EnvironmentType::cases(),
        ])->title($this->environment->name.' · '.$this->project->name);
    }
}
