import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { label, send, t, tx } from '../util';
import { PageStructure } from './core';

/**
 * /read/copyright: the forum's copyright agent, the claim form (anyone, guests too), and the
 * uploader's counter-notice (?counter={claim id}, from their notification).
 */
export default class CopyrightPage extends Page {
  targetType = 'chapter';
  targetId = 0;
  counterId = 0;
  sent = false;
  busy = false;
  form: Record<string, string> = { name: '', email: '', org: '', work: '', urls: '', signature: '', address: '', statement: '' };
  goodFaith = false;
  accurate = false;
  consent = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--panels';
    app.setTitle(tx('copyright.title'));
    this.targetType = m.route.param('type') === 'series' ? 'series' : 'chapter';
    this.targetId = Number(m.route.param('id')) || 0;
    this.counterId = Number(m.route.param('counter')) || 0;
    if (this.targetId)
      this.form.urls = document.referrer && document.referrer.startsWith(app.forum.attribute('baseUrl') as string) ? document.referrer : '';
  }

  field(key: string, labelKey: string, opts: { type?: string; rows?: number; help?: string } = {}): Mithril.Children {
    const id = `panels-claim-${key}`;
    return (
      <div className="Form-group">
        <label for={id}>{t(labelKey)}</label>
        {opts.rows ? (
          <textarea
            id={id}
            className="FormControl"
            rows={opts.rows}
            required
            value={this.form[key]}
            oninput={(e: InputEvent) => (this.form[key] = (e.target as HTMLTextAreaElement).value)}
          />
        ) : (
          <input
            id={id}
            className="FormControl"
            type={opts.type || 'text'}
            required={key !== 'org'}
            value={this.form[key]}
            oninput={(e: InputEvent) => (this.form[key] = (e.target as HTMLInputElement).value)}
          />
        )}
        {opts.help ? <p className="helpText">{t(opts.help)}</p> : null}
      </div>
    );
  }

  view() {
    const agent = [app.forum.attribute('panelsAgentName'), app.forum.attribute('panelsAgentAddress'), app.forum.attribute('panelsAgentEmail')].filter(
      Boolean
    ) as string[];
    return (
      <PageStructure className="PanelsCopyright">
        <nav className="PanelsCrumbs" aria-label={tx('breadcrumb')}>
          <Link href={app.route('panels')}>{label()}</Link> › <span>{t('copyright.title')}</span>
        </nav>
        <h1>{t('copyright.title')}</h1>
        <p>{t('copyright.intro')}</p>
        {agent.length ? (
          <div className="PanelsCard">
            <strong>{t('copyright.agent')}</strong>
            <p className="PanelsPre">{agent.join('\n')}</p>
          </div>
        ) : app.forum.attribute('panelsCanModerate') ? (
          <div className="PanelsNotice PanelsNotice--danger">{t('copyright.no_agent')}</div>
        ) : null}
        {this.sent ? (
          <div className="PanelsNotice">{t(this.counterId ? 'copyright.counter_sent' : 'copyright.sent')}</div>
        ) : this.counterId ? (
          this.counterForm()
        ) : (
          this.claimForm()
        )}
      </PageStructure>
    );
  }

  claimForm(): Mithril.Children {
    if (!this.targetId) return <p className="PanelsMuted">{t('copyright.how')}</p>;
    return (
      <form className="PanelsCard PanelsClaimForm" onsubmit={(e: SubmitEvent) => this.submitClaim(e)}>
        <h2>{t('copyright.form_title')}</h2>
        {this.field('name', 'copyright.name')}
        {this.field('email', 'copyright.email', { type: 'email' })}
        {this.field('org', 'copyright.org')}
        {this.field('work', 'copyright.work', { rows: 3, help: 'copyright.work_help' })}
        {this.field('urls', 'copyright.urls', { rows: 3, help: 'copyright.urls_help' })}
        <label className="PanelsAttest">
          <input type="checkbox" checked={this.goodFaith} onchange={() => (this.goodFaith = !this.goodFaith)} />
          <span>{t('copyright.good_faith')}</span>
        </label>
        <label className="PanelsAttest">
          <input type="checkbox" checked={this.accurate} onchange={() => (this.accurate = !this.accurate)} />
          <span>{t('copyright.accurate')}</span>
        </label>
        {this.field('signature', 'copyright.signature', { help: 'copyright.signature_help' })}
        <Button type="submit" className="Button Button--primary" loading={this.busy} disabled={!this.goodFaith || !this.accurate}>
          {t('copyright.submit')}
        </Button>
      </form>
    );
  }

  counterForm(): Mithril.Children {
    if (!app.session.user) return <p>{t('copyright.counter_signin')}</p>;
    return (
      <form className="PanelsCard PanelsClaimForm" onsubmit={(e: SubmitEvent) => this.submitCounter(e)}>
        <h2>{t('copyright.counter_title')}</h2>
        <p className="PanelsMuted">{t('copyright.counter_intro')}</p>
        {this.field('name', 'copyright.name')}
        {this.field('address', 'copyright.address', { rows: 2 })}
        {this.field('statement', 'copyright.statement', { rows: 4, help: 'copyright.statement_help' })}
        <label className="PanelsAttest">
          <input type="checkbox" checked={this.consent} onchange={() => (this.consent = !this.consent)} />
          <span>{t('copyright.consent')}</span>
        </label>
        {this.field('signature', 'copyright.signature')}
        <Button type="submit" className="Button Button--primary" loading={this.busy} disabled={!this.consent}>
          {t('copyright.counter_submit')}
        </Button>
      </form>
    );
  }

  submitClaim(e: SubmitEvent): void {
    e.preventDefault();
    this.busy = true;
    send('POST', 'takedowns', {
      ...this.form,
      targetType: this.targetType,
      targetId: this.targetId,
      goodFaith: this.goodFaith,
      accurate: this.accurate,
    })
      .then(() => (this.sent = true))
      .catch(() => {})
      .finally(() => ((this.busy = false), m.redraw()));
  }

  submitCounter(e: SubmitEvent): void {
    e.preventDefault();
    this.busy = true;
    send('POST', `takedowns/${this.counterId}/counter`, { ...this.form, consent: this.consent })
      .then(() => (this.sent = true))
      .catch(() => {})
      .finally(() => ((this.busy = false), m.redraw()));
  }
}
