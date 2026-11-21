// Only top-level app navigations need an offline response. All API requests go directly to the server.
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()))

self.addEventListener('fetch', event => {
    const request = event.request
    if (request.method !== 'GET' || request.mode !== 'navigate') return
    const url = new URL(request.url)
    if (url.origin !== self.location.origin || !['/', '/index.html'].includes(url.pathname)) return

    event.respondWith(fetch(request, {cache: 'no-store'}).catch(() => new Response(offlinePage, {
        status: 503,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        },
    })))
})

const offlinePage = `<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title>Docházka bez připojení</title>
    <style>
        body { margin: 0; padding: 1.5rem; color: #172422; background: #f8faf9; font: 1rem/1.5 system-ui, sans-serif; }
        main { max-width: 32rem; margin: 12vh auto; }
        a { display: inline-block; padding: .7rem 1.2rem; border-radius: .4rem; color: white; background: #065f46; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <h1>Docházka bez připojení</h1>
        <p>Centrála je nedostupná. Zkontrolujte připojení a zkuste stránku načíst znovu.</p>
        <a href="">Zkusit znovu</a>
    </main>
</body>
</html>`
