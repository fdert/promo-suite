/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        ink: {
          DEFAULT: '#15161C',
          soft: '#6B6E76',
          faint: '#9A9CA5',
        },
        paper: '#F7F6F3',
        accent: {
          DEFAULT: '#E8542C',
          dark: '#C93F1B',
          light: '#FBE4DB',
        },
        line: '#E4E2DC',
        success: { DEFAULT: '#1F8A5F', light: '#DFF3EB' },
        danger: { DEFAULT: '#D64545', light: '#FBE3E3' },
        warning: { DEFAULT: '#C98A1A', light: '#FBEED8' },
      },
      fontFamily: {
        display: ['Cairo', 'sans-serif'],
        body: ['Tajawal', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
