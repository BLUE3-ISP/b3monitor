import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// O painel usa o CoreUI 5 (D-06) e fontes do sistema: sem plugin de fontes, que baixaria de um CDN na hora do build, e sem Tailwind.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/painel.css'],
            refresh: true,
        }),
    ],
});
