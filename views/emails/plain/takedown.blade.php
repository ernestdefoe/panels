<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-panels.email.takedown.'.$blueprint->what.'_plain', [
'{target}' => $blueprint->target,
'{url}' => $url->to('forum')->route('panels.copyright').'?counter='.$blueprint->takedownId,
]) !!}
</x-slot:body>
</x-mail::plain.notification>
