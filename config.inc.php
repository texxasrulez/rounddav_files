<?php

// URL to RoundDAV Files UI (cloud button / Files task / picker iframe).
// %u is replaced with the Roundcube username (e.g. user@domain.com).
$config['rounddav_files_url'] = 'https://www.genesworld.net/rounddav/public/files/index.php?user=%u';

// URL used when actually ATTACHING a file from RoundDAV into Roundcube.
// %u is Roundcube username.
// Optional: %f will be replaced by the file identifier/path coming from the picker.
// If %f is NOT present, the plugin will append ?file=<href> or &file=<href>.
$config['rounddav_attach_files_url'] = 'https://www.genesworld.net/rounddav/public/files/rc_attach.php?user=%u';

// Absolute paths for direct filesystem access (faster than HTTP fallback)
$config['rounddav_files_fs_root'] = '/home/gene/web/genesworld.net/private/rounddav-files/users/%u';
$config['rounddav_files_shared_fs_root'] = '/home/gene/web/genesworld.net/private/rounddav-files/shared';

// Reserved for future SSO work
$config['rounddav_files_sso_enabled'] = false;
$config['rounddav_files_sso_secret']  = '';

// Reserved for admin iframe in Settings (TODO)
// $config['rounddav_files_admin_users'] = [
    // 'user@domain.com',
// ];



