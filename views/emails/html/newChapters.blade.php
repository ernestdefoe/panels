<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-panels.email.new_chapters.html.body', [
            '{series}' => '**'.e($blueprint->series->title).'**',
            '{count}' => $blueprint->count,
            '{number}' => e($blueprint->number),
            '{url}' => $url->to('forum')->route('panels.reader', ['slug' => $blueprint->series->slug, 'chapter' => $blueprint->number]),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
