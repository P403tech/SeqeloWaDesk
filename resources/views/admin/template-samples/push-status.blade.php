<x-layouts.admin :title="__('Template push status')" admin-key="template-samples" page="admin-template-push-status">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ route('admin.template-samples.index') }}" class="hover:text-ink-900">{{ __('Template library') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Push status') }}</span>
        </div>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">
        <div>
            <h1 class="font-serif text-[28px] sm:text-[36px]">{{ __('Library') }} <span class="italic text-wa-deep">{{ __('push status') }}</span></h1>
            <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">{{ __('Track templates installed from the sample library — especially Cloud API workspaces still waiting on Meta.') }}</p>
        </div>

        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-mono uppercase tracking-wider text-ink-500 mb-1">{{ __('Sample') }}</label>
                <select name="sample_id" class="rounded-xl border border-paper-200 px-3 py-2 text-[13px] min-w-[200px]">
                    <option value="">{{ __('All samples') }}</option>
                    @foreach ($samples as $s)
                        <option value="{{ $s->id }}" @selected($sampleId === $s->id)>{{ $s->title }} ({{ $s->slug }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-mono uppercase tracking-wider text-ink-500 mb-1">{{ __('Stage') }}</label>
                <select name="stage" class="rounded-xl border border-paper-200 px-3 py-2 text-[13px]">
                    @foreach (['all', 'not_submitted', 'pending', 'approved', 'rejected', 'baileys_ready'] as $key)
                        <option value="{{ $key }}" @selected($stage === $key)>{{ $key === 'all' ? __('All stages') : $lifecycle->stageLabel($key) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('Filter') }}</button>
        </form>

        <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $kpis = [
                    ['label' => __('Workspaces'), 'val' => $stats['workspaces_with_pushed']],
                    ['label' => __('Installs'), 'val' => $stats['total_installs']],
                    ['label' => __('Not submitted'), 'val' => $stats['draft_not_submitted']],
                    ['label' => __('Pending Meta'), 'val' => $stats['pending_meta']],
                    ['label' => __('Approved'), 'val' => $stats['approved_meta']],
                    ['label' => __('Unofficial ready'), 'val' => $stats['baileys_ready']],
                ];
            @endphp
            @foreach ($kpis as $k)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                    <div class="text-[11px] text-ink-600">{{ $k['label'] }}</div>
                    <div class="font-serif text-[28px] leading-none mt-1">{{ number_format($k['val']) }}</div>
                </div>
            @endforeach
        </section>

        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-[12.5px]">
                    <thead class="bg-paper-50 text-left font-mono text-[10px] uppercase tracking-wider text-ink-500">
                        <tr>
                            <th class="px-4 py-2.5">{{ __('Workspace') }}</th>
                            <th class="px-4 py-2.5">{{ __('Sample') }}</th>
                            <th class="px-4 py-2.5">{{ __('Template') }}</th>
                            <th class="px-4 py-2.5">{{ __('Channel') }}</th>
                            <th class="px-4 py-2.5">{{ __('Stage') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php $stageKey = $row->lifecycle_stage ?? $lifecycle->templateStage($row); @endphp
                            <tr class="border-t border-paper-200 hover:bg-paper-50">
                                <td class="px-4 py-3">{{ $row->workspace?->name ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-[11px]">{{ $row->sourceSample?->slug ?? $row->source_sample_id }}</td>
                                <td class="px-4 py-3">{{ $row->template_name }}</td>
                                <td class="px-4 py-3 uppercase text-[11px]">{{ $row->channel ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $lifecycle->stageLabel($stageKey) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-ink-500">{{ __('No pushed installs yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="px-4 py-3 border-t border-paper-200">{{ $rows->links() }}</div>
            @endif
        </div>
    </main>
</x-layouts.admin>
