<?php

namespace App\Models;

use App\Support\WaTemplateSampleLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-curated WhatsApp sample (not Meta-approved). Tenants "Use sample"
 * on /templates to copy this copy into their own create form.
 */
class WaTemplateSample extends Model
{
    protected $fillable = [
        'slug', 'title', 'category', 'meta_category', 'language',
        'header', 'body', 'footer', 'buttons',
        'color_from', 'color_to', 'emoji',
        'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'buttons'    => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public const META_CATEGORIES = [
        'marketing'      => 'Marketing',
        'utility'        => 'Utility',
        'authentication' => 'Authentication',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Shape the customer gallery / create-form expects.
     *
     * @return array<string, mixed>
     */
    public function toLibraryArray(): array
    {
        $body = (string) $this->body;
        $preview = preg_replace('/\s+/', ' ', $body) ?? $body;
        $from = $this->color_from ?: '#1B4B3D';
        $to = $this->color_to ?: '#037D66';

        return [
            'slug'           => (string) $this->slug,
            'title'          => (string) ($this->title ?: str_replace('_', ' ', (string) $this->slug)),
            'category'       => (string) $this->category,
            'category_label' => WaTemplateSampleLibrary::CATEGORIES[$this->category] ?? $this->category,
            'meta_category'  => (string) $this->meta_category,
            'language'       => (string) ($this->language ?: 'en_US'),
            'header'         => (string) $this->header,
            'body'           => $body,
            'footer'         => (string) $this->footer,
            'buttons'        => is_array($this->buttons) ? $this->buttons : [],
            'preview'        => mb_strlen($preview) > 140 ? mb_substr($preview, 0, 137).'…' : $preview,
            'gradient'       => 'from-['.$from.'] to-['.$to.']',
            'color_from'     => $from,
            'color_to'       => $to,
            'emoji'          => (string) ($this->emoji ?: '✦'),
        ];
    }

    /** Insert the built-in catalog once (migration / seeder). Never overwrites admin edits. */
    public static function seedBuiltins(): void
    {
        foreach (WaTemplateSampleLibrary::builtins() as $i => $row) {
            static::query()->firstOrCreate(
                ['slug' => $row['slug']],
                [
                    'title'         => $row['title'],
                    'category'      => $row['category'],
                    'meta_category' => $row['meta_category'],
                    'language'      => $row['language'] ?? 'en_US',
                    'header'        => $row['header'],
                    'body'          => $row['body'],
                    'footer'        => $row['footer'] ?? '',
                    'buttons'       => $row['buttons'] ?? [],
                    'color_from'    => $row['color_from'] ?? '#1B4B3D',
                    'color_to'      => $row['color_to'] ?? '#037D66',
                    'emoji'         => $row['emoji'] ?? '✦',
                    'is_active'     => true,
                    'sort_order'    => $i * 10,
                ]
            );
        }
    }
}
