<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Roznamcha photo — {{ config('ricemill.product.name') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
        main { max-width: 420px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-size: 20px; margin: 0 0 8px; color: {{ config('ricemill.product.green') }}; }
        p { color: #4b5563; font-size: 15px; line-height: 1.45; }
        .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .btn { display: block; width: 100%; box-sizing: border-box; text-align: center; padding: 16px; border-radius: 10px; border: 0;
            font-size: 17px; font-weight: 600; background: {{ config('ricemill.product.green') }}; color: #fff; margin-top: 14px; }
        .btn.secondary { background: #e5e7eb; color: #111827; }
        input[type=file] { display: none; }
        img { width: 100%; border-radius: 8px; margin-top: 12px; display: none; }
        .ok { color: #15803d; font-weight: 600; }
        .err { color: #b91c1c; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Roznamcha page photo</h1>
        @if ($expired)
            <p class="err">This link has expired. Make a new one on the computer.</p>
        @elseif ($sent)
            <p class="ok">Photo sent. Go back to the computer: it is on the form now.</p>
        @else
            @if (! empty($error))
                <p class="err">{{ $error }}</p>
            @endif
            <p>Lay the page flat in good light, fill the frame with it, and keep the phone straight above it.</p>
            <form method="post" action="{{ $action }}" enctype="multipart/form-data" id="f">
                @csrf
                <label class="btn" for="photo">Open the camera</label>
                <input id="photo" name="photo" type="file" accept="image/*" capture="environment" required>
                <img id="preview" alt="The photo">
                <button class="btn" type="submit" id="send" style="display:none">Send this photo</button>
                <label class="btn secondary" for="photo" id="again" style="display:none">Take it again</label>
            </form>
            <p id="status" style="display:none"></p>
            <script>
                // The photo is made smaller on the phone (at most 2000 pixels a side, JPEG) before it is sent: a camera's
                // 5-12 MB picture goes up as a few hundred KB, and an iPhone's HEIC photo becomes a JPEG.
                var shrunk = null;
                function shrink(file) {
                    return new Promise(function (resolve) {
                        var img = new Image();
                        img.onload = function () {
                            var scale = Math.min(1, 2000 / Math.max(img.naturalWidth, img.naturalHeight));
                            var c = document.createElement('canvas');
                            c.width = Math.round(img.naturalWidth * scale);
                            c.height = Math.round(img.naturalHeight * scale);
                            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                            c.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.85);
                        };
                        img.onerror = function () { resolve(null); };   // a picture this browser cannot read: sent as it is
                        img.src = URL.createObjectURL(file);
                    });
                }
                function say(text, bad) {
                    var s = document.getElementById('status');
                    s.textContent = text;
                    s.className = bad ? 'err' : '';
                    s.style.display = 'block';
                }
                document.getElementById('photo').addEventListener('change', function (e) {
                    var file = e.target.files && e.target.files[0];
                    if (!file) return;
                    var img = document.getElementById('preview');
                    img.src = URL.createObjectURL(file);
                    img.style.display = 'block';
                    document.getElementById('send').style.display = 'block';
                    document.getElementById('again').style.display = 'block';
                    document.querySelector('label[for=photo].btn:not(.secondary)').style.display = 'none';
                    shrunk = null;
                    shrink(file).then(function (b) { shrunk = b; });
                });
                document.getElementById('f').addEventListener('submit', function (e) {
                    var file = document.getElementById('photo').files[0];
                    if (!file || !window.fetch || !window.FormData) return;   // old browser: the plain form sends it
                    e.preventDefault();
                    var send = document.getElementById('send');
                    send.disabled = true;
                    say('Sending the photo…');
                    var go = function (b) {
                        var data = new FormData();
                        var token = document.querySelector('input[name=_token]');
                        if (token) data.append('_token', token.value);
                        data.append('photo', b || file, b ? 'page.jpg' : file.name);
                        fetch(document.getElementById('f').action, { method: 'POST', body: data, credentials: 'same-origin' })
                            .then(function (r) { return r.text(); })
                            .then(function (html) { document.open(); document.write(html); document.close(); })
                            .catch(function () { send.disabled = false; say('The photo was not sent: no internet. Press Send again.', true); });
                    };
                    shrunk ? go(shrunk) : shrink(file).then(go);
                });
            </script>
        @endif
    </div>
</main>
</body>
</html>
