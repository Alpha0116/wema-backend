<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/docs/api');
});

Route::get('/docs', function () {
    return redirect('/docs/api');
});

Route::get('/api/documentation', function () {
    return redirect('/docs/api');
});

Route::get('/swagger', function () {
    return redirect('/docs/api');
});
