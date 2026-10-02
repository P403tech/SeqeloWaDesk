<?php

/*
|--------------------------------------------------------------------------
| Threads (Meta) channel routes — WaDesk core.
|--------------------------------------------------------------------------
| Official Threads API. A workspace connects a Threads account via OAuth, then
| composes / schedules posts that publish through the 2-step container flow.
| Loaded from bootstrap/app.php in the same slot as facebook/telegram.
*/

use App\Http\Controllers\Threads\ThreadsConnectController;
use App\Http\Controllers\Threads\ThreadsInsightsController;
use App\Http\Controllers\Threads\ThreadsPostsController;
use App\Http\Controllers\Threads\ThreadsReplyRuleController;
use App\Http\Controllers\Threads\ThreadsSettingsController;
use Illuminate\Support\Facades\Route;

// ── Connect + compose — session + workspace + plan-gated. ──
Route::middleware(['web', 'auth', 'plan:access_threads'])
    ->name('user.threads.')
    ->group(function () {
        Route::get('/threads',            [ThreadsConnectController::class, 'index'])->name('index');
        Route::get('/threads/connect',    [ThreadsConnectController::class, 'start'])->name('connect');
        Route::get('/threads/callback',   [ThreadsConnectController::class, 'callback'])->name('callback');
        Route::post('/threads/manual',    [ThreadsConnectController::class, 'manual'])->name('manual');
        Route::delete('/threads/{account}', [ThreadsConnectController::class, 'disconnect'])->whereNumber('account')->name('disconnect');

        // Composer & scheduler — sub-gated on threads_posts.
        Route::get('/threads/posts', [ThreadsPostsController::class, 'index'])->name('posts');
        Route::middleware('plan:threads_posts')->group(function () {
            Route::post('/threads/posts',           [ThreadsPostsController::class, 'store'])->name('posts.store');
            Route::delete('/threads/posts/{post}',  [ThreadsPostsController::class, 'destroy'])->whereNumber('post')->name('posts.destroy');
        });

        // Reply auto-responder — sub-gated on threads_replies.
        Route::middleware('plan:threads_replies')->group(function () {
            Route::get('/threads/replies',                  [ThreadsReplyRuleController::class, 'index'])->name('replies');
            Route::post('/threads/replies',                 [ThreadsReplyRuleController::class, 'store'])->name('replies.store');
            Route::post('/threads/replies/{rule}/toggle',   [ThreadsReplyRuleController::class, 'toggle'])->whereNumber('rule')->name('replies.toggle');
            Route::delete('/threads/replies/{rule}',        [ThreadsReplyRuleController::class, 'destroy'])->whereNumber('rule')->name('replies.destroy');
        });

        // Insights dashboard — sub-gated on threads_insights.
        Route::get('/threads/insights', [ThreadsInsightsController::class, 'index'])->middleware('plan:threads_insights')->name('insights');
    });

// ── Admin credentials page. ──
Route::middleware(['web', 'auth', 'admin'])->prefix('admin/settings')->name('admin.settings.')
    ->group(function () {
        Route::get('/threads',  [ThreadsSettingsController::class, 'settings'])->name('threads');
        Route::post('/threads', [ThreadsSettingsController::class, 'save'])->name('threads.save');
    });

// ── Health ping ──
Route::middleware('web')->get('/threads/_health', fn () => response()->json(['ok' => true, 'channel' => 'threads', 'version' => '1.0.0']));
