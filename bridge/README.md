# QID Online Bridge

Lets the mobile app reach your XAMPP PC **through your own hosting**, without the
phone ever connecting to the PC directly.

Use this when the phone and PC cannot talk on the local network — Wi-Fi client
isolation, guest networks, staff working from a different building, or the PC
sitting behind a router you cannot configure.

```
  phone  ──POST /api/scan_push.php──▶  yourdomain.com/qidbridge  ◀──long poll──  PC agent
                                              (relay)                                │
                                                                  http://localhost/QID
                                                                   MySQL + all records
```

Both sides only ever make **outbound** connections, which is why this works when
nothing else does. Your database, uploads and records never leave the PC. The
bridge holds one request and its reply for the second it takes to pass them on,
then deletes them.

**Be aware:** scan traffic does pass *through* your hosting in transit, so the host
can see it. If that is not acceptable, use a mesh VPN such as Tailscale instead,
which is encrypted end to end.

---

## What you need

Ordinary shared cPanel hosting. PHP 7.0+, a writable folder. No database, no cron,
no shell access.

---

## Setup

### 1. Generate a key

On the XAMPP PC:

```bash
F:\xampp\php\php.exe -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Copy the string it prints. It is the shared secret that proves to the bridge that
your PC — and only your PC — may pick up jobs.

### 2. Upload the bridge

Upload this whole `bridge/` folder to your hosting, for example to
`/public_html/qidbridge`, so it answers at `https://yourdomain.com/qidbridge`.

On the host, copy `bridge_config.sample.php` to `bridge_config.php` and set the key:

```php
define('BRIDGE_KEY', 'paste-the-key-here');
```

Then in cPanel File Manager set the `jobs/` folder permissions to **0775**.

Check it by opening `https://yourdomain.com/qidbridge` in a browser. You should see:

```json
{"status":"ok","app":"qid_bridge","agent_online":false}
```

`agent_online: false` is expected until step 3.

### 3. Connect the PC

On the PC, copy `tools/bridge_config_agent.sample.php` to
`tools/bridge_config_agent.php` and fill in:

```php
define('BRIDGE_URL',     'https://yourdomain.com/qidbridge');
define('BRIDGE_KEY',     'paste-the-same-key-here');
define('LOCAL_BASE_URL', 'http://localhost/QID');
```

Then run `tools\start_bridge_agent.bat` and leave the window open. It should say:

```
Local QID system OK
Bridge reachable
Waiting for requests...
```

Reload the bridge URL in a browser — `agent_online` is now `true`.

To start it automatically with Windows, put a shortcut to
`start_bridge_agent.bat` in `shell:startup`.

### 4. Point the app at it

In the app's **Server URL**, enter `https://yourdomain.com/qidbridge` and log in.

The app treats any address that is not a bare IP as fixed: it will not scan the
Wi-Fi and will never overwrite it when the phone changes network.

---

## Checking it works

Every relayed request is logged in the agent window:

```
[18:13:03] POST /api/scan_push.php -> 200 (21ms)
```

Expect 200–500ms per scan over the internet, against ~20ms on the LAN. Fine for
scanning; the local UDP discovery is still faster when both are on the same Wi-Fi.

---

## Security

- Only `/api/*.php` paths are relayed. The admin site is **never** reachable
  through the bridge, so a leaked bridge URL cannot open the web portal.
- `bridge_config.php` and `jobs/` are blocked from the web by `.htaccess`.
- The agent key is compared with `hash_equals`, so it cannot be guessed one
  character at a time.
- The app's own login and token auth still applies on top of all this.
- Both config files are git-ignored so your key is never committed.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `agent_online: false` | The agent is not running on the PC, or the two keys differ |
| `503 The QID PC is not connected` | Same as above |
| `504 did not answer in time` | XAMPP Apache is stopped, or the PC lost internet |
| `403 Invalid agent key` | `BRIDGE_KEY` differs between host and PC |
| `500 jobs/ is not writable` | Set `jobs/` to 0775 in cPanel |
| App jumps back to a LAN IP | You are on an old build; update the app |
