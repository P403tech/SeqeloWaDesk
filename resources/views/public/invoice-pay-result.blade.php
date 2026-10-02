<x-layouts.guest :title="__('Payment')">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-md w-full bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-7 text-center">
            @php
                $tone = match ($state) {
                    'paid', 'success' => ['bg' => 'bg-wa-mint', 'fg' => 'text-wa-deep'],
                    'failed', 'error' => ['bg' => 'bg-red-50', 'fg' => 'text-red-700'],
                    default           => ['bg' => 'bg-paper-100', 'fg' => 'text-ink-700'],
                };
            @endphp
            <div class="mx-auto w-12 h-12 rounded-full {{ $tone['bg'] }} {{ $tone['fg'] }} flex items-center justify-center mb-4">
                @if (in_array($state, ['paid', 'success'], true))
                    <svg viewBox="0 0 20 20" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 10l4 4 8-8"/></svg>
                @elseif (in_array($state, ['failed', 'error'], true))
                    <svg viewBox="0 0 20 20" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l8 8M14 6l-8 8"/></svg>
                @else
                    <svg viewBox="0 0 20 20" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 5v5l3 2"/></svg>
                @endif
            </div>
            <p class="text-[14px] text-ink-800">{{ $message }}</p>
            @if (!empty($token))
                <a href="{{ route('invoice.public.show', $token) }}"
                    class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[13px] font-semibold">
                    {{ __('Back to invoice') }}
                </a>
            @endif
            <p class="text-center text-[11px] text-ink-400 mt-4">{{ brand_name() }}</p>
        </div>
    </div>
</x-layouts.guest>
