import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { type SeriesCard, get, readerHref, seriesHref, short, t, tx , icon } from '../util';

export type Kind = 'new' | 'continue' | 'trending' | 'spotlight' | 'stats';

interface Attrs {
  kind: Kind;
  host: 'fof' | 'page-builder' | 'stock-sidebar';
  settings?: Record<string, any>;
  /** Inside a host that draws its own box and title. */
  bare?: boolean;
}

/*
 * One component for the five widgets in every host. Data lives in a module-level store keyed by
 * the request, so a host that re-creates widgets on every redraw (and two widgets asking the same
 * thing) cost one request.
 */
const cache = new Map<string, { data: any; at: number } | 'loading'>();
const TTL = 5 * 60 * 1000;

function fetchOnce(path: string, params: Record<string, any>): any {
  const key = path + JSON.stringify(params);
  const hit = cache.get(key);
  if (hit && hit !== 'loading' && Date.now() - hit.at < TTL) return hit.data;
  if (hit !== 'loading') {
    cache.set(key, 'loading');
    get(path, params, true)
      .then((r) => cache.set(key, { data: r.data, at: Date.now() }))
      .catch(() => cache.set(key, { data: [], at: Date.now() }))
      .finally(() => m.redraw());
  }
  return hit && hit !== 'loading' ? hit.data : null;
}

const MiniCover = {
  view(vnode: Mithril.Vnode<{ s: SeriesCard; big?: boolean }>) {
    const { s, big } = vnode.attrs;
    return (
      <span className={'PanelsMini-cover' + (big ? ' is-big' : '') + (s.cover?.blurred ? ' is-blurred' : '')} style={{ backgroundColor: s.coverColor || undefined }}>
        {s.cover ? <img src={big ? s.cover.medium : s.cover.small} alt="" loading="lazy" decoding="async" /> : null}
      </span>
    );
  },
};

export default class PanelsWidget {
  view(vnode: Mithril.Vnode<Attrs>) {
    const { kind, settings = {}, bare, host } = vnode.attrs;
    const count = Math.max(1, Math.min(12, Number(settings.count) || (kind === 'trending' ? 5 : kind === 'continue' ? 4 : 6)));
    const body = this.body(kind, count, settings);
    // Nothing to show: nothing at all (staff get a hint, so a placed widget isn't a mystery).
    if (body === null) return null;
    if (body === false) return app.forum.attribute('panelsCanModerate') ? <div className="PanelsWidget PanelsWidget--empty">{t('widget.empty')}</div> : null;
    const title = (
      <h3 className="PanelsWidget-title">
        {icon(ICONS[kind])} {tx(`widget.${kind}`)}
      </h3>
    );
    return (
      <div className={`PanelsWidget PanelsWidget--${kind} PanelsWidget--${host}` + (bare ? ' is-bare' : '')}>
        {bare ? null : title}
        {body}
      </div>
    );
  }

  /** null while loading, false when empty. */
  body(kind: Kind, count: number, settings: Record<string, any>): Mithril.Children | null | false {
    const format = settings.format || '';
    if (kind === 'new') {
      const rows = fetchOnce('feed', { kind: 'new', limit: count, format });
      if (!rows) return null;
      if (!rows.length) return false;
      if (settings.layout === 'covers') {
        return (
          <div className="PanelsMini-covers">
            {rows.map((r: any) => (
              <Link className="PanelsMini-coverItem" href={readerHref(r.series.slug, r.chapter.number)}>
                <MiniCover s={r.series} big />
                <strong>{r.series.title}</strong>
                <span className="PanelsMuted">
                  {short(r.series.format, r.chapter.number)} · {humanTime(r.chapter.publishedAt)}
                </span>
              </Link>
            ))}
          </div>
        );
      }
      return (
        <ul className="PanelsMini-list">
          {rows.map((r: any) => (
            <li>
              <Link className="PanelsMini-row" href={readerHref(r.series.slug, r.chapter.number)}>
                <MiniCover s={r.series} />
                <span className="PanelsMini-text">
                  <strong>{r.series.title}</strong>
                  <span className="PanelsMuted">
                    {short(r.series.format, r.chapter.number)} · {humanTime(r.chapter.publishedAt)}
                    {r.chapter.locked ? [' ', icon('fas fa-lock')] : null}
                  </span>
                </span>
                {app.session.user && !r.chapter.read ? <span className="PanelsMini-dot" aria-label={tx('unread')} /> : null}
              </Link>
            </li>
          ))}
        </ul>
      );
    }

    if (kind === 'continue') {
      if (!app.session.user) return null;
      const rows: SeriesCard[] | null = fetchOnce('feed', { kind: 'continue', limit: count });
      if (!rows) return null;
      if (!rows.length) return null;
      return (
        <ul className="PanelsMini-list">
          {rows.map((s) => {
            const p = s.progress;
            const pct = p && p.pages ? Math.round((p.page / p.pages) * 100) : 0;
            return (
              <li>
                <Link className="PanelsMini-row" href={p ? readerHref(s.slug, p.number) : seriesHref(s.slug)}>
                  <MiniCover s={s} />
                  <span className="PanelsMini-text">
                    <span className="PanelsMini-head">
                      <strong>{s.title}</strong>
                      {s.unread ? <span className="PanelsMini-new">{t('n_new', { count: s.unread })}</span> : null}
                    </span>
                    <span className="PanelsBar PanelsBar--thin" aria-hidden="true">
                      <span style={{ width: `${pct}%` }} />
                    </span>
                    <span className="PanelsMuted">{p ? t('place_short', { chapter: short(s.format, p.number), page: p.page, pages: p.pages }) : null}</span>
                  </span>
                </Link>
              </li>
            );
          })}
        </ul>
      );
    }

    if (kind === 'trending') {
      const rows: SeriesCard[] | null = fetchOnce('series', { sort: settings.period === 'all' ? 'reads' : 'trending', limit: count, filter: format ? { format } : {} });
      if (!rows) return null;
      if (!rows.length) return false;
      return (
        <ol className="PanelsMini-list PanelsMini-ranked">
          {rows.map((s, i) => (
            <li>
              <Link className="PanelsMini-row" href={seriesHref(s.slug)}>
                <span className={'PanelsMini-rank' + (i === 0 ? ' is-first' : '')}>{i + 1}</span>
                <MiniCover s={s} />
                <span className="PanelsMini-text">
                  <strong>{s.title}</strong>
                  <span className="PanelsMuted">
                    {tx(`format.${s.format}`)} · {tx(`status.${s.status}`)}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ol>
      );
    }

    if (kind === 'spotlight') {
      const rows: SeriesCard[] | null = fetchOnce('series', { filter: { featured: 1 }, sort: 'latest', limit: 12 });
      if (!rows) return null;
      if (!rows.length) return false;
      // Today's pick: the same for everyone today.
      const day = Math.floor(Date.now() / 86400000);
      const s = rows[day % rows.length];
      return (
        <div className="PanelsSpotlight">
          <Link href={seriesHref(s.slug)} className="PanelsSpotlight-art" tabindex="-1" aria-hidden="true">
            <MiniCover s={s} big />
          </Link>
          <div className="PanelsSpotlight-text">
            <span className="PanelsEyebrow">
              {tx(`format.${s.format}`)} · {tx(`status.${s.status}`)}
            </span>
            <Link className="PanelsSpotlight-title" href={seriesHref(s.slug)}>
              {s.title}
            </Link>
            {s.genres.length ? <span className="PanelsMuted">{s.genres.map((g) => g.name).join(' · ')}</span> : null}
            <span className="PanelsSpotlight-actions">
              <Link className="Button Button--primary" href={seriesHref(s.slug)}>
                {t('start_reading')}
              </Link>
              {s.rating ? <span className="PanelsTile-rating">★ {s.rating.toFixed(1)}</span> : null}
            </span>
          </div>
        </div>
      );
    }

    const stats = fetchOnce('feed', { kind: 'stats' });
    if (!stats) return null;
    if (!stats.series) return false;
    return (
      <dl className="PanelsStats">
        {(['series', 'chapters', 'pages', 'readers'] as const).map((k) => (
          <div>
            <dd>{Number(stats[k]).toLocaleString()}</dd>
            <dt>{t(`widget.stat_${k}`)}</dt>
          </div>
        ))}
      </dl>
    );
  }
}

export const ICONS: Record<Kind, string> = {
  new: 'fas fa-bolt',
  continue: 'fas fa-book-open-reader',
  trending: 'fas fa-fire',
  spotlight: 'fas fa-star',
  stats: 'fas fa-chart-simple',
};
