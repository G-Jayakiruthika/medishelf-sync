// Light, dark or follow the device; remembered per browser.
export type Theme = 'system' | 'light' | 'dark';
export function applyTheme(th: Theme) {
  if (th === 'system') document.documentElement.removeAttribute('data-theme');
  else document.documentElement.setAttribute('data-theme', th);
  try { localStorage.setItem('aushadhbox-theme', th); } catch { /* ignore */ }
}
export function savedTheme(): Theme {
  try { return (localStorage.getItem('aushadhbox-theme') as Theme) || 'system'; } catch { return 'system'; }
}

