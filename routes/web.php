<?php

use App\NativeComponents\Arcade;
use App\NativeComponents\Home;
use Illuminate\Support\Facades\Route;

Route::native('/', Home::class);

Route::native('/arcade', Arcade::class);
