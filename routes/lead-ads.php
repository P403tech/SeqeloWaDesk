<?php

/*
|--------------------------------------------------------------------------
| Meta Lead Ads — Instant Forms → contacts + deals
|--------------------------------------------------------------------------
|
| A product of its own, not a sub-page of the Facebook channel: it is gated on
| `access_lead_ads` and sold separately, because a workspace can run Pages
| without buying lead capture. It still RUNS on a connected Facebook Page — the
| `leadgen` webhook and the Graph reads both belong to the Page — so the screen
| tells the operator to connect one when they have not.
|
| The backfill sweep rides on the index() page load (project policy is no
| `schedule:run`), so opening this page is also what catches leads the realtime
| webhook never delivered. Meta deletes leads after ~90 days.
|
*/

use App\Http\Controllers\Facebook\MetaLeadsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'plan:access_lead_ads'])
    ->name('user.lead-ads.')
    ->group(function () {
        Route::get('/lead-ads',                        [MetaLeadsController::class, 'index'])->name('index');
        Route::post('/lead-ads/sync',                  [MetaLeadsController::class, 'syncForms'])->name('sync');
        Route::post('/lead-ads/forms/{id}',            [MetaLeadsController::class, 'update'])->whereNumber('id')->name('forms.update');
        Route::post('/lead-ads/forms/{id}/backfill',   [MetaLeadsController::class, 'backfill'])->whereNumber('id')->name('forms.backfill');
        Route::post('/lead-ads/{id}/retry',            [MetaLeadsController::class, 'retry'])->whereNumber('id')->name('retry');
    });
