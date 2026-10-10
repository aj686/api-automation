<?php

use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');

// Replaced by their real pages in the phase named.
Route::view('/projects', 'placeholder', ['title' => 'Projects', 'phase' => 6])->name('projects');
Route::view('/runs', 'placeholder', ['title' => 'Runs', 'phase' => 11])->name('runs');
Route::view('/settings', 'placeholder', ['title' => 'Settings', 'phase' => 12])->name('settings');
