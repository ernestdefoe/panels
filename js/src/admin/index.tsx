import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { registerEditorWidgets } from '../common/fofWidgets';

const EXT = 'ernestdefoe-panels';
const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.admin.${key}`, params);
const api = (path: string) => `${app.forum.attribute('apiUrl')}/panels/${path}`;

/** The genre list: add, rename, reorder, delete. Saved as you go. */
class Genres {
  rows: Array<{ id: number; name: string; position: number; seriesCount: number }> | null = null;
  adding = '';

  oninit() {
    this.load();
  }

  load() {
    app.request<any>({ method: 'GET', url: api('genres') }).then((r) => ((this.rows = r.data), m.redraw()));
  }

  view() {
    const rows = this.rows || [];
    return (
      <div className="Form-group PanelsAdminList">
        <label>{t('genres.label')}</label>
        <p className="helpText">{t('genres.help')}</p>
        <ul>
          {rows.map((g, i) => (
            <li>
              <input
                className="FormControl"
                value={g.name}
                aria-label={app.translator.trans(`${EXT}.admin.genres.name`) as string}
                onchange={(e: Event) =>
                  app
                    .request({ method: 'PATCH', url: api(`genres/${g.id}`), body: { name: (e.target as HTMLInputElement).value } })
                    .then(() => this.load())
                }
              />
              <span className="helpText">{t('genres.count', { count: g.seriesCount })}</span>
              <Button
                className="Button Button--icon"
                icon="fas fa-arrow-up"
                aria-label={t('genres.up')}
                disabled={i === 0}
                onclick={() => this.swap(i, i - 1)}
              />
              <Button
                className="Button Button--icon"
                icon="fas fa-trash"
                aria-label={t('genres.delete')}
                onclick={() =>
                  window.confirm(String(t('genres.delete_confirm'))) &&
                  app.request({ method: 'DELETE', url: api(`genres/${g.id}`) }).then(() => this.load())
                }
              />
            </li>
          ))}
        </ul>
        <div className="PanelsAdminList-add">
          <input
            className="FormControl"
            value={this.adding}
            placeholder={String(t('genres.new'))}
            oninput={(e: InputEvent) => (this.adding = (e.target as HTMLInputElement).value)}
          />
          <Button
            className="Button"
            disabled={!this.adding.trim()}
            onclick={() =>
              app.request({ method: 'POST', url: api('genres'), body: { name: this.adding } }).then(() => ((this.adding = ''), this.load()))
            }
          >
            {t('genres.add')}
          </Button>
        </div>
      </div>
    );
  }

  swap(a: number, b: number) {
    const rows = this.rows!;
    Promise.all([
      app.request({ method: 'PATCH', url: api(`genres/${rows[a].id}`), body: { position: rows[b].position } }),
      app.request({ method: 'PATCH', url: api(`genres/${rows[b].id}`), body: { position: rows[a].position } }),
    ]).then(() => this.load());
  }
}

/** Custom series fields, stored as JSON in one setting. Ids never change once given. */
class Fields {
  view(vnode: Mithril.Vnode<{ setting: any }>) {
    const stream = vnode.attrs.setting;
    let rows: Array<{ id: number; name: string; type: string; options?: string[] }> = [];
    try {
      rows = JSON.parse(stream() || '[]');
    } catch (e) {
      rows = [];
    }
    const save = (next: typeof rows) => stream(JSON.stringify(next));
    return (
      <div className="Form-group PanelsAdminList">
        <label>{t('fields.label')}</label>
        <p className="helpText">{t('fields.help')}</p>
        <ul>
          {rows.map((f, i) => (
            <li>
              <input
                className="FormControl"
                value={f.name}
                aria-label={String(t('fields.name'))}
                oninput={(e: InputEvent) => save(rows.map((r, j) => (j === i ? { ...r, name: (e.target as HTMLInputElement).value } : r)))}
              />
              <select
                className="FormControl"
                aria-label={String(t('fields.type'))}
                onchange={(e: Event) => save(rows.map((r, j) => (j === i ? { ...r, type: (e.target as HTMLSelectElement).value } : r)))}
              >
                {['text', 'person', 'link', 'year', 'select'].map((type) => (
                  <option value={type} selected={f.type === type}>
                    {t(`fields.type_${type}`)}
                  </option>
                ))}
              </select>
              {f.type === 'select' ? (
                <input
                  className="FormControl"
                  value={(f.options || []).join(', ')}
                  placeholder={String(t('fields.options'))}
                  aria-label={String(t('fields.options'))}
                  oninput={(e: InputEvent) =>
                    save(
                      rows.map((r, j) =>
                        j === i
                          ? {
                              ...r,
                              options: (e.target as HTMLInputElement).value
                                .split(',')
                                .map((o) => o.trim())
                                .filter(Boolean),
                            }
                          : r
                      )
                    )
                  }
                />
              ) : null}
              <Button
                className="Button Button--icon"
                icon="fas fa-trash"
                aria-label={t('fields.delete')}
                onclick={() => save(rows.filter((_, j) => j !== i))}
              />
            </li>
          ))}
        </ul>
        <Button
          className="Button"
          onclick={() => save([...rows, { id: rows.reduce((max, r) => Math.max(max, r.id), 0) + 1, name: '', type: 'text' }])}
        >
          {t('fields.add')}
        </Button>
      </div>
    );
  }
}

/** Daily .cbz downloads per group: {groupId: n}. */
class Downloads {
  view(vnode: Mithril.Vnode<{ setting: any }>) {
    const stream = vnode.attrs.setting;
    let limits: Record<string, number> = {};
    try {
      limits = JSON.parse(stream() || '{}') || {};
    } catch (e) {
      limits = {};
    }
    const groups = app.store.all<any>('groups').filter((g: any) => g.id() !== '2');
    return (
      <div className="Form-group PanelsAdminList">
        <label>{t('downloads.label')}</label>
        <p className="helpText">{t('downloads.help')}</p>
        <ul>
          {groups.map((g: any) => (
            <li>
              <span className="PanelsAdminList-name">{g.namePlural()}</span>
              <input
                className="FormControl"
                type="number"
                min="0"
                max="1000"
                value={limits[g.id()] ?? 0}
                aria-label={String(t('downloads.per_day', { group: g.namePlural() }))}
                oninput={(e: InputEvent) => stream(JSON.stringify({ ...limits, [g.id()]: Number((e.target as HTMLInputElement).value) || 0 }))}
              />
            </li>
          ))}
        </ul>
      </div>
    );
  }
}

class Watermark {
  busy = false;
  view() {
    return (
      <div className="Form-group">
        <label>{t('watermark.image')}</label>
        <p className="helpText">{t('watermark.image_help')}</p>
        <input
          type="file"
          accept="image/png"
          disabled={this.busy}
          onchange={(e: Event) => {
            const file = (e.target as HTMLInputElement).files?.[0];
            if (!file) return;
            const form = new FormData();
            form.append('watermark', file);
            this.busy = true;
            app
              .request({ method: 'POST', url: api('watermark'), serialize: (raw: any) => raw, body: form } as any)
              .then(() => app.alerts.show({ type: 'success' }, t('watermark.saved')))
              .finally(() => ((this.busy = false), m.redraw()));
          }}
        />
        <Button
          className="Button Button--link"
          onclick={() =>
            app.request({ method: 'DELETE', url: api('watermark') }).then(() => app.alerts.show({ type: 'success' }, t('watermark.removed')))
          }
        >
          {t('watermark.remove')}
        </Button>
      </div>
    );
  }
}

const s = (key: string) => `${EXT}.${key}`;
const tt = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.admin.${key}`, params, true);

export const extend = [
  new Extend.Admin()
    .setting(
      () => ({
        setting: s('label_plural'),
        type: 'text',
        label: tt('settings.label_plural'),
        help: tt('settings.label_plural_help'),
        placeholder: 'Comics',
      }),
      200
    )
    .setting(() => ({ setting: s('label_singular'), type: 'text', label: tt('settings.label_singular'), placeholder: 'Comic' }), 199)
    .customSetting(function (this: any) {
      const tags = app.store.all<any>('tags');
      if (!tags.length) return null;
      return this.buildSettingComponent({
        setting: s('tag_id'),
        type: 'select',
        label: tt('settings.tag'),
        help: tt('settings.tag_help'),
        options: Object.fromEntries([['0', tt('settings.tag_none')], ...tags.map((tag: any) => [tag.id(), tag.name()])]),
        default: '0',
      });
    }, 190)
    .setting(
      () => ({ setting: s('approve_members'), type: 'boolean', label: tt('settings.approve_members'), help: tt('settings.approve_members_help') }),
      180
    )
    .setting(() => ({ setting: s('explicit'), type: 'boolean', label: tt('settings.explicit'), help: tt('settings.explicit_help') }), 170)
    .setting(() => ({ setting: s('mature_min_days'), type: 'number', min: 0, label: tt('settings.mature_min_days') }), 169)
    .setting(() => ({ setting: s('mature_min_posts'), type: 'number', min: 0, label: tt('settings.mature_min_posts') }), 168)
    .setting(() => ({ setting: s('max_image_mb'), type: 'number', min: 1, label: tt('settings.max_image_mb') }), 160)
    .setting(
      () => ({
        setting: s('max_megapixels'),
        type: 'number',
        min: 1,
        label: tt('settings.max_megapixels'),
        help: tt('settings.max_megapixels_help'),
      }),
      159
    )
    .setting(() => ({ setting: s('max_pages'), type: 'number', min: 1, label: tt('settings.max_pages') }), 158)
    .setting(() => ({ setting: s('max_chapter_mb'), type: 'number', min: 1, label: tt('settings.max_chapter_mb') }), 157)
    .setting(() => ({ setting: s('max_archive_mb'), type: 'number', min: 1, label: tt('settings.max_archive_mb') }), 156)
    .setting(
      () => ({
        setting: s('pages_per_hour'),
        type: 'number',
        min: 1,
        label: tt('settings.pages_per_hour'),
        help: tt('settings.pages_per_hour_help'),
      }),
      155.5
    )
    .setting(() => ({ setting: s('quality'), type: 'number', min: 40, max: 95, label: tt('settings.quality') }), 155)
    .setting(() => ({ setting: s('strip_width'), type: 'number', min: 400, max: 1600, label: tt('settings.strip_width') }), 154)
    .setting(
      () => ({ setting: s('stock_sidebar'), type: 'boolean', label: tt('settings.stock_sidebar'), help: tt('settings.stock_sidebar_help') }),
      150
    )
    .setting(() => ({ setting: s('watermark'), type: 'boolean', label: tt('watermark.on'), help: tt('watermark.on_help') }), 140)
    .customSetting(() => <Watermark />, 139)
    .setting(
      () => ({
        setting: s('watermark_position'),
        type: 'select',
        label: tt('watermark.position'),
        options: { br: tt('watermark.br'), bl: tt('watermark.bl'), tr: tt('watermark.tr'), tl: tt('watermark.tl'), center: tt('watermark.center') },
        default: 'br',
      }),
      138
    )
    .setting(() => ({ setting: s('watermark_opacity'), type: 'number', min: 5, max: 100, label: tt('watermark.opacity') }), 137)
    .setting(() => ({ setting: s('watermark_size'), type: 'number', min: 5, max: 50, label: tt('watermark.size') }), 136)
    .customSetting(function (this: any) {
      return <Downloads setting={this.setting(s('download_limits'))} />;
    }, 130)
    .customSetting(function (this: any) {
      return <Fields setting={this.setting(s('fields'))} />;
    }, 120)
    .customSetting(() => <Genres />, 110)
    .setting(() => ({ setting: s('agent_name'), type: 'text', label: tt('settings.agent_name'), help: tt('settings.agent_help') }), 100)
    .setting(() => ({ setting: s('agent_address'), type: 'textarea', label: tt('settings.agent_address') }), 99)
    .setting(() => ({ setting: s('agent_email'), type: 'email', label: tt('settings.agent_email') }), 98)
    .setting(
      () => ({ setting: s('strike_limit'), type: 'number', min: 1, label: tt('settings.strike_limit'), help: tt('settings.strike_limit_help') }),
      90
    )
    .setting(() => ({ setting: s('quarantine_days'), type: 'number', min: 1, label: tt('settings.quarantine_days') }), 89)
    .setting(
      () => ({
        setting: s('x_sendfile'),
        type: 'select',
        label: tt('settings.x_sendfile'),
        help: tt('settings.x_sendfile_help'),
        options: { '': tt('settings.x_sendfile_php'), nginx: 'nginx (X-Accel-Redirect)', apache: 'Apache (X-Sendfile)' },
        default: '',
      }),
      80
    )
    .permission(() => ({ permission: 'panels.view', icon: 'fas fa-book-open', label: tt('permissions.view'), allowGuest: true }), 'view', 50)
    .permission(() => ({ permission: 'panels.viewMature', icon: 'fas fa-user-shield', label: tt('permissions.view_mature') }), 'view', 49)
    .permission(
      () => ({ permission: 'panels.viewExplicit', icon: 'fas fa-triangle-exclamation', label: tt('permissions.view_explicit') }),
      'view',
      48
    )
    .permission(() => ({ permission: 'panels.download', icon: 'fas fa-download', label: tt('permissions.download') }), 'view', 47)
    .permission(() => ({ permission: 'panels.rate', icon: 'fas fa-star', label: tt('permissions.rate') }), 'reply', 50)
    .permission(() => ({ permission: 'panels.create', icon: 'fas fa-upload', label: tt('permissions.create') }), 'start', 50)
    .permission(() => ({ permission: 'panels.publishWithoutApproval', icon: 'fas fa-check', label: tt('permissions.publish') }), 'start', 49)
    .permission(() => ({ permission: 'panels.moderate', icon: 'fas fa-gavel', label: tt('permissions.moderate') }), 'moderate', 50),
];

app.initializers.add(EXT, () => {
  registerEditorWidgets(app, EXT, [
    { key: 'panelsNew', icon: 'fas fa-bolt', title: () => app.translator.trans(`${EXT}.lib.widget.new`) },
    { key: 'panelsContinue', icon: 'fas fa-book-open-reader', title: () => app.translator.trans(`${EXT}.lib.widget.continue`) },
    { key: 'panelsTrending', icon: 'fas fa-fire', title: () => app.translator.trans(`${EXT}.lib.widget.trending`) },
    { key: 'panelsSpotlight', icon: 'fas fa-star', title: () => app.translator.trans(`${EXT}.lib.widget.spotlight`), placement: 'top' },
    { key: 'panelsStats', icon: 'fas fa-chart-simple', title: () => app.translator.trans(`${EXT}.lib.widget.stats`) },
  ]);
});
