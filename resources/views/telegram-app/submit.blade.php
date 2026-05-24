<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit a Link</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { font-family: sans-serif; padding: 1rem; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #000); }
        label { display: block; margin-bottom: 0.25rem; font-size: 0.875rem; }
        input { display: block; width: 100%; box-sizing: border-box; padding: 0.5rem; margin-bottom: 1rem; border: 1px solid var(--tg-theme-hint-color, #ccc); border-radius: 0.375rem; font-size: 1rem; background: var(--tg-theme-secondary-bg-color, #f5f5f5); color: inherit; }
        button { width: 100%; padding: 0.625rem; background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); border: none; border-radius: 0.375rem; font-size: 1rem; cursor: pointer; }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        #error { color: var(--tg-theme-destructive-text-color, #cc2424); margin-top: 0.75rem; font-size: 0.875rem; }
    </style>
</head>
<body>
    <form id="submit-form" novalidate>
        <label for="url">URL</label>
        <input type="url" id="url" name="url" placeholder="https://example.com" required>

        <label for="title">Title</label>
        <input type="text" id="title" name="title" maxlength="255" required>

        <button type="submit" id="submit-btn">Submit</button>

        <p id="error" hidden></p>
    </form>

    <script>
        const tg = window.Telegram.WebApp;
        tg.ready();

        const form = document.getElementById('submit-form');
        const btn  = document.getElementById('submit-btn');
        const err  = document.getElementById('error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            err.hidden = true;
            btn.disabled = true;

            try {
                const res = await fetch('/telegram/submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        init_data: tg.initData,
                        link:      document.getElementById('url').value,
                        title:     document.getElementById('title').value,
                    }),
                });

                if (res.ok) {
                    tg.close();
                } else {
                    const data = await res.json().catch(() => ({}));
                    err.textContent = data.message ?? 'Submission failed. Please try again.';
                    err.hidden = false;
                }
            } catch {
                err.textContent = 'Network error. Please try again.';
                err.hidden = false;
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>
