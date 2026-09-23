# QID Online Bridge

One file. One link. Use it when the phone cannot reach the PC over Wi-Fi —
office or guest networks that block device-to-device traffic, or staff working
from somewhere else entirely.

```
 phone ──request──▶ bridge.php on your hosting ◀──waiting── PC connector
                                                                  │
                                                  http://localhost/QID
                                                   MySQL + all records
```

Neither side accepts an incoming connection — both dial **out** — which is why
this works when nothing on the local network does.

Your database, uploads and records stay on the PC. The bridge holds one request
and its reply for the second it takes to pass them along, then deletes them.

---

## Setup

**1. Upload one file**

Put `bridge.php` anywhere on your hosting, e.g. `public_html/qidbridge/bridge.php`.
Nothing else — no config file, no `.htaccess`, no folders to create.

**2. Open it in a browser**

Go to `https://yourdomain.com/qidbridge/bridge.php`. It shows **your link**:

```
https://yourdomain.com/qidbridge/bridge.php?k=016b693b5e0f7835b535937c
```

Copy it. The key is generated once, by the bridge itself — there is nothing for
you to invent or keep in sync.

**3. Paste it in QID Settings**

On the PC: QID → **Settings** → **Online Bridge Link** → paste → Save.

**4. Run the connector**

Double-click `tools\qid_connect.bat` and leave the window open. This is what
keeps the PC reachable. It should say:

```
Local QID system OK
Bridge reachable
Waiting for the app...
```

To start it with Windows: `Win+R` → `shell:startup` → put a shortcut there.

**5. Paste the same link in the app**

App → **Server URL** → paste the same link → log in.

---

## Checking it

Reload the bridge page in a browser. It shows **PC connector: CONNECTED** once
step 4 is running.

Every relayed request is logged in the connector window:

```
[18:32:55] GET /api/discovery.php -> 200 (58ms)
```

Expect 200–500ms per scan over the internet. The local Wi-Fi path is faster when
it works, and the app still prefers it automatically.

---

## Security

- Only `/api/*.php` is relayed. The admin portal is **never** reachable through
  the bridge, so a leaked link cannot open your web pages.
- A wrong or missing key is refused.
- The app's own login and token auth still applies on top.
- Keep the link private — it identifies your bridge.
- Traffic does pass *through* your hosting in transit, so the host could see it.
  If that matters, use a mesh VPN such as Tailscale instead, which is encrypted
  end to end.

## Troubleshooting

| What you see | What it means |
|---|---|
| `PC connector: NOT CONNECTED` | `qid_connect.bat` is not running on the PC |
| `503 The QID PC is not connected` | Same |
| `Wrong bridge link` | The saved link does not match the bridge; re-copy it |
| `504 did not answer in time` | XAMPP Apache stopped, or the PC lost internet |
| `cannot write next to itself` | Set the bridge folder to 0755 in File Manager |
| App can't connect, browser can | Check you pasted the **whole** link including `?k=...` |
