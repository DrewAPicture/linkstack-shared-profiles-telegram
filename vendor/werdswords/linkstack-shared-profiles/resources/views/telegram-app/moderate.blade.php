<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderator Login</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { font-family: sans-serif; padding: 1rem; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #000); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; box-sizing: border-box; }
        .message { text-align: center; }
        .message p { margin: 0; font-size: 0.9375rem; }
        .error { color: var(--tg-theme-destructive-text-color, #cc2424); }
    </style>
</head>
<body>
    <div class="message">
        <p id="status">Authenticating…</p>
    </div>

    <script>
        const tg = window.Telegram.WebApp;
        tg.ready();

        const status = document.getElementById('status');

        fetch('/telegram-login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ init_data: tg.initData }),
        })
        .then(async (res) => {
            if (res.ok) {
                const data = await res.json();
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            } else {
                status.textContent = 'You are not authorised to access this area.';
                status.classList.add('error');
            }
        })
        .catch(() => {
            status.textContent = 'Network error. Please close and try again.';
            status.classList.add('error');
        });
    </script>
</body>
</html>
