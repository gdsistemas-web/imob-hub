import {useEffect, useState} from 'react';

type InstallPrompt = Event & {prompt: () => Promise<void>; userChoice: Promise<{outcome: 'accepted' | 'dismissed'}>};

// O navegador dispara o convite de instalação uma única vez e cedo; guardamos para oferecer no momento certo.
let deferred: InstallPrompt | null = null;
const listeners = new Set<() => void>();
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e as InstallPrompt; listeners.forEach(fn => fn()) });
window.addEventListener('appinstalled', () => { deferred = null; listeners.forEach(fn => fn()) });

export const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone === true;

/** O manifesto só entra nas telas do painel, para o site público não oferecer a instalação do app do corretor. */
export function enableAppManifest() {
  if (document.querySelector('link[rel="manifest"]')) return;
  const link = document.createElement('link');
  link.rel = 'manifest'; link.href = '/manifest.webmanifest';
  document.head.appendChild(link);
  const apple = document.createElement('link');
  apple.rel = 'apple-touch-icon'; apple.href = '/icons/apple-touch-icon.png';
  document.head.appendChild(apple);
  for (const [name, content] of [['apple-mobile-web-app-capable', 'yes'], ['apple-mobile-web-app-title', 'Corretor'], ['apple-mobile-web-app-status-bar-style', 'default']]) {
    const meta = document.createElement('meta'); meta.name = name; meta.content = content; document.head.appendChild(meta);
  }
}

export function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;
  window.addEventListener('load', () => { navigator.serviceWorker.register('/sw.js').catch(() => {}) });
}

export function usePwaInstall() {
  const [, force] = useState(0);
  useEffect(() => { const fn = () => force(n => n + 1); listeners.add(fn); return () => { listeners.delete(fn) } }, []);
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !isStandalone();
  let dismissed = false;
  try { dismissed = localStorage.getItem('pwa_hint_dismissed') === '1' } catch { /* sem armazenamento */ }
  return {
    canInstall: !!deferred && !isStandalone(),
    iosHint: ios && !dismissed,
    dismissIos() { try { localStorage.setItem('pwa_hint_dismissed', '1') } catch { /* sem armazenamento */ } force(n => n + 1) },
    async install() { if (!deferred) return; await deferred.prompt(); await deferred.userChoice; deferred = null; force(n => n + 1) },
  };
}
