<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportBotLog;
use App\Models\SupportBotSource;
use App\Services\SupportBot\SourceIngestor;
use App\Support\SupportBotSettings;
use Illuminate\Http\Request;

/**
 * /admin/settings/support-bot — platform Client Support Bot. Admin sets the
 * master on/off, the escalation tiers (AI + web, each with its own key), the
 * knowledge sources, appearance and thresholds. Users only ask; the ladder
 * (docs → AI → web → contact) answers.
 */
class SupportBotSettingsController extends Controller
{
    /** Common providers the admin can pick (subset of WaDesk's provider list). */
    private const PROVIDERS = [
        'openai' => 'OpenAI (GPT)', 'anthropic' => 'Anthropic (Claude)', 'gemini' => 'Google (Gemini)',
        'mistral' => 'Mistral', 'deepseek' => 'DeepSeek', 'groq' => 'Groq', 'xai' => 'xAI (Grok)',
    ];
    private const WEB_PROVIDERS = [
        'perplexity' => 'Perplexity (web-grounded)', 'openai' => 'OpenAI', 'gemini' => 'Google (Gemini)',
    ];

    public function index()
    {
        $cfg = SupportBotSettings::raw();

        // Reuse the platform's canonical model catalog (same list /admin/api-keys
        // shows) so the admin picks a model from a dropdown instead of typing it.
        $catalog = \App\Http\Controllers\Admin\AdminAiKeyController::MODELS;
        $models = collect(array_keys(self::PROVIDERS))
            ->mapWithKeys(fn ($p) => [$p => $catalog[$p] ?? []])
            ->all();

        // Only offer providers that already have a key in Admin → AI Keys — the
        // bot reuses those, so there is NO key field here.
        $providers = collect(self::PROVIDERS)
            ->filter(fn ($lbl, $p) => SupportBotSettings::providerHasKey($p))
            ->all();
        // Keep the saved provider visible even if its key was later removed.
        if ($cfg['ai_provider'] && !isset($providers[$cfg['ai_provider']]) && isset(self::PROVIDERS[$cfg['ai_provider']])) {
            $providers[$cfg['ai_provider']] = self::PROVIDERS[$cfg['ai_provider']] . ' ' . __('(no key set)');
        }

        return view('admin.support-bot.index', [
            'cfg'           => $cfg,
            'providers'     => $providers,
            'anyKey'        => !empty($providers),
            'models'        => $models,
            'sources'       => SupportBotSource::orderByDesc('id')->limit(200)->get(),
            'chunkCount'    => \App\Models\SupportBotChunk::count(),
            'logs'          => SupportBotLog::orderByDesc('id')->limit(50)->get(),
            'unmatched'     => SupportBotLog::where('matched', false)->count(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'support_bot_enabled'        => ['nullable', 'boolean'],
            'support_bot_ai_enabled'     => ['nullable', 'boolean'],
            'support_bot_web_enabled'    => ['nullable', 'boolean'],
            'support_bot_provider'       => ['nullable', 'string', 'max:32'],
            'support_bot_model'          => ['nullable', 'string', 'max:80'],
            'support_bot_docs_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'support_bot_title'          => ['nullable', 'string', 'max:80'],
            'support_bot_greeting'       => ['nullable', 'string', 'max:400'],
            'support_bot_no_answer'      => ['nullable', 'string', 'max:400'],
            'support_bot_theme_color'    => ['nullable', 'string', 'max:9'],
            'support_bot_position'       => ['nullable', 'in:left,right'],
            'support_bot_support_url'    => ['nullable', 'string', 'max:512'],
            'support_bot_support_email'  => ['nullable', 'string', 'max:191'],
        ]);

        SupportBotSettings::save($data);

        try {
            \App\Services\Inbox\AuditLogger::platform(
                'settings.support-bot.save', auth()->id(), null, 'setting', null,
                ['enabled' => (bool) ($data['support_bot_enabled'] ?? false)]
            );
        } catch (\Throwable $e) { /* audit best-effort */ }

        return back()->with('success', __('Support bot settings saved.'));
    }

    public function addSource(Request $request, SourceIngestor $ingestor)
    {
        $data = $request->validate([
            'kind'  => ['required', 'in:url,text'],
            'label' => ['nullable', 'string', 'max:200'],
            'url'   => ['nullable', 'string', 'max:1024', 'required_if:kind,url'],
            'text'  => ['nullable', 'string', 'required_if:kind,text'],
        ]);

        $source = $data['kind'] === 'url'
            ? $ingestor->ingestUrl($data['url'], $data['label'] ?? null, auth()->id())
            : $ingestor->ingestText($data['text'], $data['label'] ?: __('Text snippet'), auth()->id());

        return $source->status === 'ready'
            ? back()->with('success', __('Source added and indexed.'))
            : back()->with('error', __('Source failed: ') . $source->error);
    }

    public function uploadFile(Request $request, SourceIngestor $ingestor)
    {
        $request->validate([
            'file'  => ['required', 'file', 'max:51200'], // 50 MB (a docs ZIP can be large)
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('file');

        // A ZIP is a folder of docs (e.g. an exported docs site) — extract and
        // ingest each page as its own article.
        if (strtolower($file->getClientOriginalExtension()) === 'zip') {
            $created = $ingestor->ingestZip($file, auth()->id());
            $ready = collect($created)->where('status', 'ready')->count();
            return $ready > 0
                ? back()->with('success', __(':n article(s) imported from the ZIP.', ['n' => $ready]))
                : back()->with('error', __('No readable docs were found in that ZIP (only html/md/pdf/docx/txt/csv are imported).'));
        }

        $source = $ingestor->ingestUpload($file, $request->input('label'), auth()->id());

        return $source->status === 'ready'
            ? back()->with('success', __('File uploaded and indexed.'))
            : back()->with('error', __('Upload failed: ') . $source->error);
    }

    public function deleteSource(int $id)
    {
        $source = SupportBotSource::findOrFail($id);
        $source->forceDelete(); // cascades chunks
        return back()->with('success', __('Source removed.'));
    }

    public function reindex(SourceIngestor $ingestor)
    {
        $n = $ingestor->reindexAll();
        return back()->with('success', __('Reindexed :n sources.', ['n' => $n]));
    }
}
