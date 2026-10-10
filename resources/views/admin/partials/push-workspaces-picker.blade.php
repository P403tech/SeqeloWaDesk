@php
    $workspaces = $workspaces ?? collect();
    $formAction = $formAction ?? '#';
    $title = $title ?? __('Push to workspaces');
@endphp

<form method="POST" action="{{ $formAction }}" class="space-y-5 max-w-2xl">
    @csrf
    <fieldset class="space-y-3">
        <legend class="text-[13px] font-semibold text-ink-900">{{ __('Who receives this?') }}</legend>
        <label class="flex items-start gap-3 p-3 rounded-xl border border-paper-200 cursor-pointer hover:bg-paper-50">
            <input type="radio" name="scope" value="all" class="mt-1" checked>
            <span>
                <span class="block text-[13px] font-medium">{{ __('All active workspaces') }}</span>
                <span class="block text-[11.5px] text-ink-500">{{ __('Same as the quick Push button on the gallery.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-3 p-3 rounded-xl border border-paper-200 cursor-pointer hover:bg-paper-50">
            <input type="radio" name="scope" value="selected" class="mt-1">
            <span class="flex-1">
                <span class="block text-[13px] font-medium">{{ __('Selected workspaces only') }}</span>
                <span class="block text-[11.5px] text-ink-500 mb-2">{{ __('Pick agencies, pilots, or a single customer.') }}</span>
                <div class="max-h-48 overflow-y-auto border border-paper-200 rounded-xl p-2 space-y-1 bg-paper-0">
                    @forelse ($workspaces as $ws)
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-paper-50 text-[12.5px]">
                            <input type="checkbox" name="workspace_ids[]" value="{{ $ws->id }}" class="rounded">
                            <span>{{ $ws->name }}</span>
                            <span class="font-mono text-[10px] text-ink-400 ml-auto">{{ $ws->slug }}</span>
                        </label>
                    @empty
                        <p class="text-[12px] text-ink-500 px-2 py-4">{{ __('No active workspaces.') }}</p>
                    @endforelse
                </div>
                @error('workspace_ids')
                    <p class="text-[11px] text-accent-coral mt-1">{{ $message }}</p>
                @enderror
            </span>
        </label>
    </fieldset>
    <div class="flex items-center gap-2">
        <button type="submit" class="px-5 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Push') }}</button>
        <a href="{{ url()->previous() }}" class="text-[12px] text-ink-600 hover:underline">{{ __('Cancel') }}</a>
    </div>
</form>
