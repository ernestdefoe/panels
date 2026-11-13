import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { type Cover, type SeriesCard, seriesHref, send, short, t, tx , icon } from '../util';

/*
 * Small pieces the pages share. Plain functions wrapped as { view } objects: a bare function in
 * JSX is a closure component factory in Mithril.
 */

/** A cover, or its colour while it loads. A blurred stand-in carries the 18+ mark. */
export const CoverArt = {
  view(vnode: Mithril.Vnode<{ cover: Cover | null; color?: string | null; title: string; size?: 'small' | 'medium' | 'large' }>) {
    const { cover, color, title, size = 'medium' } = vnode.attrs;
    return (
      <span className={'PanelsCover' + (cover?.blurred ? ' is-blurred' : '')} style={{ backgroundColor: color || undefined }}>
        {cover ? <img src={cover[size]} alt={cover.blurred ? '' : tx('cover_of', { title })} loading="lazy" decoding="async" /> : <span className="PanelsCover-empty">{icon('fas fa-book-open')}</span>}
        {cover?.blurred ? (
          <span className="PanelsCover-gate">
            <strong>18+</strong>
            <span>{t('mature_cover')}</span>
          </span>
        ) : null}
      </span>
    );
  },
};

/** A series in a grid: cover, format, NEW, title, latest chapter, rating. */
export const Tile = {
  view(vnode: Mithril.Vnode<{ series: SeriesCard }>) {
    const s = vnode.attrs.series;
    const fresh = app.session.user ? s.unread > 0 : !!s.latestAt && Date.now() - Date.parse(s.latestAt) < 48 * 3600 * 1000;
    return (
      <Link className="PanelsTile" href={seriesHref(s.slug)}>
        <span className="PanelsTile-art">
          <CoverArt cover={s.cover} color={s.coverColor} title={s.title} />
          <span className="PanelsBadge PanelsBadge--format">{tx(`format.${s.format}`)}</span>
          {fresh ? <span className="PanelsBadge PanelsBadge--new">{t('new')}</span> : null}
        </span>
        <strong className="PanelsTile-title">{s.title}</strong>
        <span className="PanelsTile-meta">
          <span>{s.latest ? [short(s.format, s.latest.number), ' · ', humanTime(s.latest.publishedAt)] : tx(`status.${s.status}`)}</span>
          {s.rating ? <span className="PanelsTile-rating">★ {s.rating.toFixed(1)}</span> : null}
        </span>
      </Link>
    );
  },
};

/** A series in progress: where the member is, and how much is new. */
export const ContinueCard = {
  view(vnode: Mithril.Vnode<{ series: SeriesCard }>) {
    const s = vnode.attrs.series;
    const p = s.progress;
    const pct = p && p.pages ? Math.min(100, Math.round((p.page / p.pages) * 100)) : 0;
    const href = p ? app.route('panels.reader', { slug: s.slug, chapter: p.number }) : seriesHref(s.slug);
    return (
      <Link className="PanelsContinue" href={href}>
        <span className="PanelsContinue-art">
          <CoverArt cover={s.cover} color={s.coverColor} title={s.title} size="small" />
        </span>
        <span className="PanelsContinue-body">
          <span className="PanelsContinue-head">
            <strong>{s.title}</strong>
            {s.unread ? <span className="PanelsBadge PanelsBadge--count">{t('n_new', { count: s.unread })}</span> : null}
          </span>
          <span className="PanelsContinue-where">{p ? t('place', { chapter: short(s.format, p.number), page: p.page, pages: p.pages }) : null}</span>
          <span className="PanelsBar" aria-hidden="true">
            <span style={{ width: `${pct}%` }} />
          </span>
          <span className="PanelsContinue-cta">{t('continue')}</span>
        </span>
      </Link>
    );
  },
};

/** Five stars a member can set (or clear by choosing the same one again). */
export const StarPicker = {
  view(vnode: Mithril.Vnode<{ value: number | null; onchange: (v: number | null) => void; disabled?: boolean }>) {
    const { value, onchange, disabled } = vnode.attrs;
    return (
      <span className="PanelsStars" role="group" aria-label={tx('rate')}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            type="button"
            className={'PanelsStars-star' + ((value || 0) >= n ? ' is-on' : '')}
            aria-label={tx('stars', { count: n })}
            aria-pressed={value === n ? 'true' : 'false'}
            disabled={disabled}
            onclick={() => onchange(value === n ? null : n)}
          >
            ★
          </button>
        ))}
      </span>
    );
  },
};

/** Ask the member to confirm they're 18+, then run `done`. */
export function confirmAdult(done: () => void): void {
  if (!app.session.user) {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  if (!window.confirm(tx('age.confirm'))) return;
  send('POST', 'age').then(() => {
    app.forum.pushAttributes({ panelsAdult: true });
    done();
  });
}

/** Why a member can't read something, as a card with the next step. */
export const Refusal = {
  view(vnode: Mithril.Vnode<{ refusal: string; offer?: { chapter: number; series: number } | null; onretry: () => void }>) {
    const { refusal, offer, onretry } = vnode.attrs;
    let action: Mithril.Children = null;
    if (refusal === 'signin') {
      action = (
        <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
          {app.translator.trans('core.forum.header.log_in_link')}
        </Button>
      );
    } else if (refusal === 'age') {
      action = (
        <Button className="Button Button--primary" onclick={() => confirmAdult(onretry)}>
          {t('age.button')}
        </Button>
      );
    } else if (refusal === 'paid' && offer) {
      const Locked = (globalThis as any).flarum?.reg?.get?.('ernestdefoe-kiosk', 'forum/components/LockedNotice');
      const LockedNotice = Locked?.default || Locked;
      // Each renders nothing when no product sells it: a chapter on its own, or the whole series.
      if (LockedNotice) action = [<LockedNotice type="panels-chapter" id={offer.chapter} />, <LockedNotice type="panels-series" id={offer.series} />];
    }
    return (
      <div className={`PanelsRefusal PanelsRefusal--${refusal}`} role="status">
        <span className="PanelsRefusal-icon">{icon(refusal === 'paid' ? 'fas fa-lock' : refusal === 'unreleased' ? 'far fa-clock' : 'fas fa-eye-slash')}</span>
        <h2>{t(`refusal.${refusal}.title`)}</h2>
        <p>{t(`refusal.${refusal}.body`)}</p>
        {action}
      </div>
    );
  },
};

/** A select styled like core's: label, then the native control (accessible and phone-friendly). */
export const Select = {
  view(vnode: Mithril.Vnode<{ label: string; value: string; options: Array<[string, string]>; onchange: (v: string) => void; className?: string }>) {
    const { label, value, options, onchange, className } = vnode.attrs;
    return (
      <label className={'PanelsSelect ' + (className || '')}>
        <span className="visually-hidden">{label}</span>
        <select className="FormControl" value={value} aria-label={label} onchange={(e: Event) => onchange((e.target as HTMLSelectElement).value)}>
          {options.map(([v, l]) => (
            <option value={v} selected={v === value}>
              {l}
            </option>
          ))}
        </select>
      </label>
    );
  },
};
