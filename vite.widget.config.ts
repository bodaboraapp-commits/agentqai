import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  // This is a library build, not a site. Vite's default publicDir copies the
  // whole of public/ into outDir on every run, so each widget build dropped a
  // second copy of index.php, .htaccess, the favicons and the entire main
  // public/build bundle (~31 MB) under public/widget/ — all of it shipped to
  // buyers, and it buried the widget's own output. The widget needs none of it.
  publicDir: false,
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './resources/js'),
    },
  },
  build: {
    lib: {
      entry: path.resolve(__dirname, 'resources/js/widget.tsx'),
      name: 'AgentQSoftphone',
      fileName: (format) => `softphone.${format}.js`,
      formats: ['umd', 'es'],
      cssFileName: 'softphone',
    },
    rollupOptions: {
      output: {
        // Provide global variables to use in the UMD build
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
        },
        assetFileNames: 'softphone.[ext]',
      },
    },
    outDir: 'public/widget',
    // outDir now receives nothing but this build's own files, so emptying it
    // first cannot delete anything the widget did not produce.
    emptyOutDir: true,
    sourcemap: true,
    minify: 'terser',
    cssCodeSplit: false,
    terserOptions: {
      compress: {
        drop_console: false, // Keep console for debugging
      },
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
});
