<?php

use App\Livewire\Dashboard;
use App\Livewire\Projects;
use App\Livewire\ProjectShow;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/projects', Projects::class)->name('projects');
Route::livewire('/projects/{project:slug}', ProjectShow::class)->name('projects.show');

// Replaced by their real pages in the phase named.
Route::view('/runs', 'placeholder', ['title' => 'Runs', 'phase' => 11])->name('runs');
Route::view('/settings', 'placeholder', ['title' => 'Settings', 'phase' => 12])->name('settings');
