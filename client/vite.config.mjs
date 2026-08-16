import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";

const katraServerUrl = process.env.KATRA_SERVER_URL ?? "http://127.0.0.1:8000";

export default defineConfig({
  build: {
    outDir: "dist/client",
  },
  optimizeDeps: {
    include: ["vue", "@lucide/vue"],
  },
  server: {
    host: "0.0.0.0",
    allowedHosts: ["terminal.local"],
    proxy: {
      "/api": {
        target: katraServerUrl,
        changeOrigin: true,
      },
      "/auth": {
        target: katraServerUrl,
        changeOrigin: true,
      },
      "/broadcasting": {
        target: katraServerUrl,
        changeOrigin: true,
      },
      "/sanctum": {
        target: katraServerUrl,
        changeOrigin: true,
      },
    },
    warmup: {
      clientFiles: ["./src/main.ts"],
    },
  },
  plugins: [vue(), tailwindcss()],
});
