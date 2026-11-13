import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { type SeriesCard, get, label, t, tx, icon } from '../util';
import { ContinueCard, Select, Tile } from './bits';
import { IndexSidebar, PageStructure } from './core';
import { openSeriesModal } from './SeriesModal';

const FORMATS = ['manga', 'webtoon', 'comic', 'manhwa', 'manhua'];
const SORTS = ['latest', 'new', 'trending', 'rating', 'followers', 'title'];
export const SHELVES = ['reading', 'planned', 'hold', 'completed', 'dropped', 'following'];

/**
 * /read: every series the member may see, filtered and sorted, with their series in progress on
 * top. /read/library: the member's own shelves. Built in core's page structure with the forum's
 * sidebar, so any theme dresses it.
 */
export default class LibraryPage extends Page {
  shelfMode = false;
  shelf = 'reading';
  q = '';
  format = '';
  status = '';
  genre = '';
  sort = 'latest';
  rows: SeriesCard[] = [];
  cont: SeriesCard[] = [];
  genres: Array<{ slug: string; name: string }> = [];
  stats: { series: number; chapters: number } | null = null;
  page = 1;
  more = false;
  loading = true;
  private ticket = 0;
  private typing: any = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.shelfMode = app.current.get('routeName') === 'panels.library';
    this.bodyClass = 'App--panels';
    const p = (k: string) => String(m.route.param(k) || '');
    this.q = p('q');
    this.format = FORMATS.includes(p('format')) ? p('format') : '';
    this.genre = p('genre');
    this.sort = SORTS.includes(p('sort')) ? p('sort') : 'latest';
    this.shelf = SHELVES.includes(p('shelf')) ? p('shelf') : 'reading';

    const title = this.shelfMode ? tx('library.title') : label();
    app.history.push(this.shelfMode ? 'panels.library' : 'panels', title);
    app.setTitle(title);

    if (this.shelfMode && !app.session.user) {
      m.route.set(app.route('panels'));
      return;
    }

    if (!this.shelfMode) {
      get('genres', {}, true)
        .then((r) => (this.genres = r.data))
        .then(
          () => m.redraw(),
          () => {}
        );
      get('feed', { kind: 'stats' }, true)
        .then((r) => (this.stats = r.data))
        .then(
          () => m.redraw(),
          () => {}
        );
      if (app.session.user)
        get('feed', { kind: 'continue', limit: 3 }, true)
          .then((r) => (this.cont = r.data))
          .then(
            () => m.redraw(),
            () => {}
          );
    }
    this.load(1);
  }

  load(page: number): void {
    const ticket = ++this.ticket;
    this.loading = true;
    const filter: Record<string, string> = {};
    if (this.shelfMode) filter.shelf = this.shelf;
    if (this.q) filter.q = this.q;
    if (this.format) filter.format = this.format;
    if (this.status) filter.status = this.status;
    if (this.genre) filter.genre = this.genre;
    get('series', { filter, sort: this.sort, page, limit: 24 })
      .then((r) => {
        if (ticket !== this.ticket) return;
        this.rows = page === 1 ? r.data : [...this.rows, ...r.data];
        this.more = r.hasMore;
        this.page = page;
      })
      .catch(() => {})
      .finally(() => {
        if (ticket !== this.ticket) return;
        this.loading = false;
        m.redraw();
      });
  }

  set(key: 'format' | 'status' | 'genre' | 'sort' | 'shelf', value: string): void {
    (this as any)[key] = value;
    this.load(1);
  }

  view() {
    return (
      <PageStructure className="PanelsLibrary" sidebar={() => <IndexSidebar />}>
        {this.header()}
        {this.shelfMode ? this.shelfTabs() : this.toolbar()}
        {!this.shelfMode && this.cont.length ? (
          <section className="PanelsLibrary-section" aria-labelledby="panels-continue">
            <div className="PanelsLibrary-sectionHead">
              <h2 id="panels-continue">{t('continue_reading')}</h2>
              <Link href={app.route('panels.library')}>{t('library.title')}</Link>
            </div>
            <div className="PanelsLibrary-continue">
              {this.cont.map((s) => (
                <ContinueCard series={s} />
              ))}
            </div>
          </section>
        ) : null}
        <section className="PanelsLibrary-section" aria-labelledby="panels-grid">
          {!this.shelfMode ? (
            <div className="PanelsLibrary-sectionHead">
              <h2 id="panels-grid">{t(`sort.${this.sort}_heading`)}</h2>
            </div>
          ) : (
            <h2 id="panels-grid" className="visually-hidden">
              {t(`library.${this.shelf}`)}
            </h2>
          )}
          {this.rows.length ? (
            <div className="PanelsGrid">
              {this.rows.map((s) => (
                <Tile series={s} />
              ))}
            </div>
          ) : this.loading ? null : (
            <p className="PanelsEmpty">
              {this.shelfMode ? t('library.empty') : this.q || this.genre || this.format ? t('empty_filtered') : t('empty')}
            </p>
          )}
          {this.loading ? <LoadingIndicator /> : null}
          {this.more && !this.loading ? (
            <div className="PanelsMore">
              <Button className="Button" onclick={() => this.load(this.page + 1)}>
                {t('load_more')}
              </Button>
            </div>
          ) : null}
        </section>
        <p className="PanelsFoot">
          <Link href={app.route('panels.copyright')}>{t('copyright.link')}</Link>
        </p>
      </PageStructure>
    );
  }

  header(): Mithril.Children {
    const queue = Number(app.forum.attribute('panelsQueueCount') || 0);
    return (
      <header className="PanelsLibrary-head">
        <div>
          <h1>{this.shelfMode ? t('library.title') : label()}</h1>
          {!this.shelfMode && this.stats ? (
            <p className="PanelsMuted">{t('stats_line', { series: this.stats.series, chapters: this.stats.chapters })}</p>
          ) : null}
        </div>
        <div className="PanelsLibrary-actions">
          {app.forum.attribute('panelsCanModerate') ? (
            <Link className="Button" href={app.route('panels.moderation')}>
              {icon('fas fa-inbox', { className: 'Button-icon' })}
              <span className="Button-label">
                {t('moderation.title')}
                {queue ? <span className="PanelsBadge PanelsBadge--count">{queue}</span> : null}
              </span>
            </Link>
          ) : null}
          {app.forum.attribute('panelsCanCreate') ? (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openSeriesModal()}>
              {t('start_series')}
            </Button>
          ) : null}
        </div>
      </header>
    );
  }

  toolbar(): Mithril.Children {
    return (
      <div className="PanelsToolbar">
        <label className="PanelsSearch">
          {icon('fas fa-search')}
          <span className="visually-hidden">{tx('search')}</span>
          <input
            className="FormControl"
            type="search"
            placeholder={tx('search')}
            value={this.q}
            oninput={(e: InputEvent) => {
              this.q = (e.target as HTMLInputElement).value;
              clearTimeout(this.typing);
              this.typing = setTimeout(() => this.load(1), 300);
            }}
          />
        </label>
        <div className="PanelsChips" role="group" aria-label={tx('format_label')}>
          {['', ...FORMATS].map((f) => (
            <button
              type="button"
              className={'PanelsChip' + (this.format === f ? ' is-on' : '')}
              aria-pressed={this.format === f ? 'true' : 'false'}
              onclick={() => this.set('format', f)}
            >
              {f ? tx(`format.${f}`) : tx('all')}
            </button>
          ))}
        </div>
        <div className="PanelsFilters">
          <Select
            label={tx('status_label')}
            value={this.status}
            options={[
              ['', tx('status.any')],
              ...['ongoing', 'completed', 'hiatus', 'cancelled'].map((s) => [s, tx(`status.${s}`)] as [string, string]),
            ]}
            onchange={(v: string) => this.set('status', v)}
          />
          <Select
            label={tx('genre_label')}
            value={this.genre}
            options={[['', tx('genre_any')], ...this.genres.map((g) => [g.slug, g.name] as [string, string])]}
            onchange={(v: string) => this.set('genre', v)}
          />
          <Select
            label={tx('sort_label')}
            value={this.sort}
            options={SORTS.map((s) => [s, tx(`sort.${s}`)] as [string, string])}
            onchange={(v: string) => this.set('sort', v)}
          />
        </div>
      </div>
    );
  }

  shelfTabs(): Mithril.Children {
    return (
      <div className="PanelsChips PanelsChips--tabs" role="tablist">
        {SHELVES.map((s) => (
          <button
            type="button"
            role="tab"
            aria-selected={this.shelf === s ? 'true' : 'false'}
            className={'PanelsChip' + (this.shelf === s ? ' is-on' : '')}
            onclick={() => this.set('shelf', s)}
          >
            {t(`library.${s}`)}
          </button>
        ))}
      </div>
    );
  }
}
