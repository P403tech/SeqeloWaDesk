<x-layouts.admin :title="__('Push flow template')" admin-key="flow-templates" page="admin-flow-templates-push">

    <main class="px-4 sm:px-7 py-7">
        <h1 class="font-serif text-[26px] mb-1">{{ __('Push') }} <span class="italic text-wa-deep">{{ $template->name }}</span></h1>
        <p class="text-[13px] text-ink-600 mb-6 max-w-xl">{{ __('Installs an unpublished draft flow. Published customer copies are left alone.') }}</p>

        @include('admin.partials.push-workspaces-picker', [
            'formAction' => route('admin.flow-templates.push', $template->id),
            'workspaces' => $workspaces,
        ])
    </main>
</x-layouts.admin>
