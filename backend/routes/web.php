<?php

use Illuminate\Support\Facades\Route;

// The API host serves the REST API (/api/v1) and the owner back office (/admin).
Route::redirect('/', '/admin');
