import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { type SeriesCard, get, label, long, readerHref, send, short, t, tx , icon } from '../util';
import { CoverArt, Select, StarPicker, confirmAdult } from './bits';
import { PageStructure } from './core';
import { openSeriesModal } from './SeriesModal';

export interface ChapterRow {
  id: number;
  number: string;
  volume: number | null;
  title: string | null;
  state: string;
  scheduled: boolean;
  publishedAt: string | null;
  pageCount: number;
  source: string;
  access: string;
  locked: boolean;
  replies: number;
  read: boolean;
  discussionId: number | null;
}

export interface SeriesFull extends SeriesCard {
  altTitles: string[];
  description: string | null;
  readingMode: string;
  fields: Array<{ id: number; name: string; type: string; value: string }>;
  rawFields: Record<string, string> | null;
  owner: { username: string; displayName: string; avatarUrl: string | null } | null;
  editors: Array<{ username: string; displayName: string }>;
  library: { shelf: string | null; notify: boolean } | null;
  myRating: number | null;
  canEdit: boolean;
  canModerate: boolean;
  canRate: boolean;
  chapters: ChapterRow[];
  tagId: number | null;
  isSold: boolean;
}

const SHELVES = ['reading', 'planned', 'hold', 'completed', 'dropped'];

/**
 * /read/{slug}: the cover and details, the member's place and shelf, and the chapters
 * (grouped by volume, newest first), each with its read mark and reply count.
 */
export default class SeriesPage extends Page {
  series: SeriesFull | null = null;
  loading = true;
  missing = false;
  asc = false;
  more = false;
  chapterPage = 1;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--panels';
    this.load();
  }

  load(): void {
    get<{ data: SeriesFull; hasMoreChapters: boolean }>(`series/${encodeURIComponent(m.route.param('slug'))}`, { order: this.asc ? 'asc' : 'desc' }, true)
      .then((r) => {
        this.series = r.data;
        this.more = r.hasMoreChapters;
        this.chapterPage = 1;
        app.history.push('panels.series', r.data.title);
        app.setTitle(r.data.title);
      })
      .catch(() => (this.missing = true))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  loadMore(): void {
    const s = this.series!;
    get<{ data: SeriesFull; hasMoreChapters: boolean }>(`series/${s.slug}`, { order: this.asc ? 'asc' : 'desc', page: this.chapterPage + 1 }).then((r) => {
      s.chapters = [...s.chapters, ...r.data.chapters];
      this.more = r.hasMoreChapters;
      this.chapterPage++;
      m.redraw();
    });
  }

  view() {
    if (this.loading) return <PageStructure className="PanelsSeries">{<LoadingIndicator />}</PageStructure>;
    if (this.missing || !this.series) {
      return (
        <PageStructure className="PanelsSeries">
          <p className="PanelsEmpty">{t('error.not_found')}</p>
        </PageStructure>
      );
    }
    const s = this.series;
    return (
      <PageStructure className="PanelsSeries">
        <nav className="PanelsCrumbs" aria-label={tx('breadcrumb')}>
          <Link href={app.route('panels')}>{label()}</Link> › <span>{s.title}</span>
        </nav>
        {s.isRemoved ? <div className="PanelsNotice PanelsNotice--danger">{t('series.removed')}</div> : null}
        {s.isHidden ? <div className="PanelsNotice">{t('series.hidden')}</div> : null}
        {this.hero(s)}
        <div className="PanelsSeries-body">
          <div className="PanelsSeries-main">{this.chapters(s)}</div>
          <aside className="PanelsSeries-aside">{this.aside(s)}</aside>
        </div>
      </PageStructure>
    );
  }

  /** Where "Start reading" or "Continue" goes. */
  target(s: SeriesFull): { number: string; label: string } | null {
    if (s.progress) return { number: s.progress.number, label: tx('continue_at', { chapter: short(s.format, s.progress.number), page: s.progress.page }) };
    const readable = s.chapters.filter((c) => c.state === 'published' && !c.scheduled);
    const first = readable.reduce<ChapterRow | null>((min, c) => (!min || parseFloat(c.number) < parseFloat(min.number) ? c : min), null);
    return first ? { number: first.number, label: tx('start_reading') } : null;
  }

  hero(s: SeriesFull): Mithril.Children {
    const go = this.target(s);
    const mature = s.contentRating === 'mature' && !app.forum.attribute('panelsAdult');
    return (
      <section className="PanelsHero">
        <span className="PanelsHero-cover">
          <CoverArt cover={s.cover} color={s.coverColor} title={s.title} size="large" />
        </span>
        <div className="PanelsHero-info">
          <div className="PanelsHero-chips">
            <span className="PanelsBadge PanelsBadge--soft">{[tx(`format.${s.format}`), ' · ', tx(`mode.${s.readingMode}_short`)]}</span>
            <span className={`PanelsBadge PanelsBadge--status PanelsBadge--${s.status}`}>{tx(`status.${s.status}`)}</span>
            <span className="PanelsBadge PanelsBadge--soft">{tx(`rating.${s.contentRating}`)}</span>
          </div>
          <h1 className="PanelsHero-title">{s.title}</h1>
          {s.altTitles.length ? <p className="PanelsMuted">{t('also_known_as', { names: s.altTitles.join(' · ') })}</p> : null}
          <p className="PanelsHero-stats">
            {s.rating ? <span className="PanelsTile-rating">★ {s.rating.toFixed(1)}</span> : null}
            {s.ratingCount ? <span>{t('ratings', { count: s.ratingCount })}</span> : null}
            <span>{t('following_count', { count: s.followerCount })}</span>
            <span>{t('chapters_count', { count: s.chapterCount })}</span>
          </p>
          {s.genres.length ? (
            <div className="PanelsHero-genres">
              {s.genres.map((g) => (
                <Link className="PanelsChip PanelsChip--small" href={app.route('panels', { genre: g.slug })}>
                  {g.name}
                </Link>
              ))}
            </div>
          ) : null}
          {mature ? (
            <div className="PanelsNotice">
              {t('age.notice')}{' '}
              <Button className="Button Button--link" onclick={() => confirmAdult(() => this.load())}>
                {t('age.button')}
              </Button>
            </div>
          ) : null}
          <div className="PanelsHero-actions">
            {go ? (
              <Link className="Button Button--primary PanelsHero-read" href={readerHref(s.slug, go.number)}>
                {go.label}
              </Link>
            ) : null}
            {app.session.user ? this.libraryControls(s) : null}
            {s.canEdit ? (
              <>
                <Link className="Button" href={app.route('panels.upload', { slug: s.slug })}>
                  {icon('fas fa-upload', { className: 'Button-icon' })}
                  <span className="Button-label">{t('upload.add_chapter')}</span>
                </Link>
                <Button className="Button" icon="fas fa-pen" onclick={() => openSeriesModal(s, () => this.load())}>
                  {t('series.edit')}
                </Button>
              </>
            ) : null}
            {s.canModerate ? this.staffControls(s) : null}
          </div>
          {s.description ? <p className="PanelsHero-description">{s.description}</p> : null}
        </div>
      </section>
    );
  }

  libraryControls(s: SeriesFull): Mithril.Children {
    const save = (body: Record<string, unknown>) =>
      send<any>('POST', `series/${s.id}/library`, body).then((r) => {
        s.library = r.library;
        s.followerCount = r.followerCount;
        m.redraw();
      });
    return (
      <>
        <Select
          className="PanelsShelf"
          label={tx('shelf.label')}
          value={s.library?.shelf || ''}
          options={[['', tx('shelf.none')], ...SHELVES.map((v) => [v, tx(`library.${v}`)] as [string, string])]}
          onchange={(v: string) => save({ shelf: v || null })}
        />
        <Button
          className={'Button' + (s.library?.notify ? ' PanelsFollowing' : '')}
          icon={s.library?.notify ? 'fas fa-bell' : 'far fa-bell'}
          aria-pressed={s.library?.notify ? 'true' : 'false'}
          onclick={() => save({ notify: !s.library?.notify })}
        >
          {s.library?.notify ? t('following') : t('follow')}
        </Button>
        {s.canRate ? (
          <span className="PanelsHero-rate">
            <StarPicker
              value={s.myRating}
              onchange={(v: number | null) =>
                send<any>('POST', `series/${s.id}/rating`, { rating: v }).then((r) => {
                  s.myRating = r.myRating;
                  s.rating = r.rating;
                  s.ratingCount = r.ratingCount;
                  m.redraw();
                })
              }
            />
          </span>
        ) : null}
      </>
    );
  }

  staffControls(s: SeriesFull): Mithril.Children {
    const patch = (body: Record<string, unknown>) => send('PATCH', `series/${s.id}`, body).then(() => this.load());
    return (
      <span className="PanelsStaff">
        <Button className="Button" icon={s.isFeatured ? 'fas fa-star' : 'far fa-star'} onclick={() => patch({ isFeatured: !s.isFeatured })}>
          {s.isFeatured ? t('series.unfeature') : t('series.feature')}
        </Button>
        <Button className="Button" icon={s.isHidden ? 'fas fa-eye' : 'fas fa-eye-slash'} onclick={() => patch({ isHidden: !s.isHidden })}>
          {s.isHidden ? t('series.unhide') : t('series.hide')}
        </Button>
        <Button
          className="Button Button--danger"
          icon={s.isRemoved ? 'fas fa-rotate-left' : 'fas fa-trash'}
          onclick={() => (s.isRemoved || window.confirm(tx('series.remove_confirm'))) && patch({ isRemoved: !s.isRemoved })}
        >
          {s.isRemoved ? t('series.restore') : t('series.remove')}
        </Button>
      </span>
    );
  }

  chapters(s: SeriesFull): Mithril.Children {
    const groups: Array<{ volume: number | null; rows: ChapterRow[] }> = [];
    s.chapters.forEach((c) => {
      const last = groups[groups.length - 1];
      if (last && last.volume === c.volume) last.rows.push(c);
      else groups.push({ volume: c.volume, rows: [c] });
    });
    return (
      <section className="PanelsChapters" aria-labelledby="panels-chapters">
        <div className="PanelsChapters-head">
          <h2 id="panels-chapters">{t('chapters_heading', { count: s.chapterCount })}</h2>
          <span className="PanelsChapters-tools">
            <Button
              className="Button Button--link"
              onclick={() => {
                this.asc = !this.asc;
                this.load();
              }}
            >
              {this.asc ? t('oldest_first') : t('newest_first')}
            </Button>
            {app.session.user && s.chapters.length ? (
              <Button
                className="Button Button--link"
                onclick={() => {
                  const top = s.chapters.reduce((a, c) => (parseFloat(c.number) > parseFloat(a.number) ? c : a));
                  send('POST', `chapters/${top.id}/read`, { read: true, upTo: true }).then(() => this.load());
                }}
              >
                {t('mark_all_read')}
              </Button>
            ) : null}
          </span>
        </div>
        {s.chapters.length ? null : <p className="PanelsEmpty">{s.canEdit ? t('series.no_chapters_owner') : t('series.no_chapters')}</p>}
        {groups.map((g) => (
          <div className="PanelsChapters-group">
            {g.volume ? <h3 className="PanelsChapters-volume">{t('volume', { number: g.volume })}</h3> : null}
            <ul className="PanelsChapters-list">{g.rows.map((c) => this.row(s, c))}</ul>
          </div>
        ))}
        {this.more ? (
          <div className="PanelsMore">
            <Button className="Button" onclick={() => this.loadMore()}>
              {t('load_more')}
            </Button>
          </div>
        ) : null}
      </section>
    );
  }

  row(s: SeriesFull, c: ChapterRow): Mithril.Children {
    const isNew = !c.read && !!c.publishedAt && Date.now() - Date.parse(c.publishedAt) < 7 * 86400 * 1000 && c.state === 'published';
    const href =
      c.state === 'draft' || c.state === 'pending' || c.state === 'held' ? app.route('panels.edit', { slug: s.slug, id: c.id }) : readerHref(s.slug, c.number);
    const progress = s.progress && String(s.progress.chapter_id) === String(c.id) ? s.progress : null;
    return (
      <li className={'PanelsChapter' + (c.read ? ' is-read' : '') + (isNew ? ' is-new' : '')}>
        <button
          type="button"
          className="PanelsChapter-mark"
          disabled={!app.session.user || c.state !== 'published'}
          aria-label={c.read ? tx('mark_unread') : tx('mark_read')}
          title={c.read ? tx('mark_unread') : tx('mark_read')}
          onclick={() => send('POST', `chapters/${c.id}/read`, { read: !c.read }).then(() => ((c.read = !c.read), m.redraw()))}
        >
          {c.read ? icon('fas fa-check') : <span className="PanelsChapter-dot" />}
        </button>
        <Link className="PanelsChapter-link" href={href}>
          <span className="PanelsChapter-line">
            <strong>{long(s.format, c.number)}</strong>
            {c.title ? <span className="PanelsChapter-title">{c.title}</span> : null}
            {isNew ? <span className="PanelsBadge PanelsBadge--new">{t('new')}</span> : null}
            {c.locked ? <span className="PanelsBadge PanelsBadge--soft">{icon('fas fa-lock')} {c.access === 'early' ? t('early_access') : t('paid')}</span> : null}
            {c.state !== 'published' ? <span className="PanelsBadge PanelsBadge--soft">{t(`state.${c.state}`)}</span> : null}
            {c.scheduled ? <span className="PanelsBadge PanelsBadge--soft">{icon('far fa-clock')} {t('scheduled_for', { date: new Date(c.publishedAt!).toLocaleString() })}</span> : null}
          </span>
          <span className="PanelsChapter-meta">
            {c.source === 'external' ? t('external_short') : t('pages', { count: c.pageCount })}
            {c.publishedAt && !c.scheduled ? [' · ', humanTime(c.publishedAt)] : null}
            {progress ? [' · ', t('page_of', { page: progress.page, pages: progress.pages })] : null}
          </span>
          {progress ? (
            <span className="PanelsBar PanelsBar--thin" aria-hidden="true">
              <span style={{ width: `${Math.round((progress.page / Math.max(1, progress.pages)) * 100)}%` }} />
            </span>
          ) : null}
        </Link>
        {c.discussionId ? (
          <Link className="PanelsChapter-replies" href={app.route('discussion', { id: c.discussionId })} aria-label={tx('replies', { count: c.replies })}>
            {icon('far fa-comment')} {c.replies}
          </Link>
        ) : null}
      </li>
    );
  }

  aside(s: SeriesFull): Mithril.Children {
    const spoilerShield = !!(globalThis as any).flarum?.extensions?.['ernestdefoe-spoiler-shield'];
    return (
      <>
        {s.fields.length ? (
          <dl className="PanelsFacts">
            {s.fields.map((f) => (
              <div className="PanelsFacts-row">
                <dt>{f.name}</dt>
                <dd>
                  {f.type === 'link' ? (
                    <a href={f.value} target="_blank" rel="noopener noreferrer nofollow ugc">
                      {f.value.replace(/^https?:\/\//, '')}
                    </a>
                  ) : (
                    f.value
                  )}
                </dd>
              </div>
            ))}
          </dl>
        ) : null}
        {s.owner ? (
          <div className="PanelsCard PanelsOwner">
            <Link href={app.route('user', { username: s.owner.username })}>
              <strong>{s.owner.displayName}</strong>
            </Link>
            <span className="PanelsMuted">
              {t('uploaded_by')}
              {s.editors.length ? [' · ', t('co_editors', { names: s.editors.map((e) => e.displayName).join(', ') })] : null}
            </span>
          </div>
        ) : null}
        {spoilerShield ? <p className="PanelsCard PanelsMuted">{t('spoiler_shield_note')}</p> : null}
        <Link className="PanelsFootLink" href={app.route('panels.copyright') + `?type=series&id=${s.id}`}>
          {t('copyright.link')}
        </Link>
      </>
    );
  }
}
