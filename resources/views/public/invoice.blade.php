@php
    use App\Support\MoneyFormat;
    $cur = (string) $invoice->currency;
    $money = fn ($m) => MoneyFormat::display((int) $m, $cur);
    $isTax = $invoice->doc_type === 'tax_invoice';
    $title = match ($invoice->doc_type) { 'tax_invoice' => 'Tax Invoice', 'proforma' => 'Proforma Invoice', 'credit_note' => 'Credit Note', default => 'Receipt' };
    $seller = is_array($invoice->seller_snapshot_json) ? $invoice->seller_snapshot_json : [];
@endphp
<x-layouts.guest :title="$title.' '.$invoice->invoice_number">
    <div class="min-h-screen bg-paper-50 py-8 px-4">
        <div class="max-w-2xl mx-auto">
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="px-6 py-5 bg-wa-deep text-paper-0 flex items-start justify-between gap-4">
                    <div>
                        <div class="font-serif text-[22px] leading-tight">{{ $title }}</div>
                        <div class="font-mono text-[12px] opacity-80 mt-0.5">{{ $invoice->invoice_number }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[13px] font-semibold">{{ $seller['name'] ?? brand_name() }}</div>
                        <div class="text-[11px] opacity-80">{{ $invoice->issued_at?->format('d M Y') }}</div>
                    </div>
                </div>

                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between text-[13px]">
                        <div>
                            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">Billed to</div>
                            <div class="font-semibold">{{ $invoice->buyer_name ?: 'Customer' }}</div>
                            @if ($invoice->buyer_phone)<div class="text-ink-500">{{ $invoice->buyer_phone }}</div>@endif
                        </div>
                        <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-mono uppercase bg-wa-mint text-wa-deep">{{ $invoice->status }}</span>
                    </div>

                    <div class="border border-paper-200 rounded-xl overflow-hidden">
                        <table class="w-full text-[12.5px]">
                            <thead class="bg-paper-50 text-left font-mono text-[10px] uppercase text-ink-500">
                                <tr><th class="px-3 py-2">Item</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Amount</th></tr>
                            </thead>
                            <tbody class="divide-y divide-paper-100">
                                @foreach ($invoice->items as $it)
                                    <tr><td class="px-3 py-2">{{ $it->description }}</td><td class="px-3 py-2 text-right">{{ rtrim(rtrim((string) $it->qty,'0'),'.') }}</td><td class="px-3 py-2 text-right">{{ $money($it->line_subtotal_minor) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="ml-auto max-w-[260px] space-y-1 text-[13px]">
                        <div class="flex justify-between text-ink-600"><span>Subtotal</span><span>{{ $money($invoice->subtotal_minor) }}</span></div>
                        @if ($invoice->discount_minor > 0)<div class="flex justify-between text-ink-600"><span>Discount</span><span>-{{ $money($invoice->discount_minor) }}</span></div>@endif
                        @if ($invoice->shipping_minor > 0)<div class="flex justify-between text-ink-600"><span>Shipping</span><span>{{ $money($invoice->shipping_minor) }}</span></div>@endif
                        @if ($isTax)@foreach ($invoice->taxSummary as $t)<div class="flex justify-between text-ink-600"><span>{{ $t->tax_label }}</span><span>{{ $money($t->amount_minor) }}</span></div>@endforeach @endif
                        <div class="flex justify-between font-semibold text-[15px] pt-2 border-t border-paper-200"><span>Total</span><span>{{ $money($invoice->total_minor) }}</span></div>
                    </div>

                    @if (session('success'))
                        <div class="mb-3 rounded-xl bg-wa-mint text-wa-deep text-[12.5px] px-4 py-2.5">{{ session('success') }}</div>
                    @elseif (session('error'))
                        <div class="mb-3 rounded-xl bg-red-50 text-red-700 text-[12.5px] px-4 py-2.5">{{ session('error') }}</div>
                    @elseif (session('info'))
                        <div class="mb-3 rounded-xl bg-paper-100 text-ink-700 text-[12.5px] px-4 py-2.5">{{ session('info') }}</div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        @if (($invoice->status ?? '') !== 'paid' && (int) $invoice->total_minor > 0 && ($gateways ?? collect())->isNotEmpty())
                            @foreach ($gateways as $g)
                                <a href="{{ route('invoice.public.pay', ['token' => $invoice->public_token, 'gateway' => $g['slug']]) }}"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[13px] font-semibold">
                                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 4h12v8H2z"/><path d="M2 7h12"/></svg>
                                    {{ __('Pay with') }} {{ $g['label'] }}
                                </a>
                            @endforeach
                        @elseif (($invoice->status ?? '') === 'paid')
                            <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-wa-mint text-wa-deep text-[12.5px] font-semibold">
                                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8l3 3 7-7"/></svg>
                                {{ __('Paid') }}
                            </span>
                        @endif

                        <a href="{{ route('invoice.public.pdf', $invoice->public_token) }}" target="_blank"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full {{ (($invoice->status ?? '') !== 'paid' && ($gateways ?? collect())->isNotEmpty()) ? 'border border-paper-200 bg-paper-0 hover:bg-paper-50 text-ink-800' : 'bg-wa-deep hover:bg-wa-teal text-paper-0' }} text-[13px] font-semibold">
                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 2v8M5 7l3 3 3-3M3 13h10"/></svg>
                            {{ __('Download PDF') }}
                        </a>
                    </div>
                </div>
            </div>
            <p class="text-center text-[11px] text-ink-400 mt-4">{{ brand_name() }}</p>
        </div>
    </div>
</x-layouts.guest>
