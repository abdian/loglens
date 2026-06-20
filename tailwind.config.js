/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/js/**/*.{js,vue}',
    './resources/views/**/*.blade.php',
  ],
  darkMode: ['class', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: {
        mono: ['"JetBrains Mono"', '"JetBrains Mono Variable"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', '"Liberation Mono"', '"Courier New"', 'monospace'],
      },
      colors: {
        // Design token surface colors mapped from CSS variables
        surface: {
          DEFAULT: 'var(--ll-surface)',
          raised: 'var(--ll-surface-raised)',
          overlay: 'var(--ll-surface-overlay)',
          sunken: 'var(--ll-surface-sunken)',
        },
        border: {
          DEFAULT: 'var(--ll-border)',
          subtle: 'var(--ll-border-subtle)',
        },
        text: {
          DEFAULT: 'var(--ll-text)',
          muted: 'var(--ll-text-muted)',
          faint: 'var(--ll-text-faint)',
        },
        accent: {
          DEFAULT: 'var(--ll-accent)',
          hover: 'var(--ll-accent-hover)',
        },
        level: {
          debug: 'var(--ll-level-debug)',
          info: 'var(--ll-level-info)',
          notice: 'var(--ll-level-notice)',
          warning: 'var(--ll-level-warning)',
          error: 'var(--ll-level-error)',
          critical: 'var(--ll-level-critical)',
          alert: 'var(--ll-level-alert)',
          emergency: 'var(--ll-level-emergency)',
        },
      },
      animation: {
        'fade-in': 'fadeIn 0.15s ease-out',
        'slide-in-right': 'slideInRight 0.2s ease-out',
        'slide-in-left': 'slideInLeft 0.2s ease-out',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideInRight: {
          '0%': { transform: 'translateX(1rem)', opacity: '0' },
          '100%': { transform: 'translateX(0)', opacity: '1' },
        },
        slideInLeft: {
          '0%': { transform: 'translateX(-1rem)', opacity: '0' },
          '100%': { transform: 'translateX(0)', opacity: '1' },
        },
      },
    },
  },
  plugins: [],
}
