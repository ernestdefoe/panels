import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { errorText, get, label, long, readerHref, send, seriesHref, t, tx, upload , icon } from '../util';
import { PageStructure } from './core';

interface EditorPageData {
  id: number;
  position: number;
  segment: number;
  name: string | null;
  sources: Record<string, string>;
}

interface Chapter {
  id: number;
  seriesId: number;
  seriesSlug: string;
  seriesTitle: string;
  seriesFormat: string;
  number: string;
  volume: number | null;
  title: string | null;
  state: string;
  scheduled: boolean;
  publishedAt: string | null;
  source: string;
  externalUrl: string | null;
  access: string;
  earlyAccessUntil: string | null;
  contentRating: string | null;
  discussionId: number | null;
  note: string | null;
  transcript: string | null;
  attested: boolean;
  pages: EditorPageData[];
}

/** A file on its way: uploading, processing, or refused with the reason. */
interface Pending {
  key: number;
  name: string;
  progress: number;
  error: string | null;
}

const PARALLEL = 3;

/**
 * /read/{slug}/upload (a new chapter) and /read/{slug}/upload/{id} (its editor).
 *
 * Details, then pages: drop images or one .cbz/.zip. Images go up three at a time, each with
 * its own progress and its own refusal; an archive goes up once, then the browser asks the
 * server to process a few pages at a time (it works without a queue worker, and picks up again
 * if the tab was closed). Pages can be dragged into order (or moved with the arrows on a phone),
 * removed, or sorted by file name. Then the attestation and Publish or Schedule.
 */
export default class EditorPage extends Page {
  chapter: Chapter | null = null;
  loading = true;
  saving = false;
  // The new-chapter form.
  number = '';
  volume = '';
  chapterTitle = '';
  // The release.
  when: 'now' | 'later' = 'now';
  at = '';
  attest = false;
  pending: Pending[] = [];
  queue: Array<{ file: File; item: Pending }> = [];
  running = 0;
  importing: { done: number; total: number; current: string | null; skipped: string[]; refused: Array<{ name: string; message: string }> } | null = null;
  dragFrom: number | null = null;
  dropping = false;
  private seq = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--panels';
    app.setTitle(tx('upload.title'));
    const id = m.route.param('id');
    if (id) this.load(Number(id));
    else this.suggest();
  }

  /** The next number after the series' highest, as a starting point. */
  suggest(): void {
    get<any>(`series/${encodeURIComponent(m.route.param('slug'))}`, {}, true)
      .then((r) => {
        this.chapter = null;
        const top = r.data.chapters.reduce((max: number, c: any) => Math.max(max, Math.floor(parseFloat(c.number))), 0);
        this.number = String(top + 1);
        (this as any).series = r.data;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  load(id: number): void {
    get<{ data: Chapter }>(`chapters/${id}`)
      .then((r) => {
        this.chapter = r.data;
        this.attest = r.data.attested;
        if (r.data.publishedAt && r.data.scheduled) {
          this.when = 'later';
          this.at = r.data.publishedAt.slice(0, 16);
        }
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  create(e: SubmitEvent): void {
    e.preventDefault();
    const series = (this as any).series;
    if (!series) return;
    this.saving = true;
    send<{ data: Chapter }>('POST', 'chapters', { seriesId: series.id, number: this.number, volume: this.volume, title: this.chapterTitle })
      .then((r) => m.route.set(app.route('panels.edit', { slug: series.slug, id: r.data.id })))
      .catch(() => {})
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  patch(body: Record<string, unknown>): Promise<void> {
    const c = this.chapter!;
    this.saving = true;
    return send<{ data: Chapter }>('PATCH', `chapters/${c.id}`, body)
      .then((r) => {
        this.chapter = r.data;
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  // ---- files ----

  addFiles(list: FileList | File[]): void {
    const files = Array.from(list);
    const archive = files.find((f) => /\.(cbz|zip)$/i.test(f.name));
    if (archive) {
      this.importArchive(archive);
      return;
    }
    files
      .filter((f) => /^image\//.test(f.type) || /\.(jpe?g|png|gif|webp|avif)$/i.test(f.name))
      .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }))
      .forEach((file) => {
        const item: Pending = { key: ++this.seq, name: file.name, progress: 0, error: null };
        this.pending.push(item);
        this.queue.push({ file, item });
      });
    this.pump();
    m.redraw();
  }

  /** Three uploads at a time; each answers for its own file. */
  pump(): void {
    while (this.running < PARALLEL && this.queue.length) {
      const { file, item } = this.queue.shift()!;
      this.running++;
      const form = new FormData();
      form.append('pages[]', file);
      upload(`chapters/${this.chapter!.id}/pages`, form, (f) => ((item.progress = f), m.redraw()))
        .then((r) => {
          if (r?.refused?.length) item.error = r.refused[0].message;
          else if (r?.error) item.error = r.error.message;
          else this.pending = this.pending.filter((p) => p !== item);
        })
        .catch((e) => (item.error = errorText(e)))
        .finally(() => {
          this.running--;
          if (!this.running && !this.queue.length) this.refresh();
          this.pump();
          m.redraw();
        });
    }
  }

  refresh(): void {
    if (this.chapter) get<{ data: Chapter }>(`chapters/${this.chapter.id}`).then((r) => ((this.chapter = r.data), m.redraw()));
  }

  importArchive(file: File): void {
    const max = Number(app.forum.attribute('panelsMaxArchiveMb') || 500) * 1024 * 1024;
    if (file.size > max) {
      app.alerts.show({ type: 'error' }, tx('upload.archive_too_big', { mb: app.forum.attribute('panelsMaxArchiveMb') }));
      return;
    }
    this.importing = { done: 0, total: 0, current: tx('upload.uploading_archive', { name: file.name }), skipped: [], refused: [] };
    const form = new FormData();
    form.append('archive', file);
    upload(`chapters/${this.chapter!.id}/import`, form, (f) => {
      this.importing!.current = tx('upload.uploading_percent', { name: file.name, percent: Math.round(f * 100) });
      m.redraw();
    })
      .then((r) => {
        if (r?.error) throw { response: r };
        this.step(r.import);
      })
      .catch((e) => {
        this.importing = null;
        app.alerts.show({ type: 'error' }, errorText(e));
        m.redraw();
      });
  }

  /** Ask the server for the next few pages until the archive is done. */
  step(state: any): void {
    this.importing = state;
    m.redraw();
    if (state.state !== 'running') {
      this.refresh();
      return;
    }
    send<any>('POST', `imports/${state.id}/step`)
      .then((r) => {
        this.refresh();
        this.step(r.import);
      })
      .catch(() => (this.importing = null));
  }

  reorder(ids: number[]): void {
    send('PATCH', `chapters/${this.chapter!.id}/pages`, { order: ids }).then(() => this.refresh());
  }

  /** First segments only: a tall strip moves as one page. */
  firsts(): EditorPageData[] {
    return (this.chapter?.pages || []).filter((p) => p.segment === 0);
  }

  move(from: number, to: number): void {
    const ids = this.firsts().map((p) => p.id);
    if (to < 0 || to >= ids.length) return;
    const [id] = ids.splice(from, 1);
    ids.splice(to, 0, id);
    this.reorder(ids);
  }

  sortByName(reverse = false): void {
    const pages = [...this.firsts()];
    pages.sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { numeric: true, sensitivity: 'base' }));
    if (reverse) pages.reverse();
    this.reorder(pages.map((p) => p.id));
  }

  // ---- drawing ----

  view() {
    if (this.loading) return <PageStructure className="PanelsEditor">{<LoadingIndicator />}</PageStructure>;
    return <PageStructure className="PanelsEditor">{this.chapter ? this.editor(this.chapter) : this.newForm()}</PageStructure>;
  }

  crumbs(slug: string, title: string): Mithril.Children {
    return (
      <nav className="PanelsCrumbs" aria-label={tx('breadcrumb')}>
        <Link href={app.route('panels')}>{label()}</Link> › <Link href={seriesHref(slug)}>{title}</Link> › <span>{this.chapter ? long(this.chapter.seriesFormat, this.chapter.number) : t('upload.new')}</span>
      </nav>
    );
  }

  newForm(): Mithril.Children {
    const series = (this as any).series;
    if (!series) return <p className="PanelsEmpty">{t('error.not_found')}</p>;
    return (
      <>
        {this.crumbs(series.slug, series.title)}
        <h1>{t('upload.new')}</h1>
        {this.steps(1)}
        <form className="PanelsCard PanelsEditor-new" onsubmit={(e: SubmitEvent) => this.create(e)}>
          <div className="PanelsForm-row">
            <div className="Form-group">
              <label for="panels-number">{t('upload.number')}</label>
              <input id="panels-number" className="FormControl" inputmode="decimal" required value={this.number} oninput={(e: InputEvent) => (this.number = (e.target as HTMLInputElement).value)} />
            </div>
            <div className="Form-group">
              <label for="panels-volume">{t('upload.volume')}</label>
              <input id="panels-volume" className="FormControl" inputmode="numeric" value={this.volume} oninput={(e: InputEvent) => (this.volume = (e.target as HTMLInputElement).value)} />
            </div>
          </div>
          <div className="Form-group">
            <label for="panels-ctitle">{t('upload.chapter_title')}</label>
            <input id="panels-ctitle" className="FormControl" maxlength={150} value={this.chapterTitle} oninput={(e: InputEvent) => (this.chapterTitle = (e.target as HTMLInputElement).value)} />
          </div>
          <Button type="submit" className="Button Button--primary" loading={this.saving}>
            {t('upload.continue')}
          </Button>
        </form>
      </>
    );
  }

  steps(at: number): Mithril.Children {
    return (
      <ol className="PanelsSteps">
        {['details', 'pages', 'publish'].map((key, i) => (
          <li className={i + 1 < at ? 'is-done' : i + 1 === at ? 'is-on' : ''} aria-current={i + 1 === at ? 'step' : undefined}>
            <span className="PanelsSteps-dot">{i + 1 < at ? icon('fas fa-check') : i + 1}</span>
            {t(`upload.step_${key}`)}
          </li>
        ))}
      </ol>
    );
  }

  editor(c: Chapter): Mithril.Children {
    const out = c.discussionId !== null;
    const staff = !!app.forum.attribute('panelsCanModerate');
    const locked = out && !staff;
    const at = c.pages.length || c.source === 'external' ? 3 : 2;
    return (
      <>
        {this.crumbs(c.seriesSlug, c.seriesTitle)}
        <h1>
          {long(c.seriesFormat, c.number)}
          {c.title ? `: ${c.title}` : ''}
        </h1>
        {this.steps(at)}
        {c.state === 'pending' ? <div className="PanelsNotice">{t('upload.pending')}</div> : null}
        {c.state === 'held' ? <div className="PanelsNotice PanelsNotice--danger">{t('upload.held')}</div> : null}
        {locked ? <div className="PanelsNotice">{t('upload.out_locked')}</div> : null}
        <div className="PanelsEditor-body">
          <div className="PanelsEditor-main">{c.source === 'external' ? this.externalForm(c) : this.pagesPanel(c, locked)}</div>
          <aside className="PanelsCard PanelsEditor-side">{this.side(c, out)}</aside>
        </div>
      </>
    );
  }

  externalForm(c: Chapter): Mithril.Children {
    return (
      <div className="PanelsCard">
        <div className="Form-group">
          <label for="panels-ext">{t('upload.external_url')}</label>
          <input id="panels-ext" className="FormControl" type="url" placeholder="https://" value={c.externalUrl || ''} onchange={(e: Event) => this.patch({ externalUrl: (e.target as HTMLInputElement).value })} />
          <p className="helpText">{t('upload.external_help')}</p>
        </div>
        <Button className="Button Button--link" onclick={() => this.patch({ source: 'upload' })}>
          {t('upload.switch_upload')}
        </Button>
      </div>
    );
  }

  pagesPanel(c: Chapter, locked: boolean): Mithril.Children {
    const firsts = this.firsts();
    const imp = this.importing;
    return (
      <>
        {!locked ? (
          <div
            className={'PanelsDrop' + (this.dropping ? ' is-over' : '')}
            ondragover={(e: DragEvent) => {
              if (e.dataTransfer?.types.includes('Files')) {
                e.preventDefault();
                this.dropping = true;
              }
            }}
            ondragleave={() => (this.dropping = false)}
            ondrop={(e: DragEvent) => {
              if (!e.dataTransfer?.files.length) return;
              e.preventDefault();
              this.dropping = false;
              this.addFiles(e.dataTransfer.files);
            }}
          >
            {icon('fas fa-cloud-arrow-up', { className: 'PanelsDrop-icon' })}
            <strong>{t('upload.drop')}</strong>
            <span className="PanelsMuted">
              {t('upload.limits', { mb: app.forum.attribute('panelsMaxImageMb'), pages: app.forum.attribute('panelsMaxPages') })}
            </span>
            <span className="PanelsDrop-buttons">
              <label className="Button Button--primary">
                {t('upload.choose')}
                <input
                  type="file"
                  className="visually-hidden"
                  multiple
                  accept="image/jpeg,image/png,image/webp,image/avif,image/gif,.cbz,.zip"
                  onchange={(e: Event) => {
                    const input = e.target as HTMLInputElement;
                    if (input.files) this.addFiles(input.files);
                    input.value = '';
                  }}
                />
              </label>
              <Button className="Button" onclick={() => this.patch({ source: 'external' })}>
                {t('upload.hosted_elsewhere')}
              </Button>
            </span>
          </div>
        ) : null}

        {imp ? (
          <div className="PanelsCard PanelsImport" role="status">
            <strong>{imp.total ? t('upload.processing', { done: imp.done, total: imp.total }) : imp.current}</strong>
            {imp.total ? (
              <span className="PanelsBar" aria-hidden="true">
                <span style={{ width: `${Math.round((imp.done / Math.max(1, imp.total)) * 100)}%` }} />
              </span>
            ) : null}
            {imp.total && imp.current ? <span className="PanelsMuted">{t('upload.keep_open', { name: imp.current })}</span> : null}
            {imp.skipped?.length ? <span className="PanelsMuted">{t('upload.skipped', { count: imp.skipped.length, names: imp.skipped.slice(0, 4).join(', ') })}</span> : null}
            {(imp.refused || []).map((r) => (
              <span className="PanelsError">
                {r.name}: {r.message}
              </span>
            ))}
          </div>
        ) : null}

        <div className="PanelsEditor-toolbar">
          <strong>{t('pages', { count: firsts.length })}</strong>
          {!locked && firsts.length > 1 ? (
            <span>
              <Button className="Button" onclick={() => this.sortByName()}>
                {t('upload.sort_name')}
              </Button>
              <Button className="Button" onclick={() => this.sortByName(true)}>
                {t('upload.reverse')}
              </Button>
            </span>
          ) : null}
        </div>

        <ol className="PanelsThumbs">
          {firsts.map((p, i) => (
            <li
              className="PanelsThumb"
              draggable={!locked}
              ondragstart={() => (this.dragFrom = i)}
              ondragover={(e: DragEvent) => this.dragFrom !== null && e.preventDefault()}
              ondrop={(e: DragEvent) => {
                e.preventDefault();
                if (this.dragFrom !== null && this.dragFrom !== i) this.move(this.dragFrom, i);
                this.dragFrom = null;
              }}
            >
              <span className="PanelsThumb-art">
                <img src={p.sources[Object.keys(p.sources).sort((a, b) => Number(a) - Number(b))[0]]} alt={tx('reader.page_alt', { page: i + 1 })} loading="lazy" />
                <span className="PanelsThumb-n">{i + 1}</span>
              </span>
              <span className="PanelsThumb-name">{p.name || '—'}</span>
              {!locked ? (
                <span className="PanelsThumb-tools">
                  <button type="button" aria-label={tx('upload.move_earlier')} disabled={i === 0} onclick={() => this.move(i, i - 1)}>
                    {icon('fas fa-arrow-left')}
                  </button>
                  <button type="button" aria-label={tx('upload.move_later')} disabled={i === firsts.length - 1} onclick={() => this.move(i, i + 1)}>
                    {icon('fas fa-arrow-right')}
                  </button>
                  <button
                    type="button"
                    aria-label={tx('upload.remove')}
                    onclick={() => window.confirm(tx('upload.remove_confirm')) && send('DELETE', `pages/${p.id}`).then(() => this.refresh())}
                  >
                    {icon('fas fa-trash')}
                  </button>
                </span>
              ) : null}
            </li>
          ))}
          {this.pending.map((p) => (
            <li className={'PanelsThumb is-pending' + (p.error ? ' is-error' : '')}>
              <span className="PanelsThumb-art">
                {p.error ? (
                  <span className="PanelsThumb-error">
                    <strong>{t('upload.refused')}</strong>
                    {p.error}
                  </span>
                ) : (
                  <span className="PanelsThumb-progress">
                    <span className="PanelsSpinner" aria-hidden="true" />
                    {p.progress >= 1 ? t('upload.processing_one') : `${Math.round(p.progress * 100)}%`}
                  </span>
                )}
              </span>
              <span className="PanelsThumb-name">{p.name}</span>
              {p.error ? (
                <span className="PanelsThumb-tools">
                  <button type="button" aria-label={tx('upload.dismiss')} onclick={() => (this.pending = this.pending.filter((x) => x !== p))}>
                    {icon('fas fa-xmark')}
                  </button>
                </span>
              ) : null}
            </li>
          ))}
        </ol>
      </>
    );
  }

  side(c: Chapter, out: boolean): Mithril.Children {
    const kiosk = !!app.forum.attribute('panelsKiosk');
    const busy = this.running > 0 || this.queue.length > 0 || this.importing?.state === 'running';
    const ratings = ['all', 'teen', 'mature', ...(app.forum.attribute('panelsExplicit') ? ['explicit'] : [])];
    return (
      <>
        <div className="PanelsForm-row">
          <div className="Form-group">
            <label for="panels-n">{t('upload.number')}</label>
            <input id="panels-n" className="FormControl" value={c.number} onchange={(e: Event) => this.patch({ number: (e.target as HTMLInputElement).value })} />
          </div>
          <div className="Form-group">
            <label for="panels-v">{t('upload.volume')}</label>
            <input id="panels-v" className="FormControl" value={c.volume ?? ''} onchange={(e: Event) => this.patch({ volume: (e.target as HTMLInputElement).value })} />
          </div>
        </div>
        <div className="Form-group">
          <label for="panels-t">{t('upload.chapter_title')}</label>
          <input id="panels-t" className="FormControl" value={c.title || ''} onchange={(e: Event) => this.patch({ title: (e.target as HTMLInputElement).value })} />
        </div>
        <div className="Form-group">
          <label for="panels-r">{t('upload.rating')}</label>
          <select id="panels-r" className="FormControl" onchange={(e: Event) => this.patch({ contentRating: (e.target as HTMLSelectElement).value || null })}>
            <option value="" selected={!c.contentRating}>
              {t('upload.rating_series')}
            </option>
            {ratings.map((r) => (
              <option value={r} selected={c.contentRating === r}>
                {tx(`rating.${r}`)}
              </option>
            ))}
          </select>
        </div>
        {kiosk ? (
          <div className="Form-group">
            <label for="panels-a">{t('upload.access')}</label>
            <select id="panels-a" className="FormControl" onchange={(e: Event) => this.patch({ access: (e.target as HTMLSelectElement).value, earlyAccessUntil: c.earlyAccessUntil })}>
              {['free', 'paid', 'early'].map((a) => (
                <option value={a} selected={c.access === a}>
                  {t(`upload.access_${a}`)}
                </option>
              ))}
            </select>
            {c.access === 'early' ? (
              <input
                className="FormControl"
                type="datetime-local"
                aria-label={tx('upload.early_until')}
                value={(c.earlyAccessUntil || '').slice(0, 16)}
                onchange={(e: Event) => this.patch({ access: 'early', earlyAccessUntil: (e.target as HTMLInputElement).value })}
              />
            ) : null}
            <p className="helpText">{t('upload.access_help')}</p>
          </div>
        ) : null}
        <div className="Form-group">
          <label for="panels-note">{t('upload.note')}</label>
          <textarea id="panels-note" className="FormControl" rows={3} maxlength={3000} value={c.note || ''} onchange={(e: Event) => this.patch({ note: (e.target as HTMLTextAreaElement).value })} />
          <p className="helpText">{t('upload.note_help')}</p>
        </div>
        <details className="Form-group">
          <summary>{t('upload.transcript')}</summary>
          <textarea className="FormControl" rows={5} value={c.transcript || ''} aria-label={tx('upload.transcript')} onchange={(e: Event) => this.patch({ transcript: (e.target as HTMLTextAreaElement).value })} />
          <p className="helpText">{t('upload.transcript_help')}</p>
        </details>
        {!out ? (
          <>
            <fieldset className="Form-group PanelsRelease">
              <legend>{t('upload.release')}</legend>
              <label>
                <input type="radio" name="panels-when" checked={this.when === 'now'} onchange={() => (this.when = 'now')} /> {t('upload.now')}
              </label>
              <label>
                <input type="radio" name="panels-when" checked={this.when === 'later'} onchange={() => (this.when = 'later')} /> {t('upload.later')}
              </label>
              {this.when === 'later' ? (
                <input className="FormControl" type="datetime-local" aria-label={tx('upload.later')} value={this.at} oninput={(e: InputEvent) => (this.at = (e.target as HTMLInputElement).value)} />
              ) : null}
            </fieldset>
            <label className="PanelsAttest">
              <input type="checkbox" checked={this.attest} onchange={() => (this.attest = !this.attest)} />
              <span>
                {t('upload.attest')} <Link href={app.route('panels.copyright')}>{t('upload.policy')}</Link>
              </span>
            </label>
            <Link className="Button" href={readerHref(c.seriesSlug, c.number)}>
              {t('upload.preview')}
            </Link>
            <Button
              className="Button Button--primary"
              loading={this.saving}
              disabled={busy || !this.attest || (c.source === 'upload' && !c.pages.length) || (this.when === 'later' && !this.at)}
              onclick={() =>
                this.patch({ attest: true, action: 'publish', at: this.when === 'later' ? new Date(this.at).toISOString() : null }).then(() => {
                  const ch = this.chapter!;
                  app.alerts.show({ type: 'success' }, tx(ch.state === 'pending' ? 'upload.done_pending' : ch.state === 'held' ? 'upload.done_held' : ch.scheduled ? 'upload.done_scheduled' : 'upload.done_published'));
                  m.route.set(seriesHref(ch.seriesSlug));
                })
              }
            >
              {busy ? t('upload.wait') : this.when === 'later' ? t('upload.schedule') : t('upload.publish')}
            </Button>
            {c.state === 'draft' ? (
              <Button
                className="Button Button--link PanelsDanger"
                onclick={() => window.confirm(tx('upload.delete_confirm')) && send('DELETE', `chapters/${c.id}`).then(() => m.route.set(seriesHref(c.seriesSlug)))}
              >
                {t('upload.delete')}
              </Button>
            ) : (
              <Button className="Button Button--link" onclick={() => this.patch({ action: 'unpublish' })}>
                {t('upload.back_to_draft')}
              </Button>
            )}
          </>
        ) : (
          <Link className="Button" href={readerHref(c.seriesSlug, c.number)}>
            {t('upload.open_reader')}
          </Link>
        )}
      </>
    );
  }
}
