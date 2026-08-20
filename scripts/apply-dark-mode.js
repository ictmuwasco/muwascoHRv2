/**
 * Applies Tailwind dark mode classes to all frontend page files.
 * Uses consistent dark: variants matching Header.jsx and shared UI components.
 * Idempotent: safe to run multiple times (skips already-themed classes).
 */
const fs = require('fs');
const path = require('path');

const PAGES_DIR = path.join(__dirname, '..', 'frontend', 'src', 'pages');

// [search, replace] — negative lookahead prevents double-application
const replacements = [
  // Headings & primary text
  [/text-gray-900(?! dark:text-gray-100)/g, 'text-gray-900 dark:text-gray-100'],
  // Secondary text
  [/text-gray-700(?! dark:text-gray-300)/g, 'text-gray-700 dark:text-gray-300'],
  [/text-gray-600(?! dark:text-gray-300)/g, 'text-gray-600 dark:text-gray-300'],
  [/text-gray-500(?! dark:text-gray-400)/g, 'text-gray-500 dark:text-gray-400'],
  [/text-gray-400(?! dark:text-gray-500)/g, 'text-gray-400 dark:text-gray-500'],
  // Borders
  [/border-gray-200(?! dark:border-slate-700)/g, 'border-gray-200 dark:border-slate-700'],
  [/border-gray-300(?! dark:border-slate-600)/g, 'border-gray-300 dark:border-slate-600'],
  [/divide-gray-200(?! dark:divide-slate-700)/g, 'divide-gray-200 dark:divide-slate-700'],
  // Backgrounds
  [/bg-white(?! dark:bg-slate-800)/g, 'bg-white dark:bg-slate-800'],
  [/bg-gray-50(?! dark:bg-slate-900)/g, 'bg-gray-50 dark:bg-slate-900'],
  [/bg-gray-100(?! dark:bg-slate-700)/g, 'bg-gray-100 dark:bg-slate-700'],
  [/bg-gray-200(?! dark:bg-slate-700)/g, 'bg-gray-200 dark:bg-slate-700'],
  // Hover states
  [/hover:bg-gray-50(?! dark:hover:bg-slate-700\/50)/g, 'hover:bg-gray-50 dark:hover:bg-slate-700/50'],
  [/hover:bg-gray-100(?! dark:hover:bg-slate-700)/g, 'hover:bg-gray-100 dark:hover:bg-slate-700'],
  [/hover:bg-gray-200(?! dark:hover:bg-slate-600)/g, 'hover:bg-gray-200 dark:hover:bg-slate-600'],
  [/hover:bg-gray-300(?! dark:hover:bg-slate-600)/g, 'hover:bg-gray-300 dark:hover:bg-slate-600'],
  // Colored backgrounds
  [/bg-blue-50(?! dark:bg-blue-900\/20)/g, 'bg-blue-50 dark:bg-blue-900/20'],
  [/bg-blue-100(?! dark:bg-blue-900\/40)/g, 'bg-blue-100 dark:bg-blue-900/40'],
  [/bg-green-50(?! dark:bg-green-900\/20)/g, 'bg-green-50 dark:bg-green-900/20'],
  [/bg-green-100(?! dark:bg-green-900\/40)/g, 'bg-green-100 dark:bg-green-900/40'],
  [/bg-red-50(?! dark:bg-red-900\/20)/g, 'bg-red-50 dark:bg-red-900/20'],
  [/bg-red-100(?! dark:bg-red-900\/40)/g, 'bg-red-100 dark:bg-red-900/40'],
  [/bg-amber-50(?! dark:bg-amber-900\/20)/g, 'bg-amber-50 dark:bg-amber-900/20'],
  [/bg-amber-100(?! dark:bg-amber-900\/40)/g, 'bg-amber-100 dark:bg-amber-900/40'],
  [/bg-yellow-50(?! dark:bg-yellow-900\/20)/g, 'bg-yellow-50 dark:bg-yellow-900/20'],
  [/bg-yellow-100(?! dark:bg-yellow-900\/40)/g, 'bg-yellow-100 dark:bg-yellow-900/40'],
  [/bg-purple-100(?! dark:bg-purple-900\/40)/g, 'bg-purple-100 dark:bg-purple-900/40'],
  [/bg-indigo-100(?! dark:bg-indigo-900\/40)/g, 'bg-indigo-100 dark:bg-indigo-900/40'],
  [/bg-primary-50(?! dark:bg-primary-900\/40)/g, 'bg-primary-50 dark:bg-primary-900/40'],
  [/bg-primary-100(?! dark:bg-primary-900\/40)/g, 'bg-primary-100 dark:bg-primary-900/40'],
  // Colored text
  [/text-blue-800(?! dark:text-blue-200)/g, 'text-blue-800 dark:text-blue-200'],
  [/text-blue-700(?! dark:text-blue-300)/g, 'text-blue-700 dark:text-blue-300'],
  [/text-blue-600(?! dark:text-blue-400)/g, 'text-blue-600 dark:text-blue-400'],
  [/text-green-800(?! dark:text-green-200)/g, 'text-green-800 dark:text-green-200'],
  [/text-green-700(?! dark:text-green-300)/g, 'text-green-700 dark:text-green-300'],
  [/text-green-600(?! dark:text-green-400)/g, 'text-green-600 dark:text-green-400'],
  [/text-red-800(?! dark:text-red-200)/g, 'text-red-800 dark:text-red-200'],
  [/text-red-700(?! dark:text-red-300)/g, 'text-red-700 dark:text-red-300'],
  [/text-red-600(?! dark:text-red-400)/g, 'text-red-600 dark:text-red-400'],
  [/text-amber-800(?! dark:text-amber-200)/g, 'text-amber-800 dark:text-amber-200'],
  [/text-amber-700(?! dark:text-amber-300)/g, 'text-amber-700 dark:text-amber-300'],
  [/text-amber-600(?! dark:text-amber-400)/g, 'text-amber-600 dark:text-amber-400'],
  [/text-yellow-800(?! dark:text-yellow-200)/g, 'text-yellow-800 dark:text-yellow-200'],
  [/text-yellow-700(?! dark:text-yellow-300)/g, 'text-yellow-700 dark:text-yellow-300'],
  [/text-yellow-600(?! dark:text-yellow-400)/g, 'text-yellow-600 dark:text-yellow-400'],
  [/text-purple-600(?! dark:text-purple-400)/g, 'text-purple-600 dark:text-purple-400'],
  [/text-indigo-600(?! dark:text-indigo-400)/g, 'text-indigo-600 dark:text-indigo-400'],
  [/text-primary-700(?! dark:text-primary-300)/g, 'text-primary-700 dark:text-primary-300'],
  [/text-primary-600(?! dark:text-primary-400)/g, 'text-primary-600 dark:text-primary-400'],
  // Icon hover colors
  [/hover:text-primary-600(?! dark:hover:text-primary-400)/g, 'hover:text-primary-600 dark:hover:text-primary-400'],
  [/hover:text-red-600(?! dark:hover:text-red-400)/g, 'hover:text-red-600 dark:hover:text-red-400'],
  [/hover:text-gray-700(?! dark:hover:text-gray-200)/g, 'hover:text-gray-700 dark:hover:text-gray-200'],
  [/hover:text-gray-900(?! dark:hover:text-gray-100)/g, 'hover:text-gray-900 dark:hover:text-gray-100'],
];

function applyDarkMode(content) {
  let result = content;
  for (const [search, replace] of replacements) {
    result = result.replace(search, replace);
  }
  return result;
}

const files = fs.readdirSync(PAGES_DIR).filter(f => /\.(jsx|tsx|ts|js)$/.test(f));
let updated = 0;

for (const file of files) {
  const filePath = path.join(PAGES_DIR, file);
  const original = fs.readFileSync(filePath, 'utf8');
  const transformed = applyDarkMode(original);
  if (transformed !== original) {
    fs.writeFileSync(filePath, transformed, 'utf8');
    updated++;
    console.log(`✓ Updated ${file}`);
  } else {
    console.log(`- No changes: ${file}`);
  }
}

console.log(`\nDone. Updated ${updated} of ${files.length} files.`);