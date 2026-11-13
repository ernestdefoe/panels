<?php

/*
 * Panels: a manga, webtoon and comics library and reader for Flarum 2.
 *
 * Series with image chapters (uploaded pages or a .cbz/.zip), a phone-first reader (vertical
 * webtoon, page left-to-right, page right-to-left), reading progress, libraries and follows,
 * a real discussion for every chapter, ratings and age gating, a copyright takedown flow, and
 * widgets for fof/forum-widgets-core and Page Builder.
 *
 * Panels' pages read their own JSON endpoints (Api\Controller\*), which share one set of rules
 * (Access) and one set of shapes (Api\Presenter).
 *
 * 🚨 String literals only below, never a class constant read at load time: it autoloads during
 * boot and fatals every request while a deploy is swapping composer's autoload map. Constants
 * inside closures are fine.
 */

use ErnestDefoe\Panels\Access;
use ErnestDefoe\Panels\Api\Controller;
use ErnestDefoe\Panels\Http\ImageController;
use ErnestDefoe\Panels\Http\SeriesMeta;
use ErnestDefoe\Panels\Media\Store;
use ErnestDefoe\Panels\Notification;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

$extenders = [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The reader, the editor and the moderation pages are chunks in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/read', 'panels')
        ->route('/read/library', 'panels.library')
        ->route('/read/copyright', 'panels.copyright')
        ->route('/read/moderation', 'panels.moderation')
        ->route('/read/{slug}', 'panels.series', SeriesMeta::class)
        ->route('/read/{slug}/upload', 'panels.upload')
        ->route('/read/{slug}/upload/{id:\d+}', 'panels.edit')
        ->route('/read/{slug}/{chapter:\d+(?:\.\d+)?}', 'panels.reader', SeriesMeta::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-panels', __DIR__.'/views'),

    // Open chapters and covers: public static files. Everything else: outside the web root.
    (new Extend\Filesystem())
        ->disk('ernestdefoe-panels', [Store::class, 'publicConfig'])
        ->disk('ernestdefoe-panels-private', [Store::class, 'privateConfig']),

    // 🚨 No file extension at the end of this route: nginx would serve it from disk.
    (new Extend\Routes('forum'))
        ->get('/panels/img/{token}', 'panels.image', ImageController::class),

    (new Extend\Routes('api'))
        ->get('/panels/series', 'panels.series.index', Controller\SeriesListController::class)
        ->post('/panels/series', 'panels.series.create', Controller\SeriesSaveController::class)
        ->get('/panels/series/{slug}', 'panels.series.show', Controller\SeriesShowController::class)
        ->patch('/panels/series/{id:\d+}', 'panels.series.update', Controller\SeriesSaveController::class)
        ->post('/panels/series/{id:\d+}/cover', 'panels.series.cover', Controller\CoverController::class)
        ->post('/panels/series/{id:\d+}/library', 'panels.library.save', Controller\LibraryController::class)
        ->delete('/panels/series/{id:\d+}/library', 'panels.library.delete', Controller\LibraryController::class)
        ->post('/panels/series/{id:\d+}/rating', 'panels.rating', Controller\LibraryController::class)
        ->get('/panels/series/{slug}/chapters/{number}', 'panels.read.chapter', Controller\ReaderController::class)
        ->post('/panels/chapters', 'panels.chapters.create', Controller\ChapterController::class)
        ->get('/panels/chapters/{id:\d+}', 'panels.chapters.show', Controller\ChapterController::class)
        ->patch('/panels/chapters/{id:\d+}', 'panels.chapters.update', Controller\ChapterController::class)
        ->delete('/panels/chapters/{id:\d+}', 'panels.chapters.delete', Controller\ChapterController::class)
        ->post('/panels/chapters/{id:\d+}/pages', 'panels.pages.add', Controller\PagesController::class)
        ->patch('/panels/chapters/{id:\d+}/pages', 'panels.pages.order', Controller\PagesController::class)
        ->delete('/panels/pages/{id:\d+}', 'panels.pages.delete', Controller\PagesController::class)
        ->post('/panels/chapters/{id:\d+}/import', 'panels.import', Controller\PagesController::class)
        ->post('/panels/imports/{id:\d+}/step', 'panels.imports.step', Controller\PagesController::class)
        ->post('/panels/chapters/{id:\d+}/progress', 'panels.progress', Controller\ProgressController::class)
        ->post('/panels/chapters/{id:\d+}/read', 'panels.read', Controller\ProgressController::class)
        ->post('/panels/chapters/{id:\d+}/download', 'panels.download', Controller\DownloadController::class)
        ->post('/panels/progress/merge', 'panels.progress.merge', Controller\LibraryController::class)
        ->post('/panels/age', 'panels.age', Controller\LibraryController::class)
        ->get('/panels/feed', 'panels.feed', Controller\FeedController::class)
        ->get('/panels/queue', 'panels.queue', Controller\AdminController::class)
        ->get('/panels/genres', 'panels.genres', Controller\AdminController::class)
        ->post('/panels/genres', 'panels.genres.create', Controller\AdminController::class)
        ->patch('/panels/genres/{id:\d+}', 'panels.genres.update', Controller\AdminController::class)
        ->delete('/panels/genres/{id:\d+}', 'panels.genres.delete', Controller\AdminController::class)
        ->post('/panels/watermark', 'panels.watermark.save', Controller\AdminController::class)
        ->delete('/panels/watermark', 'panels.watermark.delete', Controller\AdminController::class)
        ->post('/panels/takedowns', 'panels.takedowns.create', Controller\TakedownController::class)
        ->get('/panels/takedowns', 'panels.takedowns', Controller\TakedownController::class)
        ->patch('/panels/takedowns/{id:\d+}', 'panels.takedowns.update', Controller\TakedownController::class)
        ->post('/panels/takedowns/{id:\d+}/counter', 'panels.takedowns.counter', Controller\TakedownController::class),

    // Notification subjects need a resource (an unregistered one 500s every member's notifications).
    new Extend\ApiResource(\ErnestDefoe\Panels\Api\Resource\SeriesResource::class),

    (new Extend\Notification())
        ->type(Notification\NewChaptersBlueprint::class, ['alert', 'email'])
        ->type(Notification\TakedownBlueprint::class, ['alert', 'email']),

    // A chapter's discussion follows the chapter's visibility.
    (new Extend\ModelVisibility(Discussion::class))
        ->scope(\ErnestDefoe\Panels\Scope\ChapterDiscussions::class),

    // The chapter card above a chapter discussion's first post: on the discussion page only, so
    // discussion lists pay nothing.
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Arr::make('panelsChapter')
                ->nullable()
                ->visible(fn (Discussion $d, Context $context) => ($context->endpoint ?? null) instanceof \Flarum\Api\Endpoint\Show)
                ->get(fn (Discussion $d, Context $context) => resolve(\ErnestDefoe\Panels\DiscussionCard::class)->for($d, $context->getActor())),
        ]),

    // What the viewer may do (their groups are already loaded: no queries), and the moderation count (moderators only).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('panelsCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('panels.view')),
            Schema\Boolean::make('panelsCanCreate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('panels.create')),
            Schema\Boolean::make('panelsCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('panels.moderate')),
            Schema\Boolean::make('panelsAdult')
                ->get(fn ($forum, Context $context) => resolve(Access::class)->isAdult($context->getActor())),
            Schema\Boolean::make('panelsKiosk')
                ->get(fn () => resolve(Access::class)->kiosk()),
            Schema\Integer::make('panelsQueueCount')
                ->visible(fn ($forum, Context $context) => $context->getActor()->hasPermission('panels.moderate'))
                ->get(fn () => \ErnestDefoe\Panels\Model\Chapter::query()->whereIn('state', ['pending', 'held'])->count()
                    + \ErnestDefoe\Panels\Model\Takedown::query()->where('state', 'open')->count()),
            Schema\Arr::make('panelsFields')
                ->visible(fn ($forum, Context $context) => $context->getActor()->hasPermission('panels.create'))
                ->get(fn () => resolve(\ErnestDefoe\Panels\Settings::class)->fields()),
        ]),

    (new Extend\Settings())
        ->default('ernestdefoe-panels.label_singular', '')
        ->default('ernestdefoe-panels.label_plural', '')
        ->default('ernestdefoe-panels.tag_id', 0)
        ->default('ernestdefoe-panels.approve_members', true)
        ->default('ernestdefoe-panels.explicit', false)
        ->default('ernestdefoe-panels.mature_min_days', 0)
        ->default('ernestdefoe-panels.mature_min_posts', 0)
        ->default('ernestdefoe-panels.max_image_mb', 20)
        ->default('ernestdefoe-panels.max_megapixels', 60)
        ->default('ernestdefoe-panels.max_pages', 400)
        ->default('ernestdefoe-panels.max_chapter_mb', 500)
        ->default('ernestdefoe-panels.max_archive_mb', 500)
        ->default('ernestdefoe-panels.quality', 80)
        ->default('ernestdefoe-panels.pages_per_hour', 600)
        ->default('ernestdefoe-panels.strip_width', 800)
        ->default('ernestdefoe-panels.watermark', false)
        ->default('ernestdefoe-panels.watermark_position', 'br')
        ->default('ernestdefoe-panels.watermark_opacity', 40)
        ->default('ernestdefoe-panels.watermark_size', 18)
        ->default('ernestdefoe-panels.download_limits', '{}')
        ->default('ernestdefoe-panels.strike_limit', 3)
        ->default('ernestdefoe-panels.quarantine_days', 30)
        ->default('ernestdefoe-panels.x_sendfile', '')
        ->default('ernestdefoe-panels.agent_name', '')
        ->default('ernestdefoe-panels.agent_address', '')
        ->default('ernestdefoe-panels.agent_email', '')
        ->default('ernestdefoe-panels.stock_sidebar', false)
        ->serializeToForum('panelsLabelSingular', 'ernestdefoe-panels.label_singular')
        ->serializeToForum('panelsLabelPlural', 'ernestdefoe-panels.label_plural')
        ->serializeToForum('panelsExplicit', 'ernestdefoe-panels.explicit', 'boolval')
        ->serializeToForum('panelsMaxImageMb', 'ernestdefoe-panels.max_image_mb', 'intval')
        ->serializeToForum('panelsMaxArchiveMb', 'ernestdefoe-panels.max_archive_mb', 'intval')
        ->serializeToForum('panelsMaxPages', 'ernestdefoe-panels.max_pages', 'intval')
        ->serializeToForum('panelsStripWidth', 'ernestdefoe-panels.strip_width', 'intval')
        ->serializeToForum('panelsAgentName', 'ernestdefoe-panels.agent_name')
        ->serializeToForum('panelsAgentAddress', 'ernestdefoe-panels.agent_address')
        ->serializeToForum('panelsAgentEmail', 'ernestdefoe-panels.agent_email')
        ->serializeToForum('panelsStockSidebar', 'ernestdefoe-panels.stock_sidebar', 'boolval'),

    (new Extend\Event())
        // A deleted chapter discussion: the chapter keeps going, without one.
        ->listen(\Flarum\Discussion\Event\Deleted::class, function ($event) {
            \ErnestDefoe\Panels\Model\Chapter::query()->where('discussion_id', $event->discussion->id)->update(['discussion_id' => null]);
        })
        // The stats widget counts what guests see; forget it when anything is published.
        ->listen(\ErnestDefoe\Panels\Event\ChapterPublished::class, fn () => resolve(\Illuminate\Contracts\Cache\Repository::class)->forget('ernestdefoe-panels.stats.m'))
        ->listen(\ErnestDefoe\Panels\Event\ChapterRemoved::class, fn () => resolve(\Illuminate\Contracts\Cache\Repository::class)->forget('ernestdefoe-panels.stats.m')),

    (new Extend\Console())
        ->command(\ErnestDefoe\Panels\Console\TickCommand::class)
        ->schedule(\ErnestDefoe\Panels\Console\TickCommand::class, function (ScheduledEvent $event) {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Panels\Gdpr\PanelsData::class),
        ]),

    // Sell series and chapters (paid, or early access) through Kiosk.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-kiosk', fn () => [
            new \Ernestdefoe\Kiosk\Extend\UnlockType('panels-series', \ErnestDefoe\Panels\Kiosk\SeriesUnlock::class),
            new \Ernestdefoe\Kiosk\Extend\UnlockType('panels-chapter', \ErnestDefoe\Panels\Kiosk\ChapterUnlock::class),
        ]),
];

// Page Builder blocks only offer the widgets in its editor; the client fetches like every other host.
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    foreach (['NewChapters', 'ContinueReading', 'Trending', 'Spotlight', 'Stats'] as $block) {
        $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock('ErnestDefoe\\Panels\\Widget\\'.$block.'Block');
    }
}

return $extenders;
