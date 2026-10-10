<?php

use App\Livewire\Collections;
use App\Livewire\Dashboard;
use App\Livewire\Environments;
use App\Livewire\EnvironmentShow;
use App\Livewire\Projects;
use App\Livewire\ProjectShow;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/projects', Projects::class)->name('projects');
Route::livewire('/projects/{project:slug}', ProjectShow::class)->name('projects.show');
Route::livewire('/projects/{project:slug}/environments', Environments::class)->name('projects.environments');
Route::livewire('/projects/{project:slug}/collections', Collections::class)->name('projects.collections');
// {environment:slug} is scoped to {project}: another project's slug is a 404.
Route::livewire('/projects/{project:slug}/environments/{environment:slug}', EnvironmentShow::class)->name('projects.environments.show');

// Replaced by their real pages in the phase named.
Route::view('/runs', 'placeholder', ['title' => 'Runs', 'phase' => 11])->name('runs');
Route::view('/settings', 'placeholder', ['title' => 'Settings', 'phase' => 12])->name('settings');
