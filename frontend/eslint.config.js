import js from '@eslint/js'
import globals from 'globals'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tsPlugin from '@typescript-eslint/eslint-plugin'
import tsParser from '@typescript-eslint/parser'

/**
 * ESLint flat config (ESLint 9).
 *
 * Philosophy: correctness rules are hard errors; style-opinion rules that the
 * codebase (which predates lint enforcement) would violate en masse are left
 * off so `--max-warnings 0` stays meaningful. Lint the whole repo with:
 *   npm run lint
 */
export default [
  {
    ignores: ['dist/**', 'coverage/**', 'node_modules/**', 'audit-report.txt', 'scan-imports.cjs'],
  },

  // Base JS recommended rules (applies to every linted file).
  js.configs.recommended,

  {
    files: ['**/*.{js,jsx,ts,tsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parser: tsParser,
      parserOptions: { ecmaFeatures: { jsx: true } },
      globals: { ...globals.browser },
    },
    settings: { react: { version: 'detect' } },
    plugins: {
      react,
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
      '@typescript-eslint': tsPlugin,
    },
    rules: {
      // ---- unused vars -----------------------------------------------------
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrors: 'none',
        },
      ],
      'no-undef': 'error',

      // ---- React correctness -----------------------------------------------
      'react/jsx-uses-vars': 'error',
      'react/jsx-uses-react': 'error',
      'react/jsx-no-undef': 'error',
      'react/jsx-no-duplicate-props': 'error',
      'react/jsx-key': 'error',
      'react/jsx-no-target-blank': 'error',
      'react/no-children-prop': 'error',
      'react/no-danger-with-children': 'error',
      'react/no-direct-mutation-state': 'error',
      'react/no-string-refs': 'error',
      'react/no-find-dom-node': 'error',
      'react/no-is-mounted': 'error',
      'react/no-render-return-value': 'error',
      'react/no-deprecated': 'error',
      'react/no-unknown-property': 'error',
      'react/require-render-return': 'error',

      // ---- Style-opinion rules relaxed for this codebase --------------------
      'react/react-in-jsx-scope': 'off', // react-jsx transform
      'react/prop-types': 'off',
      'react/display-name': 'off',
      'react/no-unescaped-entities': 'off',

      // ---- Hooks -------------------------------------------------------------
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'off',

      // ---- HMR hygiene --------------------------------------------------------
      'react-refresh/only-export-components': 'off',
    },
  },

  {
    // TypeScript guarantees defined names; avoid no-undef false positives.
    files: ['**/*.ts', '**/*.tsx'],
    rules: {
      'no-undef': 'off',
    },
  },

  {
    // Vitest is configured with globals: true (vite.config.js).
    files: ['**/*.{test,spec}.{js,jsx,ts,tsx}', '**/__tests__/**'],
    languageOptions: {
      globals: {
        describe: 'readonly',
        it: 'readonly',
        test: 'readonly',
        expect: 'readonly',
        vi: 'readonly',
        beforeEach: 'readonly',
        afterEach: 'readonly',
        beforeAll: 'readonly',
        afterAll: 'readonly',
      },
    },
  },

  {
    // Node-context config files.
    files: ['*.config.js', '*.config.ts', '*.cjs'],
    languageOptions: {
      globals: { ...globals.node },
    },
  },

  {
    // Service worker (public/sw.js) runs in the SW global scope.
    files: ['public/sw.js'],
    languageOptions: {
      globals: { ...globals.serviceworker },
    },
  },
]
