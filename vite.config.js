import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
  plugins: [
    laravel({
      input: [
        'resources/assets/js/app.js',
        'resources/assets/sass/app.scss',
        'resources/assets/sass/app-dark.scss',
      ],
      refresh: true,
    }),
  ],
});
