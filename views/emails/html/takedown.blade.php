{{-- The claimant's words never go through Markdown: the claim is linked, not quoted. --}}
<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-panels.email.takedown.'.$blueprint->what.'_html', [
            '{target}' => e($blueprint->target),
            '{url}' => $url->to('forum')->route('panels.copyright').'?counter='.$blueprint->takedownId,
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
