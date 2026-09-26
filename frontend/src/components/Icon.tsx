import type {SVGProps} from 'react';

const paths:Record<string,string>={
  dashboard:'M3 13h8V3H3v10Zm10 8h8V11h-8v10ZM3 21h8v-6H3v6Zm10-12h8V3h-8v6Z',
  leads:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87m0-8.26a4 4 0 0 1 0 7.75',
  calendar:'M19 4H5a2 2 0 0 0-2 2v14h18V6a2 2 0 0 0-2-2ZM8 2v4m8-4v4M3 10h18',
  building:'M3 21h18M6 21V5l6-3 6 3v16M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1',
  file:'M14 2H6a2 2 0 0 0-2 2v16h16V8l-6-6Zm0 0v6h6M8 13h8m-8 4h8',
  chart:'M4 19V9m6 10V5m6 14v-7m5 7H2',
  settings:'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm0-13v2m0 15v2M4.57 4.57 6 6m12 12 1.43 1.43M2.5 12h2m15 0h2M4.57 19.43 6 18M18 6l1.43-1.43',
  plug:'M8 12h8m-7-7v4m6-4v4m3 0v3a6 6 0 0 1-12 0V9h12ZM12 18v4',
  bell:'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 13h4',
  search:'m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
  menu:'M4 6h16M4 12h16M4 18h16',
  home:'m3 11 9-8 9 8v10h-6v-6H9v6H3V11Z',
  check:'m5 12 4 4L19 6',
  users:'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2m7.5-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM18 8v6m3-3h-6',
  briefcase:'M4 7h16v13H4V7Zm5 0V4h6v3M4 12h16',
  chat:'M21 11.5a8.38 8.38 0 0 1-4.9 7.6 8.5 8.5 0 0 1-9.03-1.08L3 19l1.08-4.07a8.38 8.38 0 0 1-.9-3.93 8.5 8.5 0 0 1 8.5-8.5h.5a8.48 8.48 0 0 1 8 8v.5Z',
  mail:'M4 4h16v16H4V4Zm0 0 8 8 8-8',
  phone:'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z',
  globe:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 0c2.5 2.5 4 5.9 4 10s-1.5 7.5-4 10m0-20c-2.5 2.5-4 5.9-4 10s1.5 7.5 4 10M2.5 9h19M2.5 15h19',
  close:'M6 6l12 12M18 6 6 18',
  pen:'M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z',
};

export function Icon({name,...props}:{name:string}&SVGProps<SVGSVGElement>){return <svg viewBox="0 0 24 24" fill={name==='dashboard'?'currentColor':'none'} stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>{name==='dashboard'?<path stroke="none" d={paths[name]}/>:<path d={paths[name]||paths.file}/>}</svg>}
