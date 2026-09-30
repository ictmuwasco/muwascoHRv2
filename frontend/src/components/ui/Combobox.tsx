import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';

export interface ComboboxOption {
  value: string | number;
  label: string;
  /** Secondary line shown under the label in the dropdown. */
  description?: string;
}

/**
 * Combobox - a searchable dropdown ("type to filter, then pick from a list").
 *
 * A native <select> cannot cope with a supervisor's employee roster: the
 * authorized scope routinely holds hundreds of rows and a closed dropdown
 * gives no way to scan them. This is the searchable middle ground - the
 * control is a real text input (filter by name / staff number / department)
 * while still behaving like a dropdown with full keyboard navigation.
 *
 * Styling intentionally mirrors Input.tsx and Select.tsx so it drops into the
 * existing modals and forms without visual drift.
 */
interface ComboboxProps {
  label?: string;
  /** Currently selected value. Pass '' for none. */
  value: string | number;
  onChange: (value: string) => void;
  options: ComboboxOption[];
  placeholder?: string;
  /** Shown when options exist but none match the typed query. */
  emptyMessage?: string;
  /** Shown when there are no options at all. */
  noOptionsMessage?: string;
  loading?: boolean;
  disabled?: boolean;
  required?: boolean;
  error?: string;
  className?: string;
}

const Combobox = ({
  label,
  value,
  onChange,
  options,
  placeholder = 'Select an option',
  emptyMessage = 'No matches found.',
  noOptionsMessage = 'No options available.',
  loading = false,
  disabled = false,
  required = false,
  error,
  className = '',
}: ComboboxProps) => {
  const listId = useId();
  const rootRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);

  const selected = options.find((o) => String(o.value) === String(value)) ?? null;

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter((o) => `${o.label} ${o.description ?? ''}`.toLowerCase().includes(q));
  }, [options, query]);

  // Close when the user clicks away from the control.
  useEffect(() => {
    if (!open) return;
    const onPointerDown = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', onPointerDown);
    return () => document.removeEventListener('mousedown', onPointerDown);
  }, [open]);

  // Keep the highlighted row valid as the query narrows the list.
  useEffect(() => {
    setActive(0);
  }, [query, open]);

  const close = () => {
    setOpen(false);
    setQuery('');
  };

  const select = (option: ComboboxOption) => {
    onChange(String(option.value));
    close();
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      close();
      return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) {
        setOpen(true);
        return;
      }
      if (!filtered.length) return;
      const delta = e.key === 'ArrowDown' ? 1 : -1;
      setActive((i) => (i + delta + filtered.length) % filtered.length);
      return;
    }
    if (e.key === 'Enter') {
      // Only swallow Enter when a row is highlighted, so the key still
      // submits the surrounding form during normal typing.
      if (open && filtered[active]) {
        e.preventDefault();
        select(filtered[active]);
      }
      return;
    }
    if (e.key === 'Tab') close();
  };

  const hasOptions = options.length > 0;

  return (
    <div className={`w-full ${className}`} ref={rootRef}>
      {label && (
        <label className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
          {label}
          {required && <span className="text-red-500"> *</span>}
        </label>
      )}
      <div className="relative">
        <div
          className={`
            flex w-full items-center rounded-md border bg-white dark:bg-slate-800
            focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500
            ${disabled ? 'cursor-not-allowed bg-gray-100' : ''}
            ${error ? 'border-red-500' : 'border-gray-300 dark:border-slate-600'}
          `}
        >
          <Search className="ml-3 h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
          <input
            type="text"
            role="combobox"
            aria-expanded={open}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-required={required}
            disabled={disabled || loading}
            value={open ? query : (selected?.label ?? '')}
            placeholder={selected ? selected.label : placeholder}
            onFocus={() => !disabled && setOpen(true)}
            onChange={(e) => {
              setQuery(e.target.value);
              setOpen(true);
            }}
            onKeyDown={onKeyDown}
            className="w-full bg-transparent px-2 py-2 text-sm text-gray-900 outline-none placeholder:text-gray-400 disabled:cursor-not-allowed dark:text-gray-100"
          />
          {loading && <span className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-primary-600" aria-hidden="true" />}
          {!loading && selected && !disabled && (
            <button
              type="button"
              aria-label="Clear selection"
              onClick={() => {
                onChange('');
                setQuery('');
              }}
              className="mr-1 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-slate-700"
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <button
            type="button"
            tabIndex={-1}
            aria-label={open ? 'Close list' : 'Open list'}
            disabled={disabled}
            onClick={() => (open ? close() : setOpen(true))}
            className="mr-2 rounded p-1 text-gray-400 hover:text-gray-600 disabled:cursor-not-allowed dark:hover:text-gray-200"
          >
            <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
          </button>
        </div>

        {open && (
          <ul
            id={listId}
            role="listbox"
            className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-slate-600 dark:bg-slate-800"
          >
            {loading && <li className="px-3 py-2 text-sm text-gray-500">Loading…</li>}
            {!loading && !hasOptions && <li className="px-3 py-2 text-sm text-gray-500">{noOptionsMessage}</li>}
            {!loading && hasOptions && !filtered.length && (
              <li className="px-3 py-2 text-sm text-gray-500">{emptyMessage}</li>
            )}
            {filtered.map((option, index) => {
              const isSelected = String(option.value) === String(value);
              return (
                <li
                  key={option.value}
                  role="option"
                  aria-selected={isSelected}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => select(option)}
                  onMouseEnter={() => setActive(index)}
                  className={`cursor-pointer px-3 py-2 text-sm ${index === active ? 'bg-primary-50 dark:bg-slate-700' : ''}`}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className={isSelected ? 'font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-900 dark:text-gray-100'}>
                      {option.label}
                    </span>
                    {isSelected && <Check className="h-4 w-4 shrink-0 text-primary-600" />}
                  </div>
                  {option.description && <p className="mt-0.5 text-xs text-gray-500">{option.description}</p>}
                </li>
              );
            })}
          </ul>
        )}
      </div>
      {error && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{error}</p>}
    </div>
  );
};

export default Combobox;

