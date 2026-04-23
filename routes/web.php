<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'taskai.index');

Route::view('/app', 'taskai.index')->name('taskai');

Route::view('/welcome', 'welcome')->name('welcome');
