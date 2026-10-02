<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiTrainingSource extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_training_sources';

    protected $fillable = [
        'workspace_id', 'assistant_id', 'user_id',
        'kind', 'label', 'url', 'file_path',
        'content', 'question', 'answer',
        'status', 'tokens_estimate', 'error',
    ];

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(AiChatAssistant::class, 'assistant_id');
    }

    /**
     * The text we feed into the model. For Q&A pairs we synthesise a
     * "Q: ... A: ..." block so the AI sees both sides; for URL/file/
     * text we just hand back the extracted body; for a `catalog` source
     * we render the workspace's live products so the agent can answer
     * "what do you sell / how much is X / is Y in stock" without anyone
     * pasting the catalog by hand — and it stays fresh automatically.
     */
    public function renderedText(): string
    {
        if ($this->kind === 'qa') {
            return "Q: " . trim((string) $this->question) . "\nA: " . trim((string) $this->answer);
        }
        if ($this->kind === 'catalog') {
            return static::renderCatalog((int) $this->workspace_id);
        }
        return (string) ($this->content ?? '');
    }

    /**
     * Compact plain-text dump of a workspace's catalog for the AI. One line
     * per product — name, price, stock and link — capped so it never eats the
     * whole context budget (the AI context is hard-limited at 12k chars).
     */
    public static function renderCatalog(int $workspaceId, int $maxProducts = 120): string
    {
        if ($workspaceId <= 0) return '';

        $rows = WaProduct::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'archived');
            })
            ->orderByDesc('in_stock')      // in-stock first
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($maxProducts)
            ->get();

        if ($rows->isEmpty()) {
            return 'The product catalog is currently empty.';
        }

        $lines = ['Product catalog (name — price — availability):'];
        foreach ($rows as $p) {
            $name = trim((string) $p->name);
            if ($name === '') continue;

            $price = '';
            if ($p->price_minor !== null) {
                $cur   = trim((string) $p->currency_code) ?: '';
                $price = trim($cur . ' ' . number_format(((int) $p->price_minor) / 100, 2));
            }
            $stock = $p->in_stock
                ? 'in stock' . (($p->stock_qty !== null && (int) $p->stock_qty > 0) ? ' (' . (int) $p->stock_qty . ')' : '')
                : 'out of stock';

            $line = '- ' . $name;
            if ($price !== '')                 $line .= ' — ' . $price;
            $line .= ' — ' . $stock;
            if (trim((string) $p->sku) !== '') $line .= ' — SKU ' . trim((string) $p->sku);
            if (trim((string) $p->product_url) !== '') $line .= ' — ' . trim((string) $p->product_url);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
