<?php

namespace ErnestDefoe\Panels\Http;

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Presenter;
use ErnestDefoe\Panels\Model\Series;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /read/{slug} and its chapters as the server sends them, for crawlers and link previews: the
 * title, description, canonical URL, cover image and schema.org ComicSeries data.
 *
 * Only for series anyone may read (All ages or Teen). Mature and Explicit series get a plain
 * title, no image and noindex; one a visitor may not see gets nothing at all.
 */
class SeriesMeta
{
    public function __construct(
        private readonly Access $access,
        private readonly Presenter $presenter,
        private readonly UrlGenerator $url,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $params = $request->getQueryParams();
        $slug = (string) ($request->getAttribute('routeParameters')['slug'] ?? $params['slug'] ?? '');
        $series = Series::query()->where('slug', $slug)->first();
        $actor = RequestUtil::getActor($request);
        if (! $series || ! $this->access->canSeeSeries($series, $actor)) {
            return $document;
        }

        $document->title = $series->title;
        $canonical = $this->url->to('forum')->route('panels.series', ['slug' => $series->slug]);
        $document->canonicalUrl = $canonical;

        if (! in_array($series->content_rating, ['all', 'teen'], true) || $series->is_hidden) {
            $document->meta['robots'] = 'noindex';

            return $document;
        }

        $description = mb_substr(trim(preg_replace('~\s+~', ' ', (string) $series->description) ?? ''), 0, 200);
        if ($description !== '') {
            $document->meta['description'] = $description;
        }
        $cover = $this->presenter->cover($series, $actor);
        if ($cover) {
            $document->head[] = '<meta property="og:image" content="'.e($cover['large']).'">';
        }
        $document->head[] = '<meta property="og:title" content="'.e($series->title).'">';
        $document->head[] = '<script type="application/ld+json">'.json_encode(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ComicSeries',
            'name' => $series->title,
            'url' => $canonical,
            'description' => $description ?: null,
            'image' => $cover['large'] ?? null,
            'alternateName' => $series->altTitles() ?: null,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>';

        return $document;
    }
}
