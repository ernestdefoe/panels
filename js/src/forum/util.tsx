import Icon from 'flarum/common/components/Icon';
import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export const EXT = 'ernestdefoe-panels';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

export const api = (path: string) => `${app.forum.attribute('apiUrl')}/panels/${path}`;

/** What the forum calls this section: the admin's word, else "Comics". */
export function label(plural = true): string {
  const own = String(app.forum.attribute(plural ? 'panelsLabelPlural' : 'panelsLabelSingular') || '').trim();
  return own || tx(plural ? 'nav' : 'nav_singular');
}

/** The sentence an error answer carries (our endpoints send {error: {message}}), or core's. */
export function errorText(error: any): string {
  const ours = error?.response?.error?.message;
  if (ours) return ours;
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return tx('error.not_found');
  return extractText(app.translator.trans('core.lib.error.generic_message'));
}

export function alertError(error: any): never {
  app.alerts.show({ type: 'error' }, errorText(error));
  throw error;
}

export function get<T = any>(path: string, params: Record<string, any> = {}, quiet = false): Promise<T> {
  return app.request<T>({ method: 'GET', url: api(path), params, errorHandler: quiet ? () => {} : alertError } as any);
}

export function send<T = any>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body: any = {}): Promise<T> {
  return app.request<T>({ method, url: api(path), body, errorHandler: alertError } as any);
}

/** Multipart upload with progress; resolves with the JSON answer, also for 422 (per-file refusals). */
export function upload(path: string, form: FormData, onProgress?: (fraction: number) => void): Promise<any> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', api(path));
    xhr.setRequestHeader('X-CSRF-Token', app.session.csrfToken || '');
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress?.(e.loaded / e.total);
    xhr.onload = () => {
      let body: any = null;
      try {
        body = JSON.parse(xhr.responseText);
      } catch (e) {
        // not JSON: a server error page
      }
      if (xhr.status >= 200 && xhr.status < 300) resolve(body);
      else if (xhr.status === 422 && body) resolve(body);
      else reject({ status: xhr.status, response: body });
    };
    xhr.onerror = () => reject({ status: 0 });
    xhr.send(form);
  });
}

/** localStorage, never trusted to be there (private windows, blocked storage). */
export const store = {
  get<T>(key: string, fallback: T): T {
    try {
      const raw = localStorage.getItem(`${EXT}.${key}`);
      return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch (e) {
      return fallback;
    }
  },
  set(key: string, value: unknown): void {
    try {
      localStorage.setItem(`${EXT}.${key}`, JSON.stringify(value));
    } catch (e) {
      // storage full or blocked: the feature just doesn't remember
    }
  },
  remove(key: string): void {
    try {
      localStorage.removeItem(`${EXT}.${key}`);
    } catch (e) {
      // ignore
    }
  },
};

export interface Cover {
  small: string;
  medium: string;
  large: string;
  blurred: boolean;
}

export interface SeriesCard {
  id: number;
  slug: string;
  title: string;
  format: string;
  status: string;
  contentRating: string;
  cover: Cover | null;
  coverColor: string | null;
  genres: Array<{ id: number; name: string; slug: string }>;
  latest: { id: number; number: string; publishedAt: string } | null;
  latestAt: string | null;
  chapterCount: number;
  followerCount: number;
  rating: number | null;
  ratingCount: number;
  isHidden: boolean;
  isFeatured: boolean;
  isRemoved: boolean;
  progress: { chapter_id: number; page: number; pages: number; number: string } | null;
  unread: number;
}

/** "Ch 12", "Ep 8", "#7": how a format names a chapter, short. */
export function short(format: string, number: string): string {
  return tx(`short.${['webtoon', 'manhwa', 'manhua'].includes(format) ? 'episode' : format === 'comic' ? 'issue' : 'chapter'}`, { number });
}

/** "Chapter 12", "Episode 8", "Issue 7". */
export function long(format: string, number: string): string {
  return tx(`long.${['webtoon', 'manhwa', 'manhua'].includes(format) ? 'episode' : format === 'comic' ? 'issue' : 'chapter'}`, { number });
}

export const readerHref = (slug: string, number: string) => app.route('panels.reader', { slug, chapter: number });
export const seriesHref = (slug: string) => app.route('panels.series', { slug });

/** A guest's places, kept in the browser until they sign in. */
export interface GuestPlace {
  seriesId: number;
  chapterId: number;
  page: number;
  pages: number;
  at: number;
}

/** Flarum 2 has no icon() helper: the Icon component, with an optional class. */
export function icon(name: string, attrs: { className?: string } = {}): any {
  return <Icon name={name} className={attrs.className} />;
}
