<?php

use App\NativeComponents\Arcade;
use App\NativeComponents\Home;
use App\NativeComponents\Scores;
use App\NativeComponents\Soundcheck;
use Illuminate\Support\Facades\Route;

Route::native('/', Home::class);

Route::native('/arcade', Arcade::class);

Route::native('/scores', Scores::class);

Route::native('/soundcheck', Soundcheck::class);
