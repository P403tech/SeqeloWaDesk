@php $libTab = $libTab ?? 'samples'; @endphp
<div class="mt-4 mb-1 flex items-center gap-1 p-1 rounded-full bg-paper-50 border border-paper-200 w-fit">
    <a href="{{ route('user.templates.samples') }}"
        class="px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold {{ $libTab === 'samples' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:text-ink-900' }}">
        {{ __('Template Library') }}
    </a>
    <a href="{{ route('user.templates.index', ['view' => 'yours']) }}"
        class="px-3.5 py-1.5 rounded-full text-[12.5px] font-semibold {{ $libTab === 'yours' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:text-ink-900' }}">
        {{ __('Your Templates') }}
    </a>
</div>
