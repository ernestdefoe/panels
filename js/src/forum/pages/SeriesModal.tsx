import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { get, send, seriesHref, t, tx, upload } from '../util';
import type { SeriesFull } from './SeriesPage';

interface Attrs extends IFormModalAttrs {
  series?: SeriesFull;
  onsaved?: () => void;
}

const FORMATS = ['manga', 'manhwa', 'manhua', 'webtoon', 'comic', 'other'];
const MODES = ['rtl', 'ltr', 'vertical'];
const STATUSES = ['ongoing', 'completed', 'hiatus', 'cancelled'];

export function openSeriesModal(series?: SeriesFull, onsaved?: () => void): void {
  app.modal.show(SeriesModal, { series, onsaved });
}

/**
 * Starting or changing a series: names, cover, format and reading mode, status, rating,
 * genres, the admin's custom fields, and co-editors.
 */
export default class SeriesModal extends FormModal<Attrs> {
  title_ = '';
  altTitles = '';
  description = '';
  format = 'manga';
  readingMode = 'rtl';
  status = 'ongoing';
  contentRating = 'all';
  genreIds: number[] = [];
  fields: Record<string, string> = {};
  editors = '';
  cover: File | null = null;
  genres: Array<{ id: number; name: string }> = [];
  modeTouched = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const s = this.attrs.series;
    if (s) {
      this.title_ = s.title;
      this.altTitles = s.altTitles.join('\n');
      this.description = s.description || '';
      this.format = s.format;
      this.readingMode = s.readingMode;
      this.status = s.status;
      this.contentRating = s.contentRating;
      this.genreIds = s.genres.map((g) => g.id);
      this.fields = { ...(s.rawFields || {}) };
      this.editors = s.editors.map((e) => e.username).join(', ');
      this.modeTouched = true;
    }
    get('genres', {}, true).then((r) => ((this.genres = r.data), m.redraw()), () => {});
  }

  className() {
    return 'PanelsSeriesModal Modal--large';
  }

  title() {
    return this.attrs.series ? t('series.edit') : t('start_series');
  }

  /** A format suggests a reading mode until the member picks one. */
  setFormat(format: string): void {
    this.format = format;
    if (!this.modeTouched) this.readingMode = format === 'manga' ? 'rtl' : ['webtoon', 'manhwa', 'manhua'].includes(format) ? 'vertical' : 'ltr';
  }

  content() {
    const fields: Array<{ id: number; name: string; type: string; options?: string[] }> = (app.forum.attribute('panelsFields') as any) || [];
    const ratings = ['all', 'teen', 'mature', ...(app.forum.attribute('panelsExplicit') ? ['explicit'] : [])];
    const select = (value: string, options: string[], prefix: string, onchange: (v: string) => void) => (
      <select className="FormControl" value={value} onchange={(e: Event) => onchange((e.target as HTMLSelectElement).value)}>
        {options.map((o) => (
          <option value={o} selected={o === value}>
            {tx(`${prefix}.${o}`)}
          </option>
        ))}
      </select>
    );
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="panels-title">{t('form.title')}</label>
            <input id="panels-title" className="FormControl" maxlength={150} required value={this.title_} oninput={(e: InputEvent) => (this.title_ = (e.target as HTMLInputElement).value)} />
          </div>
          <div className="Form-group">
            <label for="panels-alt">{t('form.alt_titles')}</label>
            <textarea id="panels-alt" className="FormControl" rows={2} value={this.altTitles} oninput={(e: InputEvent) => (this.altTitles = (e.target as HTMLTextAreaElement).value)} />
            <p className="helpText">{t('form.alt_titles_help')}</p>
          </div>
          <div className="Form-group">
            <label for="panels-desc">{t('form.description')}</label>
            <textarea id="panels-desc" className="FormControl" rows={4} maxlength={5000} value={this.description} oninput={(e: InputEvent) => (this.description = (e.target as HTMLTextAreaElement).value)} />
          </div>
          <div className="PanelsForm-row">
            <div className="Form-group">
              <label>{t('form.format')}</label>
              {select(this.format, FORMATS, 'format', (v) => this.setFormat(v))}
            </div>
            <div className="Form-group">
              <label>{t('form.mode')}</label>
              {select(this.readingMode, MODES, 'mode', (v) => ((this.readingMode = v), (this.modeTouched = true)))}
            </div>
            <div className="Form-group">
              <label>{t('form.status')}</label>
              {select(this.status, STATUSES, 'status', (v) => (this.status = v))}
            </div>
            <div className="Form-group">
              <label>{t('form.rating')}</label>
              {select(this.contentRating, ratings, 'rating', (v) => (this.contentRating = v))}
            </div>
          </div>
          {this.contentRating === 'explicit' ? <p className="PanelsNotice">{t('form.explicit_note')}</p> : null}
          <div className="Form-group">
            <label>{t('form.genres')}</label>
            <div className="PanelsChips PanelsChips--wrap">
              {this.genres.map((g) => {
                const on = this.genreIds.includes(g.id);
                return (
                  <button
                    type="button"
                    className={'PanelsChip' + (on ? ' is-on' : '')}
                    aria-pressed={on ? 'true' : 'false'}
                    onclick={() => (this.genreIds = on ? this.genreIds.filter((id) => id !== g.id) : [...this.genreIds, g.id].slice(0, 8))}
                  >
                    {g.name}
                  </button>
                );
              })}
            </div>
          </div>
          {fields.length ? (
            <div className="PanelsForm-row">
              {fields.map((f) => (
                <div className="Form-group">
                  <label for={`panels-field-${f.id}`}>{f.name}</label>
                  {f.type === 'select' ? (
                    <select id={`panels-field-${f.id}`} className="FormControl" onchange={(e: Event) => (this.fields[String(f.id)] = (e.target as HTMLSelectElement).value)}>
                      <option value="">—</option>
                      {(f.options || []).map((o) => (
                        <option value={o} selected={this.fields[String(f.id)] === o}>
                          {o}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      id={`panels-field-${f.id}`}
                      className="FormControl"
                      type={f.type === 'link' ? 'url' : f.type === 'year' ? 'number' : 'text'}
                      placeholder={f.type === 'link' ? 'https://' : ''}
                      value={this.fields[String(f.id)] || ''}
                      oninput={(e: InputEvent) => (this.fields[String(f.id)] = (e.target as HTMLInputElement).value)}
                    />
                  )}
                </div>
              ))}
            </div>
          ) : null}
          <div className="Form-group">
            <label for="panels-cover">{t('form.cover')}</label>
            <input id="panels-cover" className="FormControl" type="file" accept="image/jpeg,image/png,image/webp,image/avif" onchange={(e: Event) => (this.cover = (e.target as HTMLInputElement).files?.[0] || null)} />
            <p className="helpText">{t('form.cover_help')}</p>
          </div>
          {!this.attrs.series || this.attrs.series.canModerate || this.attrs.series.owner?.username === app.session.user?.username() ? (
            <div className="Form-group">
              <label for="panels-editors">{t('form.editors')}</label>
              <input id="panels-editors" className="FormControl" value={this.editors} placeholder={tx('form.editors_placeholder')} oninput={(e: InputEvent) => (this.editors = (e.target as HTMLInputElement).value)} />
            </div>
          ) : null}
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary" loading={this.loading}>
              {this.attrs.series ? t('form.save') : t('form.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const body = {
      title: this.title_,
      altTitles: this.altTitles,
      description: this.description,
      format: this.format,
      readingMode: this.readingMode,
      status: this.status,
      contentRating: this.contentRating,
      genres: this.genreIds,
      fields: this.fields,
      editors: this.editors.split(',').map((n) => n.trim().replace(/^@/, '')).filter(Boolean),
    };
    const s = this.attrs.series;
    (s ? send<any>('PATCH', `series/${s.id}`, body) : send<any>('POST', 'series', body))
      .then(async (r) => {
        if (this.cover) {
          const form = new FormData();
          form.append('cover', this.cover);
          const answer = await upload(`series/${r.data.id}/cover`, form);
          if (answer?.error) app.alerts.show({ type: 'error' }, answer.error.message);
        }
        this.hide();
        if (s) this.attrs.onsaved?.();
        else m.route.set(seriesHref(r.data.slug));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
