/** @type {import('tailwindcss').Config} */
module.exports = {
  // Scope to app sources only — avoid scanning node_modules/vendor (see Tailwind content docs).
  content: [
    './*.{php,html}',
    './admin/**/*.{php,html}',
    './includes/**/*.php',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['DM Sans', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
