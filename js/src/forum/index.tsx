import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import PanelsWidget from './widgets/Widget';
import { placedInFof, registerFof, registerPageBuilder } from './widgets/hosts';
import { type GuestPlace, label, long, readerHref, seriesHref, store, t, tx , icon } from './util';

export { default as PanelsWidget } from './widgets/Widget';

/*
 * forum.js wires things up and carries the widgets (they render on ordinary pages) and the chapter
 * card on chapter discussions. Every Panels page is one chunk (./pages), fetched on the first visit.
 */

const pages = () => import('./pages');
const route = (path: string, name: 'LibraryPage' | 'SeriesPage' | 'ReaderPage' | 'EditorPage' | 'ModerationPage' | 'CopyrightPage') => ({
  path,
  component: () => pages().then(async (chunk) => (await chunk.loadCore(), { default: chunk[name] })),
});

function notification(type: 'panelsNewChapters' | 'panelsTakedown') {
  return class extends Notification {
    icon() {
      return type === 'panelsNewChapters' ? 'fas fa-book-open' : 'fas fa-scale-balanced';
    }
    href() {
      const n: any = this.attrs.notification;
      const series = n.subject();
      const data = n.content() || {};
      if (type === 'panelsTakedown') return app.route('panels.copyright') + (data.what === 'disabled' ? `?counter=${data.takedownId}` : '');
      return series ? readerHref(series.attribute('slug'), String(data.number)) : app.route('panels');
    }
    content() {
      const n: any = this.attrs.notification;
      const data = n.content() || {};
      const title = n.subject()?.attribute('title') ?? '';
      if (type === 'panelsTakedown') return t(`notification.takedown_${data.what}`, { target: data.target });
      return t('notification.new_chapters', { count: data.count || 1, series: title, number: data.number });
    }
    excerpt() {
      return null;
    }
  };
}

app.initializers.add('ernestdefoe-panels', () => {
  // Queued for Page Builder now: it picks them up whenever it looks, whichever of us starts first.
  try {
    registerPageBuilder();
  } catch (e) {
    console.warn('[panels] Page Builder registration failed', e);
  }

  app.routes['panels'] = route('/read', 'LibraryPage');
  app.routes['panels.library'] = route('/read/library', 'LibraryPage');
  app.routes['panels.copyright'] = route('/read/copyright', 'CopyrightPage');
  app.routes['panels.moderation'] = route('/read/moderation', 'ModerationPage');
  app.routes['panels.series'] = route('/read/:slug', 'SeriesPage');
  app.routes['panels.upload'] = route('/read/:slug/upload', 'EditorPage');
  app.routes['panels.edit'] = route('/read/:slug/upload/:id', 'EditorPage');
  app.routes['panels.reader'] = route('/read/:slug/:chapter', 'ReaderPage');

  app.notificationComponents.panelsNewChapters = notification('panelsNewChapters');
  app.notificationComponents.panelsTakedown = notification('panelsTakedown');

  // String form: these live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('panelsNewChapters', { name: 'panelsNewChapters', icon: 'fas fa-book-open', label: t('notification.new_chapters_preference') });
    if (app.forum.attribute('panelsCanCreate')) {
      items.add('panelsTakedown', { name: 'panelsTakedown', icon: 'fas fa-scale-balanced', label: t('notification.takedown_preference') });
    }
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('panelsCanView')) return;
    items.add('panels', LinkButton.component({ href: app.route('panels'), icon: 'fas fa-book-open' }, label()), 85);
    if (app.session.user) items.add('panelsLibrary', LinkButton.component({ href: app.route('panels.library'), icon: 'fas fa-bookmark' }, t('library.title')), 84);
  });

  // The optional stock spot: New chapters in the index sidebar, for forums without a widget host.
  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('panelsStockSidebar') || !app.forum.attribute('panelsCanView') || placedInFof('new')) return;
    if (String(app.current.get('routeName') || '').startsWith('panels')) return;
    items.add('panelsNew', <PanelsWidget kind="new" host="stock-sidebar" settings={{ count: 5 }} />, -60);
  });

  // The chapter bar under a chapter discussion's title: which series and chapter, and a way in.
  extend('flarum/forum/components/DiscussionHero', 'items', function (this: any, items: ItemList<Mithril.Children>) {
    const card: any = this.attrs.discussion?.attribute?.('panelsChapter');
    if (!card) return;
    const s = card.series;
    const c = card.chapter;
    items.add(
      'panelsChapter',
      <div className="PanelsHeroBar">
        {s.cover ? <img className={'PanelsHeroBar-cover' + (s.cover.blurred ? ' is-blurred' : '')} src={s.cover.small} alt="" /> : null}
        <span className="PanelsHeroBar-text">
          <Link href={seriesHref(s.slug)}>{s.title}</Link>
          <span>
            {long(s.format, c.number)} · {tx('pages', { count: c.pageCount })}
            {c.read ? [' · ', icon('fas fa-check'), ' ', tx('read')] : null}
          </span>
        </span>
        <span className="PanelsHeroBar-actions">
          {card.prev ? (
            <Link className="Button Button--icon" href={readerHref(s.slug, card.prev.number)} aria-label={tx('reader.prev_chapter')}>
              {icon('fas fa-chevron-left')}
            </Link>
          ) : null}
          <Link className="Button Button--primary" href={readerHref(s.slug, c.number)}>
            {c.locked ? icon('fas fa-lock', { className: 'Button-icon' }) : null}
            {t('read_this', { chapter: long(s.format, c.number) })}
          </Link>
          {card.next ? (
            <Link className="Button Button--icon" href={readerHref(s.slug, card.next.number)} aria-label={tx('reader.next_chapter')}>
              {icon('fas fa-chevron-right')}
            </Link>
          ) : null}
        </span>
      </div>,
      -10
    );
  });

  // A guest's reading places, offered to their account once they sign in.
  extend(app, 'mount', function () {
    if (!app.session.user) return;
    const places = store.get<GuestPlace[]>('places', []);
    if (!places.length) return;
    store.remove('places');
    app
      .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/panels/progress/merge`, body: { places }, errorHandler: () => {} } as any)
      .catch(() => {});
  });

  // After every initializer, before the first draw: widgets-core reads its registry then.
  override(app, 'mount', function (original: (...args: any[]) => unknown, ...args: any[]) {
    try {
      registerFof();
    } catch (e) {
      // A host whose internals moved must not take the forum's JS down with it.
      console.warn('[panels] widget registration failed', e);
    }
    return original(...args);
  });
});
