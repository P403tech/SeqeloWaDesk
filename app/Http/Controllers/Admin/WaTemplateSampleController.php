<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WaTemplateSample;
use App\Support\WaTemplateSampleLibrary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /admin/template-samples — WhatsApp sample copy tenants can start from.
 * Not Meta-approved. "Use sample" on /templates copies into the tenant form.
 */
class WaTemplateSampleController extends Controller
{
    public function index()
    {
        $samples = WaTemplateSample::query()->ordered()->paginate(40);
        $stats = [
            'total'  => WaTemplateSample::count(),
            'active' => WaTemplateSample::where('is_active', true)->count(),
        ];

        return view('admin.template-samples.index', compact('samples', 'stats'));
    }

    public function create()
    {
        return view('admin.template-samples.form', ['sample' => null]);
    }

    public function edit(int $id)
    {
        return view('admin.template-samples.form', [
            'sample' => WaTemplateSample::findOrFail($id),
        ]);
    }

    public function store(Request $request)
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, int $id)
    {
        return $this->persist($request, WaTemplateSample::findOrFail($id));
    }

    public function toggle(int $id)
    {
        $row = WaTemplateSample::findOrFail($id);
        $row->update(['is_active' => ! $row->is_active]);

        return back()->with('success', $row->is_active
            ? __('Sample is now visible to tenants.')
            : __('Sample hidden from tenants.'));
    }

    public function destroy(int $id)
    {
        WaTemplateSample::findOrFail($id)->delete();

        return redirect()->route('admin.template-samples.index')
            ->with('success', __('Sample deleted.'));
    }

    private function persist(Request $request, ?WaTemplateSample $sample)
    {
        $cats = array_keys(WaTemplateSampleLibrary::CATEGORIES);
        $meta = array_keys(WaTemplateSample::META_CATEGORIES);

        $data = $request->validate([
            'title'         => ['required', 'string', 'max:160'],
            'slug'          => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('wa_template_samples', 'slug')->ignore($sample?->id),
            ],
            'category'      => ['required', Rule::in($cats)],
            'meta_category' => ['required', Rule::in($meta)],
            'language'      => ['nullable', 'string', 'max:12'],
            'header'        => ['required', 'string', 'max:60'],
            'body'          => ['required', 'string', 'max:1024'],
            'footer'        => ['nullable', 'string', 'max:60'],
            'emoji'         => ['nullable', 'string', 'max:16'],
            'color_from'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_to'      => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'     => ['nullable', 'boolean'],
            'buttons'       => ['nullable', 'array', 'max:3'],
            'buttons.*.type'=> ['nullable', Rule::in(['quick_reply', 'visit_website'])],
            'buttons.*.text'=> ['nullable', 'string', 'max:25'],
            'buttons.*.value'=> ['nullable', 'string', 'max:2000'],
        ]);

        $body = trim($data['body']);
        if (str_starts_with($body, '{{') || str_ends_with($body, '}}')) {
            return back()->withInput()->withErrors([
                'body' => __('Meta rejects a body that starts or ends with a variable. Add words around {{name}}.'),
            ]);
        }

        $footer = trim((string) ($data['footer'] ?? ''));
        if ($footer === '' && $data['meta_category'] === 'marketing') {
            $footer = 'Reply STOP to unsubscribe';
        }

        $payload = [
            'title'         => $data['title'],
            'slug'          => strtolower($data['slug']),
            'category'      => $data['category'],
            'meta_category' => $data['meta_category'],
            'language'      => ($data['language'] ?? '') ?: 'en_US',
            'header'        => $data['header'],
            'body'          => $body,
            'footer'        => $footer,
            'emoji'         => ($data['emoji'] ?? '') ?: '✦',
            'color_from'    => strtoupper($data['color_from']),
            'color_to'      => strtoupper($data['color_to']),
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
            'is_active'     => $request->boolean('is_active'),
            'buttons'       => $this->cleanButtons($data['buttons'] ?? []),
        ];

        if ($sample) {
            $sample->update($payload);
        } else {
            $payload['created_by'] = $request->user()?->id;
            WaTemplateSample::create($payload);
        }

        return redirect()->route('admin.template-samples.index')
            ->with('success', __('Sample saved. Visible tenants can Use sample on Templates.'));
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array{type: string, text: string, value?: string}>
     */
    private function cleanButtons(array $raw): array
    {
        $out = [];
        foreach ($raw as $btn) {
            if (! is_array($btn)) {
                continue;
            }
            $text = trim((string) ($btn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $type = ($btn['type'] ?? 'quick_reply') === 'visit_website' ? 'visit_website' : 'quick_reply';
            $row = ['type' => $type, 'text' => mb_substr($text, 0, 25)];
            if ($type === 'visit_website') {
                $url = trim((string) ($btn['value'] ?? ''));
                $row['value'] = $url !== '' ? $url : 'https://example.com';
            }
            $out[] = $row;
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }
}
