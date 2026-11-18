import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [vue()],
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        proxy: Object.fromEntries(['/api/', '/docs/', '/openapi.json'].map(path => [
            path, process.env.DOCHAZKA_DEV_BACKEND || 'http://127.0.0.1:8080',
        ])),
    },
    preview: {proxy:{}},
    build: {outDir:'../public', emptyOutDir:false},
})
