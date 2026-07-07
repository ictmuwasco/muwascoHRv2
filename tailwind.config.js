/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    // Frontend pages & components
    "./frontend/pages/**/*.php",
    "./frontend/components/**/*.php",
    // Backend views (all PHP views)
    "./backend/app/Views/**/*.php",
    // Root files
    "./index.php",
    "./*.php",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: "#00d4ff20",
          100: "#00d4ff40",
          200: "#00d4ff60",
          300: "#00d4ff80",
          400: "#00d4ff",
          500: "#00d4ff",
          600: "#0099cc",
          700: "#007a99",
          800: "#005c73",
          900: "#003d4d",
        },
        secondary: {
          50: "#6c5ce720",
          100: "#6c5ce740",
          200: "#6c5ce760",
          300: "#6c5ce780",
          400: "#6c5ce7",
          500: "#6c5ce7",
          600: "#5a4bc4",
          700: "#483ba2",
          800: "#362b80",
          900: "#241a5e",
        },
        accent: "#ff3366",
        success: "#00b894",
        warning: "#fdcb6e",
        error: "#e17055",
        dark: {
          bg: "#0f0f23",
          secondary: "#1a1a2e",
          tertiary: "#16213e",
          card: "rgba(26, 26, 46, 0.8)",
          glass: "rgba(255, 255, 255, 0.05)",
          modal: "rgba(26, 26, 46, 0.95)",
        },
        light: {
          bg: "#ffffff",
          secondary: "#f8f9fa",
          tertiary: "#e9ecef",
          card: "rgba(255, 255, 255, 0.9)",
          glass: "rgba(255, 255, 255, 0.1)",
          modal: "rgba(255, 255, 255, 0.95)",
        },
      },
      fontFamily: {
        sans: ["Inter", "-apple-system", "BlinkMacSystemFont", "sans-serif"],
        mono: ["JetBrains Mono", "monospace"],
      },
      boxShadow: {
        sm: "0 2px 8px rgba(0, 0, 0, 0.1)",
        md: "0 4px 16px rgba(0, 0, 0, 0.2)",
        lg: "0 8px 32px rgba(0, 0, 0, 0.3)",
        glow: "0 0 20px rgba(0, 212, 255, 0.2)",
        "glow-primary": "0 0 20px rgba(0, 212, 255, 0.3)",
        "glow-success": "0 0 20px rgba(0, 184, 148, 0.3)",
        "glow-warning": "0 0 20px rgba(253, 203, 110, 0.3)",
        "glow-danger": "0 0 20px rgba(225, 112, 85, 0.3)",
        "glow-info": "0 0 20px rgba(108, 92, 231, 0.3)",
      },
      backdropBlur: {
        xs: "2px",
        sm: "10px",
        md: "20px",
        lg: "30px",
      },
      borderRadius: {
        xl: "16px",
        "2xl": "20px",
      },
      animation: {
        slideDown: "slideDown 0.4s ease-out",
        slideUp: "slideUp 0.3s ease-out",
        fadeIn: "fadeIn 0.3s ease-out",
        shimmer: "shimmer 1.5s ease-in-out infinite",
        float: "float 20s ease-in-out infinite",
        drift: "drift 20s linear infinite",
      },
      keyframes: {
        slideDown: {
          "0%": { transform: "translateY(-20px)", opacity: "0" },
          "100%": { transform: "translateY(0)", opacity: "1" },
        },
        slideUp: {
          "0%": { transform: "translate(-50%, -40%)", opacity: "0" },
          "100%": { transform: "translate(-50%, -50%)", opacity: "1" },
        },
        fadeIn: {
          "0%": { opacity: "0" },
          "100%": { opacity: "1" },
        },
        shimmer: {
          "0%": { backgroundPosition: "200% 0" },
          "100%": { backgroundPosition: "-200% 0" },
        },
        float: {
          "0%, 100%": { transform: "translate(0, 0) scale(1)" },
          "33%": { transform: "translate(40px, -30px) scale(1.05)" },
          "66%": { transform: "translate(-20px, 20px) scale(0.95)" },
        },
        drift: {
          "0%": { bottom: "-10px", opacity: "0", transform: "translateX(0)" },
          "10%": { opacity: "1" },
          "90%": { opacity: "1" },
          "100%": { bottom: "100%", opacity: "0", transform: "translateX(40px)" },
        },
      },
      backgroundImage: {
        "gradient-radial": "radial-gradient(var(--tw-gradient-stops))",
        "gradient-conic":
          "conic-gradient(from 180deg at 50% 50%, var(--tw-gradient-stops))",
      },
    },
  },
  plugins: [],
};