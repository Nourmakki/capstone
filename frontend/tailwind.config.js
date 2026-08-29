/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./src/**/*.{js,jsx,ts,tsx}"],
  theme: {
    extend: {
      screens: {
        // Height, not width. The material manager sidebar carries eight nav
        // items, and on a laptop the column ran past the bottom of the screen
        // and had to be scrolled to reach Logout. These let the sidebar give
        // up its padding on a short screen while leaving a tall one untouched.
        short: { raw: "(max-height: 780px)" },
        shorter: { raw: "(max-height: 660px)" },
      },
    },
  },
  plugins: [],
}
