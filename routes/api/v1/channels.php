<?php

use App\Http\Controllers\Api\V1\ChannelController;
use Illuminate\Support\Facades\Route;

// Channels — list every connected channel (WhatsApp / Facebook / Instagram /
// Telegram) with its id + status, to discover what POST /messages can send on.
Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');

// Bulk send — one message to many recipients on a single channel (the channel
// equivalent of /broadcasts). Returns a per-recipient result list.
Route::post('/channels/broadcast', [ChannelController::class, 'broadcast'])->name('channels.broadcast');
