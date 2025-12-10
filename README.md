# COMING SOON

# rounddav_files

[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/rounddav_files?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/rounddav_files)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/rounddav_files?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/rounddav_files)
[![Github License](https://img.shields.io/github/license/texxasrulez/rounddav_files?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/rounddav_files/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/rounddav_files?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/rounddav_files/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/rounddav_files?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/rounddav_files/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/rounddav_files?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/rounddav_files/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/rounddav_files?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/rounddav_files/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

WebDAV Files integration for Roundcube, powered by RoundDAV.

This plugin adds a **Files** tab to Roundcube and lets users attach files directly from their RoundDAV storage into email messages.

This plugin requires [RoundDAV Server](https://github.com/texxasrulez/rounddav) to function.

Check out the [Suite README](README_suite.md) to see what is entailed.

---

## Features

- Adds a **Files** entry to Roundcube's main menu
- Embeds the RoundDAV Files UI inside an iframe
- Uses SSO from `rounddav_provision` (no extra login)
- Adds an **Attach from RoundDAV** button in the compose window
- Works with Elastic and Larry-based skins (including variants)
- Respects Roundcube's layout and styling conventions

---

## Installation

1. Copy plugin into Roundcube:

```text
roundcube/plugins/rounddav_files/
```

2. Enable it in Roundcube config:

```php
$config['plugins'][] = 'rounddav_files';
```

3. Configure URLs in `config.inc.php` or your main config:

```php
// Where the iframe should point to for the Files UI.
// %u will be replaced with the URL-encoded Roundcube username.
$config['rounddav_files_url'] = 'https://your.server/rounddav/public/files/?user=%u';

// (Optional) Endpoint for attachment operations if you implement them as a RoundDAV API
$config['rounddav_attach_url'] = 'https://your.server/rounddav/public/api.php?r=attach';
```

---

## UI Integration

### Files Tab

The plugin:

- Registers a new task: `rounddav_files`
- Adds a **Files** icon/entry in the Roundcube main menu
- Loads a template that embeds the RoundDAV `/public/files/` UI inside an iframe

Example Elastic template snippet:

```html
<roundcube:include file="includes/layout.html" />
<roundcube:include file="includes/menu.html" />

<h1 class="voice"><roundcube:label name="rounddav_files.files" /></h1>

<div id="layout-content" class="selected" role="main">
    <div class="iframe-wrapper">
        <roundcube:object
            name="rounddav_files_frame"
            id="rounddav-files-frame"
            src="env:blankpage"
            title="arialabelrounddavfilescontent"
        />
    </div>
</div>

<roundcube:include file="includes/footer.html" />
```

### Compose Window

The plugin injects a small **Attach from RoundDAV** button inside the attachments area of the compose screen. The header-level button is intentionally hidden to avoid clutter.

When clicked:

1. Opens the Files UI (either embedded or as a modal / separate window depending on your setup).
2. Lets the user pick files from their RoundDAV storage.
3. Posts the selected files back to Roundcube using a plugin action to turn them into real message attachments.

---

## SSO Behavior

`rounddav_files` integrates with `rounddav_provision` through `$_SESSION`:

- After login, `rounddav_provision` stores a one-shot SSO URL in:

  ```php
  $_SESSION['rounddav_sso_login_url']
  ```

- When rendering the Files iframe, `rounddav_files`:

  1. Checks `$_SESSION['rounddav_sso_login_url']`.
  2. If present:
     - Uses that value as the iframe `src`.
     - Unsets it to avoid reuse.
  3. If not present:
     - Falls back to `rounddav_files_url` (with `%u` replaced by the username).

This means:

- First visit after login → automatic SSO via `/sso_login.php`
- Later visits → direct Files URL (as long as the RoundDAV session is still valid)

---

## Logging

The plugin logs to the `roundcube` log channel when it decides which URL to use for the iframe, e.g.:

- `rounddav_files: using SSO URL for iframe src: ...`
- `rounddav_files: using fallback files URL template=... for user=...`

These messages are helpful when debugging SSO behavior.

---

## Philosophy

Webmail without file storage feels incomplete.

`rounddav_files` is the missing piece that lets Roundcube act like a modern client: email, calendars, contacts, and files, all with one login.

Enjoy!

:moneybag: **Donations** :moneybag:

If you use this plugin and would like to show your appreciation by buying me a cup of coffee, I surely would appreciate it. A regular cup of Joe is sufficient, but a Starbucks Coffee would be better ... \
Zelle (Zelle is integrated within many major banks Mobile Apps by default) - Just send to texxasrulez at yahoo dot com \
No Zelle in your banks mobile app, no problem, just click [Paypal](https://paypal.me/texxasrulez?locale.x=en_US) and I can make a Starbucks run ...

I appreciate the interest in this plugin and hope all the best ...
