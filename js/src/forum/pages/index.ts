/*
 * The one lazy chunk: every Panels page. One entry on purpose: two chunks sharing a module make
 * webpack emit a numbered shared chunk that jsDirectory doesn't publish.
 */
export { loadCore } from './core';
export { default as LibraryPage } from './LibraryPage';
export { default as SeriesPage } from './SeriesPage';
export { default as ReaderPage } from './ReaderPage';
export { default as EditorPage } from './EditorPage';
export { default as ModerationPage } from './ModerationPage';
export { default as CopyrightPage } from './CopyrightPage';
