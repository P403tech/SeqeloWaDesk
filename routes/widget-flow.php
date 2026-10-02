<?php

/*
|--------------------------------------------------------------------------
| Chat-widget flow-engine routes — WaDesk core.
|--------------------------------------------------------------------------
|
| Node → Laravel callbacks for the embedded chat-widget flow runtime. The
| widget has no external messaging API, so every SEND comes back here
| (flow-send writes the outbound row the visitor's widget polls for) and smart
| AI / webhook nodes resolve via flow-node. All X-Node-Token guarded,
| CSRF-exempt in bootstrap/app.php. Loaded from bootstrap/app.php in the same
| slot as mailtrixy-email/wechat/viber — BEFORE the workspace-slug catch-all,
| or '/api/widget/...' would be swallowed as a workspace slug.
|
| The visitor-facing widget endpoints stay where they were
| (ChatbotWidgetPublicController); this file is Node→PHP only.
|
*/

use App\Http\Controllers\Widget\WidgetFlowNodeController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::post('/api/widget/flow-send', [WidgetFlowNodeController::class, 'send']);
    Route::post('/api/widget/flow-node', [WidgetFlowNodeController::class, 'node']);
});
