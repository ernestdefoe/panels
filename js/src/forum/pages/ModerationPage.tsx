import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { get, label, long, readerHref, send, t, tx } from '../util';
import { PageStructure } from './core';

const CLAIM_STATES = ['open', 'info', 'disabled', 'rejected', 'restored'];

/**
 * /read/moderation: chapters waiting for a moderator (members' chapters, and ones that look like
 * a removed chapter), and the copyright claims, each with what the uploader attested.
 */
export default class ModerationPage extends Page {
  tab: 'queue' | 'claims' = 'queue';
  claimState = 'open';
  queue: any[] | null = null;
  claims: any[] | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--panels';
    app.setTitle(tx('moderation.title'));
    if (!app.forum.attribute('panelsCanModerate')) {
      m.route.set(app.route('panels'));
      return;
    }
    this.tab = m.route.param('tab') === 'claims' ? 'claims' : 'queue';
    this.load();
  }

  load(): void {
    if (this.tab === 'queue') get('queue').then((r) => ((this.queue = r.data), m.redraw()));
    else get('takedowns', { state: this.claimState }).then((r) => ((this.claims = r.data), m.redraw()));
  }

  view() {
    return (
      <PageStructure className="PanelsModeration">
        <nav className="PanelsCrumbs" aria-label={tx('breadcrumb')}>
          <Link href={app.route('panels')}>{label()}</Link> › <span>{t('moderation.title')}</span>
        </nav>
        <h1>{t('moderation.title')}</h1>
        <div className="PanelsChips PanelsChips--tabs" role="tablist">
          {(['queue', 'claims'] as const).map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === tab ? 'true' : 'false'}
              className={'PanelsChip' + (this.tab === tab ? ' is-on' : '')}
              onclick={() => {
                this.tab = tab;
                this.load();
              }}
            >
              {t(`moderation.${tab}`)}
            </button>
          ))}
        </div>
        {this.tab === 'queue' ? this.queueView() : this.claimsView()}
      </PageStructure>
    );
  }

  queueView(): Mithril.Children {
    if (!this.queue) return <LoadingIndicator />;
    if (!this.queue.length) return <p className="PanelsEmpty">{t('moderation.queue_empty')}</p>;
    return (
      <ul className="PanelsRows">
        {this.queue.map((c) => (
          <li className="PanelsCard PanelsRow">
            <div className="PanelsRow-body">
              <strong>
                {c.seriesTitle} · {long('manga', c.number)}
                {c.title ? `: ${c.title}` : ''}
              </strong>
              <span className="PanelsMuted">
                {t('pages', { count: c.pageCount })} · {c.uploader ? c.uploader.displayName : '—'}
              </span>
              {c.state === 'held' ? <span className="PanelsError">{t('moderation.held', { id: c.heldFor })}</span> : null}
            </div>
            <div className="PanelsRow-actions">
              <Link className="Button" href={readerHref(c.seriesSlug, c.number)}>
                {t('moderation.preview')}
              </Link>
              <Button className="Button Button--primary" onclick={() => send('PATCH', `chapters/${c.id}`, { action: 'approve' }).then(() => this.load())}>
                {t('moderation.approve')}
              </Button>
              <Button
                className="Button Button--danger"
                onclick={() => window.confirm(tx('moderation.remove_confirm')) && send('PATCH', `chapters/${c.id}`, { action: 'remove' }).then(() => this.load())}
              >
                {t('moderation.remove')}
              </Button>
            </div>
          </li>
        ))}
      </ul>
    );
  }

  act(claim: any, action: string): void {
    let reason = '';
    if (action === 'reject' || action === 'info') {
      reason = window.prompt(tx(`moderation.${action}_prompt`)) || '';
      if (!reason.trim()) return;
    } else if (!window.confirm(tx(`moderation.${action}_confirm`))) {
      return;
    }
    send('PATCH', `takedowns/${claim.id}`, { action, reason }).then(() => this.load());
  }

  claimsView(): Mithril.Children {
    return (
      <>
        <div className="PanelsChips">
          {CLAIM_STATES.map((s) => (
            <button
              type="button"
              className={'PanelsChip PanelsChip--small' + (this.claimState === s ? ' is-on' : '')}
              aria-pressed={this.claimState === s ? 'true' : 'false'}
              onclick={() => {
                this.claimState = s;
                this.claims = null;
                this.load();
              }}
            >
              {t(`moderation.state_${s}`)}
            </button>
          ))}
        </div>
        {!this.claims ? (
          <LoadingIndicator />
        ) : !this.claims.length ? (
          <p className="PanelsEmpty">{t('moderation.claims_empty')}</p>
        ) : (
          <ul className="PanelsRows">
            {this.claims.map((c) => (
              <li className="PanelsCard PanelsClaim">
                <div className="PanelsClaim-head">
                  <strong>{c.label}</strong>
                  <span className="PanelsMuted">
                    #{c.id} · {humanTime(c.createdAt)}
                  </span>
                </div>
                <dl className="PanelsFacts">
                  <div className="PanelsFacts-row">
                    <dt>{t('moderation.claimant')}</dt>
                    <dd>
                      {c.claimant.name}
                      {c.claimant.org ? ` (${c.claimant.org})` : ''} · <a href={`mailto:${c.claimant.email}`}>{c.claimant.email}</a>
                    </dd>
                  </div>
                  <div className="PanelsFacts-row">
                    <dt>{t('moderation.work')}</dt>
                    <dd className="PanelsPre">{c.work}</dd>
                  </div>
                  <div className="PanelsFacts-row">
                    <dt>{t('moderation.urls')}</dt>
                    <dd className="PanelsPre">{(c.urls || []).join('\n')}</dd>
                  </div>
                  <div className="PanelsFacts-row">
                    <dt>{t('moderation.signature')}</dt>
                    <dd>{c.signature}</dd>
                  </div>
                  <div className="PanelsFacts-row">
                    <dt>{t('moderation.uploader')}</dt>
                    <dd>
                      {c.uploader ? (
                        <Link href={app.route('user', { username: c.uploader.username })}>{c.uploader.username}</Link>
                      ) : (
                        '—'
                      )}
                      {c.uploader ? ` · ${tx('moderation.strikes', { count: c.uploader.strikes })}` : ''}
                      {c.attestedAt ? ` · ${tx('moderation.attested', { date: new Date(c.attestedAt).toLocaleDateString() })}` : ''}
                    </dd>
                  </div>
                  {c.reason ? (
                    <div className="PanelsFacts-row">
                      <dt>{t('moderation.reason')}</dt>
                      <dd className="PanelsPre">{c.reason}</dd>
                    </div>
                  ) : null}
                  {c.counter ? (
                    <div className="PanelsFacts-row">
                      <dt>{t('moderation.counter')}</dt>
                      <dd className="PanelsPre">
                        {c.counter.name} · {c.counter.address}
                        {'\n'}
                        {c.counter.statement}
                        {'\n'}
                        {tx('moderation.restore_after', { date: c.restoreAfter ? new Date(c.restoreAfter).toLocaleDateString() : '—' })}
                      </dd>
                    </div>
                  ) : null}
                </dl>
                <div className="PanelsRow-actions">
                  {c.seriesSlug ? (
                    <Link className="Button" href={c.chapterNumber ? readerHref(c.seriesSlug, c.chapterNumber) : app.route('panels.series', { slug: c.seriesSlug })}>
                      {t('moderation.view')}
                    </Link>
                  ) : null}
                  {['open', 'info'].includes(c.state) ? (
                    <>
                      <Button className="Button Button--danger" onclick={() => this.act(c, 'disable')}>
                        {t('moderation.disable')}
                      </Button>
                      <Button className="Button" onclick={() => this.act(c, 'info')}>
                        {t('moderation.info')}
                      </Button>
                      <Button className="Button" onclick={() => this.act(c, 'reject')}>
                        {t('moderation.reject')}
                      </Button>
                    </>
                  ) : null}
                  {c.state === 'disabled' ? (
                    <Button className="Button" onclick={() => this.act(c, 'restore')}>
                      {t('moderation.restore')}
                    </Button>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        )}
      </>
    );
  }
}
