import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import Avatar from 'flarum/common/components/Avatar';
import type Mithril from 'mithril';
import { type GuestPlace, type SeriesCard, api, get, long, readerHref, send, seriesHref, short, store, t, tx, icon } from '../util';
import { Refusal, StarPicker } from './bits';

interface PageData {
  id: number;
  position: number;
  segment: number;
  width: number;
  height: number;
  color: string | null;
  alt: string | null;
  sources: Record<string, string>;
}

interface Payload {
  series: SeriesCard & {
    readingMode: string;
    canEdit: boolean;
    canRate: boolean;
    canDownload: boolean;
    library: { shelf: string | null; notify: boolean } | null;
    myRating: number | null;
  };
  chapter: {
    id: number;
    number: string;
    title: string | null;
    source: string;
    externalUrl: string | null;
    discussionId: number | null;
    replies: number;
    read: boolean;
    note: string | null;
    transcript: string | null;
    pageCount: number;
  };
  refusal: string | null;
  offer: { chapter: number; series: number } | null;
  pages: PageData[];
  prev: { number: string; title: string | null } | null;
  next: { number: string; title: string | null } | null;
  place: number | null;
}

type Mode = 'vertical' | 'ltr' | 'rtl';

interface Prefs {
  fit: 'width' | 'height' | 'original';
  spread: boolean;
  saver: boolean;
  bg: 'theme' | 'black';
  zones: boolean;
}

const PREFS: Prefs = { fit: 'height', spread: true, saver: false, bg: 'theme', zones: true };
const SAVE_EVERY = 5000;

/**
 * The reader: /read/{slug}/{chapter}, full screen.
 *
 * Vertical (a webtoon's continuous strip), page left to right, and page right to left (manga:
 * "next" is the left arrow, the left third, a swipe to the right). The series picks the mode;
 * each reader can change it, remembered per series. The forum's header and the Mobile Tab bar
 * step aside; a tap in the middle, a mouse move or a key brings the bars back.
 *
 * Every <img> carries its width and height (nothing jumps) and the page's own colour while it
 * loads; srcset lets the browser pick 720, 1080 or 1600 wide, or 720 only with Data saver.
 */
export default class ReaderPage extends Page {
  data: Payload | null = null;
  missing = false;
  mode: Mode = 'rtl';
  prefs: Prefs = { ...PREFS };
  /** Page modes: the logical page shown (index into groups); groups.length is the end card. */
  index = 0;
  groups: PageData[][] = [];
  chrome = true;
  settings = false;
  finished = false;
  picker: Array<{ number: string; title: string | null }> | null = null;
  replies: any[] | null = null;
  private hideTimer: any = null;
  private saveTimer: any = null;
  private lastSaved = '';
  private touch: { x: number; y: number } | null = null;
  private onKey = (e: KeyboardEvent) => this.key(e);
  private onScroll = () => this.scrolled();
  private onMove = () => this.wake();
  private onLeave = () => this.save(true);
  private scrollTicking = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--panels Panels-reading';
    this.prefs = { ...PREFS, ...store.get<Partial<Prefs>>('prefs', {}) };
    this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    document.addEventListener('keydown', this.onKey);
    window.addEventListener('scroll', this.onScroll, { passive: true });
    window.addEventListener('mousemove', this.onMove, { passive: true });
    window.addEventListener('pagehide', this.onLeave);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.save(true);
    document.removeEventListener('keydown', this.onKey);
    window.removeEventListener('scroll', this.onScroll);
    window.removeEventListener('mousemove', this.onMove);
    window.removeEventListener('pagehide', this.onLeave);
    clearTimeout(this.hideTimer);
    clearTimeout(this.saveTimer);
    document.body.removeAttribute('data-panels-bg');
  }

  load(): void {
    const slug = m.route.param('slug');
    const number = m.route.param('chapter');
    get<Payload>(`series/${encodeURIComponent(slug)}/chapters/${encodeURIComponent(number)}`, {}, true)
      .then((data) => {
        this.data = data;
        this.mode = store.get<Mode | null>(`mode.${data.series.id}`, null) || (data.series.readingMode as Mode) || 'rtl';
        this.groups = [];
        data.pages.forEach((p) => {
          const last = this.groups[this.groups.length - 1];
          if (last && last[0].position === p.position) last.push(p);
          else this.groups.push([p]);
        });
        const p = Number(m.route.param('p')) || data.place || this.guestPlace(data) || 1;
        this.index = Math.max(0, Math.min(this.groups.length - 1, p - 1));
        app.history.push('panels.reader', `${data.series.title} · ${long(data.series.format, data.chapter.number)}`);
        app.setTitle(`${long(data.series.format, data.chapter.number)} · ${data.series.title}`);
        this.applyBg();
        this.wake();
        if (this.mode === 'vertical' && this.index > 0) {
          // Back to where the member was in the strip, once the images have their boxes.
          requestAnimationFrame(() => document.getElementById(`panels-page-${this.index + 1}`)?.scrollIntoView());
        } else {
          window.scrollTo(0, 0);
        }
        this.preload();
      })
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  // ---- state changes ----

  setMode(mode: Mode): void {
    this.mode = mode;
    if (this.data) store.set(`mode.${this.data.series.id}`, mode);
    m.redraw();
  }

  setPref<K extends keyof Prefs>(key: K, value: Prefs[K]): void {
    this.prefs[key] = value;
    store.set('prefs', this.prefs);
    this.applyBg();
    m.redraw();
  }

  applyBg(): void {
    document.body.setAttribute('data-panels-bg', this.prefs.bg);
  }

  spreadOn(): boolean {
    return this.mode !== 'vertical' && this.prefs.spread && window.innerWidth >= 1100 && window.innerWidth > window.innerHeight;
  }

  go(delta: number): void {
    if (!this.data || this.mode === 'vertical') return;
    const step = this.spreadOn() && this.index > 0 ? 2 : 1;
    const next = this.index + delta * (delta > 0 && this.index === 0 ? 1 : step);
    if (next < 0) return;
    if (next >= this.groups.length) {
      this.index = this.groups.length;
      this.finish();
    } else {
      this.index = next;
    }
    this.chrome = false;
    this.preload();
    this.queueSave();
    m.redraw();
  }

  /** "Next" in reading order: right in ltr, left in rtl. */
  forward(): void {
    this.go(1);
  }

  back(): void {
    this.go(-1);
  }

  wake(): void {
    if (!this.chrome) {
      this.chrome = true;
      m.redraw();
    }
    clearTimeout(this.hideTimer);
    this.hideTimer = setTimeout(() => {
      if (!this.settings) {
        this.chrome = false;
        m.redraw();
      }
    }, 2500);
  }

  // ---- input ----

  key(e: KeyboardEvent): void {
    const target = e.target as HTMLElement | null;
    if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
    if (document.querySelector('.ModalManager .Modal') || e.metaKey || e.ctrlKey || e.altKey) return;
    const rtl = this.mode === 'rtl';
    const paged = this.mode !== 'vertical';
    let handled = true;
    if (e.key === 'ArrowRight') {
      if (paged) rtl ? this.back() : this.forward();
      else handled = false;
    } else if (e.key === 'ArrowLeft') {
      if (paged) rtl ? this.forward() : this.back();
      else handled = false;
    } else if ((e.key === ' ' || e.key === 'PageDown') && paged) {
      e.shiftKey ? this.back() : this.forward();
    } else if (e.key === 'PageUp' && paged) {
      this.back();
    } else if (e.key === 'Home' && paged) {
      this.index = 0;
      m.redraw();
    } else if (e.key === 'End' && paged) {
      this.index = Math.max(0, this.groups.length - 1);
      m.redraw();
    } else if (e.key === ']' && this.data?.next) {
      m.route.set(readerHref(this.data.series.slug, this.data.next.number));
    } else if (e.key === '[' && this.data?.prev) {
      m.route.set(readerHref(this.data.series.slug, this.data.prev.number));
    } else if (e.key === 'f' || e.key === 'F') {
      this.fullscreen();
    } else if (e.key === 'm' || e.key === 'M') {
      this.setMode(this.mode === 'vertical' ? 'ltr' : this.mode === 'ltr' ? 'rtl' : 'vertical');
    } else if (e.key === 'd' || e.key === 'D') {
      this.setPref('saver', !this.prefs.saver);
    } else if (e.key === 'Escape') {
      if (this.settings) this.settings = false;
      else if (this.data) m.route.set(seriesHref(this.data.series.slug));
      m.redraw();
    } else {
      handled = false;
    }
    if (handled) {
      e.preventDefault();
      this.wake();
    }
  }

  tap(e: MouseEvent): void {
    const x = e.clientX / window.innerWidth;
    if (this.mode === 'vertical' || !this.prefs.zones || (x > 1 / 3 && x < 2 / 3)) {
      this.chrome ? ((this.chrome = false), clearTimeout(this.hideTimer)) : this.wake();
      m.redraw();
      return;
    }
    const left = x <= 1 / 3;
    if (this.mode === 'rtl') left ? this.forward() : this.back();
    else left ? this.back() : this.forward();
  }

  touchStart(e: TouchEvent): void {
    this.touch = e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null;
  }

  touchEnd(e: TouchEvent): void {
    const start = this.touch;
    this.touch = null;
    // Never while zoomed: panning a zoomed page must not turn it.
    if (!start || this.mode === 'vertical' || (window.visualViewport?.scale ?? 1) > 1.05) return;
    const end = e.changedTouches[0];
    const dx = end.clientX - start.x;
    const dy = end.clientY - start.y;
    if (Math.abs(dx) < 60 || Math.abs(dy) > 50) return;
    // A swipe to the left reveals what's on the right: the next page in ltr, the previous in rtl.
    const towardRight = dx < 0;
    if (this.mode === 'rtl') towardRight ? this.back() : this.forward();
    else towardRight ? this.forward() : this.back();
  }

  scrolled(): void {
    if (this.mode !== 'vertical' || this.scrollTicking || !this.data) return;
    this.scrollTicking = true;
    requestAnimationFrame(() => {
      this.scrollTicking = false;
      const strip = document.querySelector('.PanelsStrip');
      if (!strip) return;
      const middle = window.innerHeight / 2;
      let current = 0;
      document.querySelectorAll<HTMLElement>('.PanelsStrip-page').forEach((el, i) => {
        if (el.getBoundingClientRect().top < middle) current = i;
      });
      const rect = strip.getBoundingClientRect();
      const seen = (window.innerHeight - rect.top) / Math.max(1, rect.height);
      if (current !== this.index) {
        this.index = current;
        this.queueSave();
        m.redraw();
      }
      if (seen >= 0.9 && !this.finished) this.finish();
    });
  }

  fullscreen(): void {
    if (document.fullscreenElement) document.exitFullscreen?.();
    else document.documentElement.requestFullscreen?.().catch(() => {});
  }

  // ---- loading ahead, saving the place ----

  src(page: PageData): { src: string; srcset?: string } {
    const widths = Object.keys(page.sources)
      .map(Number)
      .sort((a, b) => a - b);
    if (this.prefs.saver) return { src: page.sources[String(widths[0])] };
    return {
      src: page.sources[String(widths[Math.min(1, widths.length - 1)])],
      srcset: widths.map((w) => `${page.sources[String(w)]} ${w}w`).join(', '),
    };
  }

  preload(): void {
    if (this.mode === 'vertical') return;
    [this.index + 1, this.index + 2, this.index + 3, this.index - 1].forEach((i) => {
      this.groups[i]?.forEach((p) => {
        const img = new Image();
        const { src, srcset } = this.src(p);
        if (srcset) {
          img.srcset = srcset;
          img.sizes = '100vw';
        }
        img.src = src;
      });
    });
  }

  queueSave(): void {
    clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(() => this.save(false), SAVE_EVERY);
  }

  save(leaving: boolean): void {
    const d = this.data;
    if (!d || d.refusal || !this.groups.length) return;
    const page = Math.min(this.groups.length, this.index + 1);
    const body = { page, pages: this.groups.length, finished: this.finished };
    const key = JSON.stringify(body);
    if (key === this.lastSaved) return;
    this.lastSaved = key;

    if (!app.session.user) {
      const places = store.get<GuestPlace[]>('places', []).filter((p) => p.seriesId !== d.series.id);
      places.unshift({ seriesId: d.series.id, chapterId: d.chapter.id, page, pages: this.groups.length, at: Date.now() });
      store.set('places', places.slice(0, 50));
      return;
    }
    if (leaving) {
      // The tab is closing: a request that outlives the page.
      try {
        fetch(api(`chapters/${d.chapter.id}/progress`), {
          method: 'POST',
          keepalive: true,
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken || '' },
          body: key,
        });
      } catch (e) {
        // nothing to do: the last periodic save stands
      }
      return;
    }
    app.request({ method: 'POST', url: api(`chapters/${d.chapter.id}/progress`), body, errorHandler: () => {} } as any).catch(() => {});
  }

  guestPlace(d: Payload): number | null {
    if (app.session.user) return null;
    const place = store.get<GuestPlace[]>('places', []).find((p) => p.seriesId === d.series.id && p.chapterId === d.chapter.id);
    return place ? place.page : null;
  }

  finish(): void {
    if (this.finished) return;
    this.finished = true;
    this.save(false);
    this.loadReplies();
    if (this.data) this.data.chapter.read = true;
  }

  loadReplies(): void {
    const id = this.data?.chapter.discussionId;
    if (!id || this.replies) return;
    this.replies = [];
    app.store
      .find<any[]>('posts', { filter: { discussion: id, type: 'comment' }, sort: '-createdAt', page: { limit: 3 } } as any)
      .then((posts: any) => (this.replies = [...posts].filter((p: any) => p.number() > 1)))
      .catch(() => {})
      .finally(() => m.redraw());
  }

  reply(): void {
    const id = this.data?.chapter.discussionId;
    if (!id) return;
    if (!app.session.user) {
      app.modal.show(() => import('flarum/forum/components/LogInModal'));
      return;
    }
    Promise.all([app.store.find<any>('discussions', String(id)), import('flarum/forum/utils/DiscussionControls')]).then(
      ([discussion, controls]: any) => (controls.default || controls).replyAction.call(discussion, true, false)
    );
  }

  openPicker(): void {
    if (this.picker || !this.data) return;
    get<any>(`series/${this.data.series.slug}`, { order: 'asc' }).then((r) => {
      this.picker = r.data.chapters
        .filter((c: any) => c.state === 'published' && !c.scheduled)
        .map((c: any) => ({ number: c.number, title: c.title }));
      m.redraw();
    });
  }

  // ---- drawing ----

  view() {
    if (this.missing) {
      return (
        <div className="PanelsReader PanelsReader--message">
          <p>{t('error.not_found')}</p>
          <Link className="Button" href={app.route('panels')}>
            {t('back_to_library')}
          </Link>
        </div>
      );
    }
    if (!this.data) {
      return (
        <div className="PanelsReader">
          <LoadingIndicator />
        </div>
      );
    }
    const d = this.data;
    return (
      <div className={`PanelsReader PanelsReader--${this.mode}` + (this.chrome ? ' has-chrome' : '')}>
        {this.topBar(d)}
        {d.refusal ? (
          <div className="PanelsReader-message">
            <Refusal refusal={d.refusal} offer={d.offer} onretry={() => this.load()} />
          </div>
        ) : d.chapter.source === 'external' ? (
          this.external(d)
        ) : this.mode === 'vertical' ? (
          this.strip(d)
        ) : (
          this.paged(d)
        )}
        {this.settings ? this.settingsSheet(d) : null}
        {this.bottomBar(d)}
      </div>
    );
  }

  topBar(d: Payload): Mithril.Children {
    return (
      <header className="PanelsReader-top">
        <Link className="PanelsReader-icon" href={seriesHref(d.series.slug)} aria-label={tx('reader.back', { title: d.series.title })}>
          {icon('fas fa-chevron-left')}
        </Link>
        <span className="PanelsReader-titles">
          <strong>{d.series.title}</strong>
          <span>{[long(d.series.format, d.chapter.number), d.chapter.title ? ` · ${d.chapter.title}` : '']}</span>
        </span>
        <label className="PanelsReader-picker">
          <span className="visually-hidden">{tx('reader.jump')}</span>
          <select
            onfocus={() => this.openPicker()}
            onmousedown={() => this.openPicker()}
            onchange={(e: Event) => m.route.set(readerHref(d.series.slug, (e.target as HTMLSelectElement).value))}
          >
            {(this.picker || [{ number: d.chapter.number, title: d.chapter.title }]).map((c) => (
              <option value={c.number} selected={c.number === d.chapter.number}>
                {short(d.series.format, c.number)}
              </option>
            ))}
          </select>
        </label>
        <button type="button" className="PanelsReader-icon PanelsReader-full" aria-label={tx('reader.fullscreen')} onclick={() => this.fullscreen()}>
          {icon('fas fa-expand')}
        </button>
        <button
          type="button"
          className="PanelsReader-icon"
          aria-label={tx('reader.settings')}
          aria-expanded={this.settings ? 'true' : 'false'}
          onclick={() => {
            this.settings = !this.settings;
            this.wake();
          }}
        >
          {icon('fas fa-sliders')}
        </button>
      </header>
    );
  }

  bottomBar(d: Payload): Mithril.Children {
    const total = this.groups.length;
    const current = Math.min(total, this.index + 1);
    const rtl = this.mode === 'rtl';
    const prev = d.prev ? (
      <Link className="PanelsReader-icon" href={readerHref(d.series.slug, d.prev.number)} aria-label={tx('reader.prev_chapter')}>
        {icon('fas fa-backward-step')}
      </Link>
    ) : (
      <span className="PanelsReader-icon is-off" />
    );
    const next = d.next ? (
      <Link className="PanelsReader-icon" href={readerHref(d.series.slug, d.next.number)} aria-label={tx('reader.next_chapter')}>
        {icon('fas fa-forward-step')}
      </Link>
    ) : (
      <span className="PanelsReader-icon is-off" />
    );
    return (
      <footer className="PanelsReader-bottom">
        {rtl ? next : prev}
        {total && !d.refusal ? (
          <>
            <input
              className="PanelsReader-scrub"
              type="range"
              min="1"
              max={String(total)}
              value={String(current)}
              dir={rtl ? 'rtl' : 'ltr'}
              aria-label={tx('reader.page_of', { page: current, pages: total })}
              oninput={(e: Event) => {
                const v = Number((e.target as HTMLInputElement).value) - 1;
                this.index = v;
                if (this.mode === 'vertical') document.getElementById(`panels-page-${v + 1}`)?.scrollIntoView();
                this.queueSave();
                this.wake();
              }}
            />
            <span className="PanelsReader-count" aria-live="polite">
              {t('reader.page_count', { page: current, pages: total })}
            </span>
          </>
        ) : (
          <span className="PanelsReader-spacer" />
        )}
        {rtl ? prev : next}
      </footer>
    );
  }

  img(p: PageData, sizes: string, eager: boolean): Mithril.Children {
    const { src, srcset } = this.src(p);
    return (
      <img
        src={src}
        srcset={srcset}
        sizes={srcset ? sizes : undefined}
        width={p.width}
        height={p.height}
        alt={p.segment ? '' : p.alt || tx('reader.page_alt', { page: p.position })}
        loading={eager ? 'eager' : 'lazy'}
        decoding="async"
        style={{ backgroundColor: p.color || undefined }}
        draggable={false}
      />
    );
  }

  strip(d: Payload): Mithril.Children {
    const width = Number(app.forum.attribute('panelsStripWidth') || 800);
    return (
      <main className="PanelsReader-stage" onclick={(e: MouseEvent) => this.tap(e)}>
        <div className="PanelsStrip" style={{ maxWidth: `${width}px` }}>
          {this.groups.map((group, i) => (
            <div className="PanelsStrip-page" id={`panels-page-${i + 1}`}>
              {group.map((p) => this.img(p, `(max-width: ${width}px) 100vw, ${width}px`, i < 2))}
            </div>
          ))}
        </div>
        {this.transcript(d)}
        {this.endCard(d)}
      </main>
    );
  }

  paged(d: Payload): Mithril.Children {
    if (this.index >= this.groups.length) {
      return <main className="PanelsReader-stage PanelsReader-stage--end">{this.endCard(d)}</main>;
    }
    const spread = this.spreadOn() && this.index > 0 && this.index + 1 < this.groups.length;
    const shown = spread ? [this.groups[this.index], this.groups[this.index + 1]] : [this.groups[this.index]];
    if (this.mode === 'rtl') shown.reverse();
    return (
      <main
        className={`PanelsReader-stage PanelsReader-paged PanelsReader-fit--${this.prefs.fit}` + (spread ? ' is-spread' : '')}
        onclick={(e: MouseEvent) => this.tap(e)}
        ontouchstart={(e: TouchEvent) => this.touchStart(e)}
        ontouchend={(e: TouchEvent) => this.touchEnd(e)}
      >
        {this.prefs.zones && this.index === 0 && this.mode === 'rtl' ? (
          <div className="PanelsReader-hint" role="status">
            {icon('fas fa-arrow-left')} {t('reader.rtl_hint')}
          </div>
        ) : null}
        <div className="PanelsReader-pages">
          {shown.map((group) => (
            <div className="PanelsReader-page">{group.map((p) => this.img(p, spread ? '50vw' : '100vw', true))}</div>
          ))}
        </div>
      </main>
    );
  }

  external(d: Payload): Mithril.Children {
    let host = '';
    try {
      host = new URL(d.chapter.externalUrl || '').hostname;
    } catch (e) {
      host = '';
    }
    return (
      <main className="PanelsReader-stage PanelsReader-stage--end">
        <div className="PanelsReader-end">
          <div className="PanelsCard PanelsReader-external">
            <h2>{t('reader.external_title')}</h2>
            <p>{t('reader.external_body', { host })}</p>
            <a className="Button Button--primary" href={d.chapter.externalUrl || '#'} target="_blank" rel="noopener noreferrer">
              {t('reader.external_button', { host })}
            </a>
          </div>
          {this.discussionCard(d)}
        </div>
      </main>
    );
  }

  transcript(d: Payload): Mithril.Children {
    if (!d.chapter.transcript) return null;
    return (
      <details className="PanelsReader-transcript">
        <summary>{t('reader.transcript')}</summary>
        <p>{d.chapter.transcript}</p>
      </details>
    );
  }

  endCard(d: Payload): Mithril.Children {
    if (this.mode === 'vertical' && !this.finished) {
      // Drawn, so it can be scrolled to; filled once the strip is finished.
      return <div className="PanelsReader-end PanelsReader-end--waiting" />;
    }
    const s = d.series;
    const save = (body: Record<string, unknown>) =>
      send<any>('POST', `series/${s.id}/library`, body).then((r) => {
        s.library = r.library;
        m.redraw();
      });
    return (
      <div className="PanelsReader-end">
        <div className="PanelsCard">
          <span className="PanelsEyebrow">{t('reader.end_of', { chapter: long(s.format, d.chapter.number) })}</span>
          {d.next ? (
            <Link className="Button Button--primary PanelsReader-next" href={readerHref(s.slug, d.next.number)}>
              <span>
                <strong>{t('reader.next', { chapter: long(s.format, d.next.number) })}</strong>
                {d.next.title ? <span className="PanelsReader-nextTitle">{d.next.title}</span> : null}
              </span>
              {icon('fas fa-arrow-right')}
            </Link>
          ) : (
            <p className="PanelsReader-caught">{t('reader.caught_up')}</p>
          )}
          {app.session.user ? (
            <div className="PanelsReader-endActions">
              <Button
                className={'Button' + (s.library?.notify ? ' PanelsFollowing' : '')}
                icon={s.library?.notify ? 'fas fa-bell' : 'far fa-bell'}
                aria-pressed={s.library?.notify ? 'true' : 'false'}
                onclick={() => save({ notify: !s.library?.notify })}
              >
                {s.library?.notify ? t('following') : t('follow')}
              </Button>
              {s.canRate ? (
                <StarPicker
                  value={s.myRating}
                  onchange={(v: number | null) =>
                    send<any>('POST', `series/${s.id}/rating`, { rating: v }).then((r) => ((s.myRating = r.myRating), m.redraw()))
                  }
                />
              ) : null}
            </div>
          ) : null}
          <span className="PanelsMuted PanelsReader-saved">{app.session.user ? t('reader.saved') : t('reader.saved_guest')}</span>
        </div>
        {this.discussionCard(d)}
        <p className="PanelsFoot">
          <Link href={app.route('panels.copyright') + `?type=chapter&id=${d.chapter.id}`}>{t('copyright.link')}</Link>
        </p>
      </div>
    );
  }

  discussionCard(d: Payload): Mithril.Children {
    if (!d.chapter.discussionId) return null;
    if (this.replies === null) this.loadReplies();
    return (
      <section className="PanelsCard PanelsReader-talk" aria-labelledby="panels-talk">
        <div className="PanelsReader-talkHead">
          <h2 id="panels-talk">{t('reader.replies', { count: d.chapter.replies, chapter: short(d.series.format, d.chapter.number) })}</h2>
          <Link href={app.route('discussion', { id: d.chapter.discussionId })}>{t('reader.open_discussion')}</Link>
        </div>
        {(this.replies || []).map((post: any) => (
          <article className="PanelsReader-reply">
            <Avatar user={post.user()} />
            <div>
              <span className="PanelsReader-replyHead">
                <strong>{post.user()?.displayName?.() ?? ''}</strong> <span className="PanelsMuted">{humanTime(post.createdAt())}</span>
              </span>
              <div className="PanelsReader-replyBody">{m.trust(post.contentHtml() || '')}</div>
            </div>
          </article>
        ))}
        <Button className="Button" icon="fas fa-reply" onclick={() => this.reply()}>
          {app.translator.trans('core.forum.discussion_controls.reply_button')}
        </Button>
      </section>
    );
  }

  settingsSheet(d: Payload): Mithril.Children {
    const choice = (on: boolean, label: Mithril.Children, onclick: () => void) => (
      <button type="button" className={'PanelsReader-choice' + (on ? ' is-on' : '')} aria-pressed={on ? 'true' : 'false'} onclick={onclick}>
        {label}
      </button>
    );
    return (
      <div className="PanelsReader-settings" role="dialog" aria-label={tx('reader.settings')}>
        <h2 className="PanelsEyebrow">{t('reader.mode')}</h2>
        <div className="PanelsReader-choices">
          {(['vertical', 'ltr', 'rtl'] as Mode[]).map((mode) => choice(this.mode === mode, t(`mode.${mode}`), () => this.setMode(mode)))}
        </div>
        {this.mode !== 'vertical' ? (
          <>
            <h2 className="PanelsEyebrow">{t('reader.fit')}</h2>
            <div className="PanelsReader-choices">
              {(['height', 'width', 'original'] as const).map((fit) =>
                choice(this.prefs.fit === fit, t(`reader.fit_${fit}`), () => this.setPref('fit', fit))
              )}
            </div>
            <label className="PanelsReader-toggle">
              <input type="checkbox" checked={this.prefs.spread} onchange={() => this.setPref('spread', !this.prefs.spread)} />
              {t('reader.spread')}
            </label>
            <label className="PanelsReader-toggle">
              <input type="checkbox" checked={this.prefs.zones} onchange={() => this.setPref('zones', !this.prefs.zones)} />
              {t('reader.zones')}
            </label>
          </>
        ) : null}
        <label className="PanelsReader-toggle">
          <input type="checkbox" checked={this.prefs.saver} onchange={() => this.setPref('saver', !this.prefs.saver)} />
          {t('reader.saver')}
        </label>
        <h2 className="PanelsEyebrow">{t('reader.background')}</h2>
        <div className="PanelsReader-choices">
          {choice(this.prefs.bg === 'theme', t('reader.bg_theme'), () => this.setPref('bg', 'theme'))}
          {choice(this.prefs.bg === 'black', t('reader.bg_black'), () => this.setPref('bg', 'black'))}
        </div>
        {d.series.canDownload ? (
          <a className="Button PanelsReader-download" href={api(`chapters/${d.chapter.id}/download`)} download>
            {icon('fas fa-download', { className: 'Button-icon' })}
            <span className="Button-label">{t('reader.download')}</span>
          </a>
        ) : null}
        {d.series.canEdit ? (
          <Link className="Button" href={app.route('panels.edit', { slug: d.series.slug, id: d.chapter.id })}>
            {t('reader.edit')}
          </Link>
        ) : null}
        <p className="PanelsMuted PanelsReader-keys">{t('reader.keys')}</p>
      </div>
    );
  }
}
