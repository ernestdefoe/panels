<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-panels.email.new_chapters.plain.body', [
'{series}' => $blueprint->series->title,
'{count}' => $blueprint->count,
'{number}' => $blueprint->number,
'{url}' => $url->to('forum')->route('panels.reader', ['slug' => $blueprint->series->slug, 'chapter' => $blueprint->number]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
