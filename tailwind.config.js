/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.html",
    "./admin.html",
    "./assets/app.js"
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Vazirmatn', 'system-ui', 'sans-serif'],
        display: ['Righteous', 'Vazirmatn', 'sans-serif'],
      },
      colors: {
        spine: {
          DEFAULT: 'var(--brand)',
          dark: '#C8B087',
          light: '#EADBC2',
          card: '#E8D7B8'
        },
        deepBurgundy: '#1f0d11',
        coffeeDark: '#170c08',
        cardText: {
          DEFAULT: '#1c120c',
          muted: '#402619',
          badge: '#261208'
        }
      }
    }
  },
  plugins: [],
}
