import app from 'flarum/forum/app';
import { fof, hasFofWidgets, placed } from '../../common/fofWidgets';
import PanelsWidget, { ICONS, type Kind } from './Widget';

/*
 * The five widgets, offered to fof/forum-widgets-core (Bespoke 2.9+ shows those in its zones) and
 * to Page Builder (through its block queue). Neither is a build-time import: both are found at
 * runtime, so the bundle is the same with either installed or neither.
 */

export const EXT = 'ernestdefoe-panels';

export const WIDGETS: Array<{ kind: Kind; key: string; placement: string }> = [
  { kind: 'new', key: 'panelsNew', placement: 'end' },
  { kind: 'continue', key: 'panelsContinue', placement: 'end' },
  { kind: 'trending', key: 'panelsTrending', placement: 'end' },
  { kind: 'spotlight', key: 'panelsSpotlight', placement: 'top' },
  { kind: 'stats', key: 'panelsStats', placement: 'end' },
];

/** Placed by the admin in FoF's layout? Then the stock sidebar spot stands down. */
export function placedInFof(kind: Kind): boolean {
  const w = WIDGETS.find((x) => x.kind === kind);
  return !!w && hasFofWidgets() && placed(app, EXT, w.key);
}

export function registerFof(): void {
  if (!hasFofWidgets()) return;
  const Widgets = fof('common/extend/Widgets');
  const WidgetBase = fof('common/components/Widget');
  if (!Widgets || !WidgetBase || !(app as any).widgets) return;

  const add = new Widgets();
  WIDGETS.forEach(({ kind, key, placement }, position) => {
    class FofPanels extends WidgetBase {
      className() {
        return `PanelsFofWidget PanelsFofWidget--${kind}`;
      }
      icon() {
        return ICONS[kind];
      }
      title() {
        return app.translator.trans(`${EXT}.lib.widget.${kind}`);
      }
      content() {
        return <PanelsWidget kind={kind} host="fof" bare />;
      }
    }
    add.add({
      key,
      component: FofPanels,
      // 🚨 Opt-in: only where the admin placed it in FoF's layout editor; "continue" only for members.
      isDisabled: () => !placed(app, EXT, key) || (kind === 'continue' && !app.session.user) || !app.forum.attribute('panelsCanView'),
      isUnique: true,
      placement,
      position,
    });
  });
  add.extend(app, EXT);
}

export function registerPageBuilder(): void {
  // 🚨 Order-proof: Page Builder (3.10+) drains `window.PageBuilderBlockQueue` on every lookup, so
  // these register whichever extension initializes first.
  const blocks = WIDGETS.map(({ kind }) => ({
    // Match Widget\*Block::type().
    type: `panels-${kind}`,
    component: { view: (vnode: any) => <PanelsWidget kind={kind} host="page-builder" settings={vnode.attrs?.settings || {}} /> },
  }));
  const queue = ((globalThis as any).PageBuilderBlockQueue = (globalThis as any).PageBuilderBlockQueue || []);
  queue.push(...blocks);

  // Page Builder before 3.10 has no queue: register directly when it's already there.
  const pb = (app as any).pageBuilder;
  if (pb?.registerBlock) blocks.forEach(({ type, component }) => pb.registerBlock(type, component));
}
