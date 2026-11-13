/** Core components the pages build on, loaded through the registry: some live in lazy chunks. */
declare const flarum: any;

export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  if (PageStructure && IndexSidebar) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}
