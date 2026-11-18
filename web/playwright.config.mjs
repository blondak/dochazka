import { defineConfig } from '@playwright/test'

const baseURL = 'http://127.0.0.1:4176'

export default defineConfig({
    testDir: './tests',
    testMatch: '**/*.spec.mjs',
    outputDir: '../var/ui-tests',
    forbidOnly: !!process.env.CI,
    fullyParallel: true,
    use: {
        baseURL,
        timezoneId: 'Europe/Prague',
        launchOptions: {
            executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
        },
    },
    webServer: {
        command: 'vite preview --host 127.0.0.1 --port 4176 --strictPort',
        url: baseURL,
    },
})
