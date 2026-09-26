import {useEffect, useRef, useState} from 'react';

export function SearchAutocomplete<T>({value, onChange, onSubmit, fetcher, renderItem, onPick, placeholder}: {
  value: string;
  onChange: (v: string) => void;
  onSubmit?: () => void;
  fetcher: (q: string) => Promise<T[]>;
  renderItem: (item: T) => React.ReactNode;
  onPick: (item: T) => void;
  placeholder?: string;
}) {
  const [items, setItems] = useState<T[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);
  const requestId = useRef(0);

  useEffect(() => {
    if (!value.trim()) { setItems([]); setOpen(false); return }
    const id = ++requestId.current;
    setLoading(true);
    const t = setTimeout(() => {
      fetcher(value).then(r => { if (requestId.current === id) { setItems(r.slice(0, 8)); setOpen(true); setLoading(false) } })
        .catch(() => { if (requestId.current === id) setLoading(false) });
    }, 300);
    return () => clearTimeout(t);
  }, [value]);

  useEffect(() => {
    function onDocClick(e: MouseEvent) { if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  return <div className="autocomplete" ref={boxRef}>
    <input
      value={value}
      onChange={e => { onChange(e.target.value); setOpen(true) }}
      onKeyDown={e => e.key === 'Enter' && (setOpen(false), onSubmit?.())}
      onFocus={() => items.length > 0 && setOpen(true)}
      placeholder={placeholder}
    />
    {open && (loading || items.length > 0) && <div className="autocomplete-list">
      {loading && !items.length && <div className="autocomplete-empty">Buscando…</div>}
      {items.map((it, i) => <button type="button" key={i} className="autocomplete-item" onClick={() => { onPick(it); setOpen(false) }}>{renderItem(it)}</button>)}
    </div>}
  </div>;
}
