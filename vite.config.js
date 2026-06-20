import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'resources/js'),
    },
  },
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, 'resources/js/app.js'),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'app.css'
          }
          return '[name].[ext]'
        },
        // Single chunk output - no code splitting for package distribution
        manualChunks: undefined,
        inlineDynamicImports: true,
      },
    },
    // Target modern browsers; the blade template uses type=module
    target: 'es2020',
    sourcemap: false,
    minify: 'esbuild',
  },
  css: {
    postcss: './postcss.config.js',
  },
})
