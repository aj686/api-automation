<?php

use App\Livewire\Captures;
use App\Livewire\Collections;
use App\Livewire\CollectionShow;
use App\Livewire\Dashboard;
use App\Livewire\Environments;
use App\Livewire\EnvironmentShow;
use App\Livewire\Projects;
use App\Livewire\ProjectShow;
use App\Livewire\Runs;
use App\Livewire\RunShow;
use App\Livewire\Settings;
use App\Http\Controllers\CollectionDownloadController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/projects', Projects::class)->name('projects');
Route::livewire('/projects/{project:slug}', ProjectShow::class)->name('projects.show');
Route::livewire('/projects/{project:slug}/environments', Environments::class)->name('projects.environments');
Route::livewire('/projects/{project:slug}/collections', Collections::class)->name('projects.collections');
Route::livewire('/projects/{project:slug}/runs', Runs::class)->name('projects.runs');
Route::livewire('/projects/{project:slug}/captures', Captures::class)->name('projects.captures');
// {environment:slug} is scoped to {project}: another project's slug is a 404.
Route::livewire('/projects/{project:slug}/environments/{environment:slug}', EnvironmentShow::class)->name('projects.environments.show');
Route::livewire('/projects/{project:slug}/collections/{collection:slug}', CollectionShow::class)->name('projects.collections.show');
Route::get('/projects/{project:slug}/collections/{collection:slug}/download', CollectionDownloadController::class)->name('projects.collections.download');

Route::livewire('/runs', Runs::class)->name('runs');
// By ULID, not slug: runs outlive their project (plan section 4).
Route::livewire('/runs/{run}', RunShow::class)->name('runs.show');

Route::livewire('/settings', Settings::class)->name('settings');
