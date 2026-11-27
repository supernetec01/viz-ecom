import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { cpSync } from 'node:fs';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/private-file-viewer.js'],
            refresh: true,
        }),
        tailwindcss(),
        {
            name: 'local-pdfjs-assets',
            closeBundle() {
                for (const name of ['cmaps', 'standard_fonts', 'LICENSE']) {
                    cpSync(`node_modules/pdfjs-dist/${name}`, `public/build/pdfjs/${name}`, { recursive: true });
                }
            },
        },
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
