<x-layouts.admin :title="__('Push sample')" admin-key="template-samples" page="admin-template-samples-push">

    <main class="px-4 sm:px-7 py-7">
        <h1 class="font-serif text-[26px] mb-1">{{ __('Push') }} <span class="italic text-wa-deep">{{ $sample->title }}</span></h1>
        <p class="text-[13px] text-ink-600 mb-6 max-w-xl">{{ __('Installs this sample as a real template. Cloud API customers still submit to Meta on their own number.') }}</p>

        @include('admin.partials.push-workspaces-picker', [
            'formAction' => route('admin.template-samples.push', $sample->id),
            'workspaces' => $workspaces,
        ])
    </main>
</x-layouts.admin>
