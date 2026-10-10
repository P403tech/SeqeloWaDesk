<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WaTemplateSample;
use App\Services\WaTemplateSamplePublisher;
use App\Support\WaTemplateSampleLibrary;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /admin/template-samples — WhatsApp sample copy tenants can start from.
 * Save updates the gallery. "Push to customers" installs a real template
 * into every active workspace (Your templates). Not a Meta approval.
 */
class WaTemplateSampleController extends Controller
{
    public function index(Request $request)
    {
        $category = (string) $request->query('category', 'all');
        if ($category !== 'all' && ! isset(WaTemplateSampleLibrary::CATEGORIES[$category])) {
            $category = 'all';
        }
        $search = (string) $request->query('q', '');

        $stats = [
            'total'  => WaTemplateSample::count(),
            'active' => WaTemplateSample::where('is_active', true)->count(),
        ];

        $q = WaTemplateSample::query()->ordered();
        if ($category !== 'all') {
            $q->where('category', $category);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('slug', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        $samples = $q->paginate(40)->withQueryString();
        $categoryCounts = ['all' => WaTemplateSample::count()];
        foreach (array_keys(WaTemplateSampleLibrary::CATEGORIES) as $key) {
            $categoryCounts[$key] = WaTemplateSample::where('category', $key)->count();
        }

        return view('admin.template-samples.index', compact(
            'samples', 'stats', 'category', 'search', 'categoryCounts'
        ));
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

    public function push(int $id)
    {
        $sample = WaTemplateSample::findOrFail($id);
        $stats = app(WaTemplateSamplePublisher::class)->push($sample);

        return redirect()->route('admin.template-samples.index')
            ->with('success', $this->pushFlash($stats));
    }

    private function persist(Request $request, ?WaTemplateSample $sample)
    {
        $cats = array_keys(WaTemplateSampleLibrary::CATEGORIES);
        $meta = array_keys(WaTemplateSample::META_CATEGORIES);

        $headerType = $request->input('header_type') === 'image' ? 'image' : 'text';

        $data = $request->validate([
            'title'         => ['required', 'string', 'max:160'],
            'slug'          => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('wa_template_samples', 'slug')->ignore($sample?->id),
            ],
            'category'      => ['required', Rule::in($cats)],
            'meta_category' => ['required', Rule::in($meta)],
            'language'      => ['nullable', 'string', 'max:12'],
            'header_type'   => ['nullable', Rule::in(['text', 'image'])],
            'header'        => [$headerType === 'text' ? 'required' : 'nullable', 'string', 'max:60'],
            'body'          => ['required', 'string', 'max:1024'],
            'footer'        => ['nullable', 'string', 'max:60'],
            'emoji'         => ['nullable', 'string', 'max:16'],
            'color_from'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_to'      => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'     => ['nullable', 'boolean'],
            'remove_image'  => ['nullable', 'boolean'],
            'image'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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

        $header = trim((string) ($data['header'] ?? ''));
        $imagePath = $sample?->image_path;
        if ($request->boolean('remove_image')) {
            $imagePath = null;
            $headerType = 'text';
        } elseif ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('wa-template-samples', media_disk());
            $headerType = 'image';
        }

        if ($headerType === 'image' && ($imagePath === null || $imagePath === '')) {
            return back()->withInput()->withErrors([
                'image' => __('Upload a JPEG, PNG, or WebP (max 5MB) for an image header.'),
            ]);
        }

        if ($header === '') {
            $header = mb_substr((string) $data['title'], 0, 60);
        }

        $payload = [
            'title'         => $data['title'],
            'slug'          => strtolower($data['slug']),
            'category'      => $data['category'],
            'meta_category' => $data['meta_category'],
            'language'      => ($data['language'] ?? '') ?: 'en_US',
            'header_type'   => $headerType,
            'header'        => $header,
            'image_path'    => $imagePath,
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
            $row = $sample;
        } else {
            $payload['created_by'] = $request->user()?->id;
            $row = WaTemplateSample::create($payload);
        }

        if ($request->boolean('push_to_customers')) {
            $stats = app(WaTemplateSamplePublisher::class)->push($row);

            return redirect()->route('admin.template-samples.index')
                ->with('success', __('Sample saved. ').$this->pushFlash($stats));
        }

        return redirect()->route('admin.template-samples.index')
            ->with('success', __('Sample saved. Visible tenants can Use sample on Templates. Push to customers to put it in their template list.'));
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}  $stats
     */
    private function pushFlash(array $stats): string
    {
        return __('Pushed to customers: :created new, :updated updated, :skipped already on Meta (left alone).', [
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'skipped' => $stats['skipped'],
        ]);
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
