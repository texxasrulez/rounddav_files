<?php

class rounddav_files extends rcube_plugin
{
    public $task = '.*';

    /** @var rcmail */
    private $rc;

    private function is_debug_enabled(): bool
    {
        return (bool) $this->rc->config->get('rounddav_files_debug', false);
    }

    private function write_log(string $channel, string $message): void
    {
        if (!$this->is_debug_enabled()) {
            return;
        }

        rcube::write_log($channel, $message);
    }

    public function init(): void
    {
        $this->rc = rcmail::get_instance();
        $this->load_config();

        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files init: task=%s action=%s',
                $this->rc->task,
                $this->rc->action
            )
        );

        $this->add_texts('localization/', true);

        $skin = (string) $this->rc->config->get('skin', 'larry');
        if ($skin === '') {
            $skin = 'larry';
        }

        $this->include_stylesheet('skins/' . $skin . '/rounddav_files.css');
        $this->include_script('rounddav_files.js');
        $this->include_script('rounddav_files_compose.js');

        $this->register_task('rounddav_files');
        $this->register_files_button();

        if ($this->rc->task === 'rounddav_files') {
            $this->rc->output->set_env('refresh_interval', 0);
            $this->register_action('index', [$this, 'action_index']);
        }

        if ($this->rc->task === 'mail') {
            // Catch our AJAX attach action early
            $this->add_hook('startup', [$this, 'on_startup']);

            if ($this->rc->action === 'compose') {
                $this->init_compose_integration();
            }
        }
    }

    public function on_startup(array $args): array
    {
        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files on_startup: task=%s action=%s',
                $this->rc->task,
                $this->rc->action
            )
        );

        if ($this->rc->task === 'mail' && $this->rc->action === 'plugin.rounddav_files_attach_files') {
            $this->write_log(
                'roundcube',
                'rounddav_files on_startup: dispatching attach_files()'
            );
            $this->attach_files();
            // attach_files() sends output and ends request
        }

        return $args;
    }

    private function init_compose_integration(): void
    {
        $files_tpl  = (string) $this->rc->config->get('rounddav_files_url', '');
        $attach_tpl = (string) $this->rc->config->get('rounddav_attach_files_url', '');
        $username   = (string) $this->rc->user->get_username();

        if ($files_tpl !== '') {
            $files_url = str_replace('%u', rawurlencode($username), $files_tpl);
            $this->rc->output->set_env('rounddav_files_url', $files_url);
        }

        if ($attach_tpl !== '') {
            $attach_url = str_replace('%u', rawurlencode($username), $attach_tpl);
            $this->rc->output->set_env('rounddav_attach_files_url', $attach_url);
        }
    }

    private function register_files_button(): void
    {
        $this->add_button(
            [
                'command'  => 'rounddav_files',
                'type'     => 'link',
                'label'    => 'rounddav_files.files',
                'title'    => 'rounddav_files.files',
                'domain'   => 'rounddav_files',
                'class'    => 'button-rounddav-files button-files',
                'classsel' => 'button-rounddav-files button-files button-selected',
                'id'       => 'taskbarrounddav_files',
            ],
            'taskbar'
        );
    }

    public function action_index(): void
    {
        $this->rc->output->add_handlers([
            'rounddav_files_frame' => [$this, 'files_frame'],
        ]);

        $this->rc->output->set_pagetitle($this->gettext('files'));
        $this->rc->output->send('rounddav_files.files');
    }

    public function files_frame(array $attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rounddav-files-frame';
        }

        $attrib['name'] = $attrib['id'];

        // Prefer a one-shot SSO login URL prepared by the rounddav_provision plugin.
        // If present, this will transparently log the user into the RoundDAV web UI
        // and then redirect to the Files interface.
        $sso_url = isset($_SESSION['rounddav_sso_login_url']) ? $_SESSION['rounddav_sso_login_url'] : null;

        if (!empty($sso_url)) {
            $url = $sso_url;
            // Clear it so the token is not reused indefinitely
            unset($_SESSION['rounddav_sso_login_url']);
            $this->write_log('roundcube', 'rounddav_files: using SSO URL for iframe src: ' . $url);
        } else {
            $url_tpl  = (string) $this->rc->config->get('rounddav_files_url', '');
            $username = (string) $this->rc->user->get_username();
            $url      = str_replace('%u', rawurlencode($username), $url_tpl);

            $this->write_log('roundcube', 'rounddav_files: using fallback files URL template=' . $url_tpl . ' for user=' . $username);

            if ($url === '') {
                $url = './?_task=mail';
            }
        }

        $attrib['src']    = $url;
        $attrib['width']  = $attrib['width']  ?? '100%';
        $attrib['height'] = $attrib['height'] ?? '100%';

        return $this->rc->output->frame($attrib);
    }


    /**
     * Main AJAX handler: attach selected RoundDAV files to current compose.
     */
    public function attach_files(): void
    {
        $rcmail = $this->rc;

        $this->write_log(
            'roundcube',
            'rounddav_files attach_files START; REQUEST=' . json_encode($_REQUEST)
        );

        $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST, true);
        if (!$compose_id) {
            $compose_id = rcube_utils::get_input_value('_compose_id', rcube_utils::INPUT_POST, true);
        }

        $files_json = rcube_utils::get_input_value('files', rcube_utils::INPUT_POST, true);
        $files      = @json_decode((string) $files_json, true);

        if (empty($compose_id) || !is_array($files) || !count($files)) {
            $rcmail->output->show_message($this->gettext('attach_error_invalid'), 'error');
            $rcmail->output->send('plugin');
            return;
        }

        $temp_dir = $rcmail->config->get('temp_dir', sys_get_temp_dir());
        if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
            $this->write_log('errors', 'rounddav_files: temp_dir not writable: ' . $temp_dir);
            $rcmail->output->show_message($this->gettext('attach_error_temp'), 'error');
            $rcmail->output->send('plugin');
            return;
        }

        $attached_count = 0;
        $charset        = $rcmail->output->get_charset();

        foreach ($files as $file) {
            $href = isset($file['href']) ? (string) $file['href'] : '';
            $name = isset($file['name']) ? trim((string) $file['name']) : '';

            if ($href === '' || $name === '') {
                continue;
            }

            $mimetype = null;
            $filesize = 0;
            $tmp_path = null;
            $used_fs  = false;

            // 1) Try filesystem access if configured
            $fs_path = $this->resolve_fs_path_from_href($href);
            if ($fs_path !== null) {
                $used_fs = true;
                $this->write_log(
                    'roundcube',
                    'rounddav_files attach_files: using fs_path=' . $fs_path . ' for href=' . $href
                );

                if (!is_file($fs_path) || !is_readable($fs_path)) {
                    $this->write_log(
                        'errors',
                        'rounddav_files: fs_path not readable: ' . $fs_path . ' (falling back to HTTP)'
                    );
                } else {
                    $tmp_path = tempnam($temp_dir, 'rdv_');
                    if ($tmp_path === false) {
                        $this->write_log('errors', 'rounddav_files: tempnam failed in ' . $temp_dir . ' (falling back to HTTP)');
                        $tmp_path = null;
                    } else {
                        if (!copy($fs_path, $tmp_path)) {
                            @unlink($tmp_path);
                            $tmp_path = null;
                            $this->write_log(
                                'errors',
                                'rounddav_files: copy failed from ' . $fs_path . ' to tmp (falling back to HTTP)'
                            );
                        } else {
                            $mimetype = rcube_mime::file_content_type($tmp_path, $name, $charset);
                            $filesize = @filesize($tmp_path) ?: 0;
                        }
                    }
                }
            }

            if ($tmp_path === null) {
                // 2) Fallback to HTTP fetch via RoundDAV UI / rc_attach.php
                $download_url = $this->build_attach_url($href);
                $this->write_log(
                    'roundcube',
                    'rounddav_files attach_files: href=' . $href . ' download_url=' . $download_url
                );

                $content_type = null;
                $data         = $this->fetch_rounddav_file($download_url, $content_type);

                if ($data === null) {
                    $this->write_log('errors', 'rounddav_files: failed to fetch file from ' . $download_url);
                    continue;
                }

                $tmp_path = tempnam($temp_dir, 'rdv_');
                if ($tmp_path === false) {
                    $this->write_log('errors', 'rounddav_files: tempnam failed in ' . $temp_dir);
                    continue;
                }

                if (file_put_contents($tmp_path, $data) === false) {
                    @unlink($tmp_path);
                    $this->write_log('errors', 'rounddav_files: file_put_contents failed for ' . $tmp_path);
                    continue;
                }

                $mimetype = $content_type ?: rcube_mime::file_content_type($tmp_path, $name, $charset);
                $filesize = strlen($data);
            }

            // At this point we have $tmp_path, $name, $mimetype, $filesize.
            if (!$tmp_path || !is_file($tmp_path)) {
                $this->write_log('errors', 'rounddav_files: tmp_path invalid for ' . $name);
                continue;
            }

            // Store attachment directly into compose session
            $attachment_id = $this->store_compose_attachment(
                $compose_id,
                $tmp_path,
                $name,
                $mimetype,
                $filesize
            );

            if (!$attachment_id) {
                $this->write_log(
                    'errors',
                    'rounddav_files: store_compose_attachment() failed for ' . $name
                );
                continue;
            }

            // Build attachment row for Roundcube UI (same structure as core uploader)
            $filesize_str = $rcmail->show_bytes($filesize);

            // Simple HTML for the attachment list entry
            $content = sprintf(
                '<span class="attachment-name">%s</span><span class="attachment-size"> (%s)</span>',
                rcube::Q($name),
                rcube::Q($filesize_str)
            );

            $attachment_row = [
                'html'      => $content,
                'name'      => $name,
                'mimetype'  => $mimetype,
                'classname' => rcube_utils::file2class($mimetype, $name),
                'complete'  => true,
            ];

            // Roundcube convention: DOM id is "rcmfile<ID>"
            $dom_id = 'rcmfile' . $attachment_id;

            $this->write_log(
                'roundcube',
                sprintf(
                    'rounddav_files attach_files: sending add2attachment_list dom_id=%s name=%s size=%d mimetype=%s',
                    $dom_id,
                    $name,
                    $filesize,
                    $mimetype
                )
            );

            // This exactly matches how core + plugins add attachment rows
            $rcmail->output->command('add2attachment_list', $dom_id, $attachment_row);

            $attached_count++;
        }

        if ($attached_count > 0) {
            $rcmail->output->show_message($this->gettext('attach_success'), 'confirmation');
        } else {
            $rcmail->output->show_message($this->gettext('attach_error_none'), 'error');
        }

        $rcmail->output->send('plugin');
    }

    /**
     * Store an attachment into Roundcube's compose_data session for the given compose_id.
     * Returns the new attachment id on success, or false on failure.
     */
    private function store_compose_attachment(
        string $compose_id,
        string $tmp_path,
        string $name,
        string $mimetype,
        int $size
    ) {
        // Roundcube 1.x stores compose data in $_SESSION['compose_data_' . $COMPOSE_ID]
        if (!isset($_SESSION)) {
            @session_start();
        }

        $session_key = 'compose_data_' . $compose_id;

        if (empty($_SESSION[$session_key]) || !is_array($_SESSION[$session_key])) {
            $_SESSION[$session_key] = [];
        }

        if (
            !isset($_SESSION[$session_key]['attachments']) ||
            !is_array($_SESSION[$session_key]['attachments'])
        ) {
            $_SESSION[$session_key]['attachments'] = [];
        }

        // Generate a semi-random id similar to Roundcube's style
        $id = substr(md5(uniqid('rdv', true)), 0, 16);

        $_SESSION[$session_key]['attachments'][$id] = [
            'name'       => $name,
            'mimetype'   => $mimetype,
            'size'       => $size,
            'path'       => $tmp_path,
            'data'       => null,
            'content_id' => null,
            'disposition'=> 'attachment',
            // Roundcube will default charset, but being explicit doesn't hurt
            'charset'    => RCUBE_CHARSET,
            'complete'   => true,
        ];

        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files store_compose_attachment: session_key=%s compose_id=%s id=%s path=%s size=%d mimetype=%s',
                $session_key,
                $compose_id,
                $id,
                $tmp_path,
                $size,
                $mimetype
            )
        );

        return $id;
    }

    private function sanitizeRelPath(string $path): string
    {
        $path = str_replace(["\\", "\0"], '/', $path);
        $parts = [];

        foreach (explode('/', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }

    private function sanitizeFilename(string $file): string
    {
        $file = str_replace(["\0", '/', '\\'], '', (string) $file);
        return trim($file);
    }

    private function resolveExistingPath(string $root, string $rel, string $file): ?string
    {
        $baseReal = realpath($root);
        if ($baseReal === false || !is_dir($baseReal)) {
            return null;
        }

        $rel = $this->sanitizeRelPath($rel);
        $file = $this->sanitizeFilename($file);
        if ($file === '') {
            return null;
        }

        $candidate = rtrim($baseReal, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ($rel === '' ? '' : $rel . DIRECTORY_SEPARATOR)
            . $file;

        $pathReal = realpath($candidate);
        if ($pathReal === false || !is_file($pathReal)) {
            return null;
        }

        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($pathReal, $basePrefix, strlen($basePrefix)) !== 0) {
            return null;
        }

        return $pathReal;
    }

    /**
     * Map an href like "?action=download&area=user&path=Pictures&file=me.jpg"
     * to a filesystem path using rounddav_files_fs_root.
     */
    private function resolve_fs_path_from_href(string $href): ?string
    {
        $fs_tpl_user   = (string) $this->rc->config->get('rounddav_files_fs_root', '');
        $fs_tpl_shared = (string) $this->rc->config->get('rounddav_files_shared_fs_root', '');
        $username      = (string) $this->rc->user->get_username();

        // Strip leading "?" if present and parse query
        $q = ltrim($href, '?');
        parse_str($q, $params);

        $path = isset($params['path']) ? (string) $params['path'] : '';
        $file = isset($params['file']) ? (string) $params['file'] : '';
        $area = isset($params['area']) ? (string) $params['area'] : 'user';

        if ($file === '') {
            $this->write_log(
                'roundcube',
                'rounddav_files resolve_fs_path_from_href: missing file param in href=' . $href
            );
            return null;
        }

        $fs_tpl = $fs_tpl_user;
        if ($area === 'shared') {
            if ($fs_tpl_shared !== '') {
                $fs_tpl = $fs_tpl_shared;
            } else {
                $this->write_log(
                    'roundcube',
                    'rounddav_files resolve_fs_path_from_href: shared area requested but rounddav_files_shared_fs_root not set'
                );
                $fs_tpl = '';
            }
        }

        if ($fs_tpl === '') {
            return null;
        }

        $root = str_replace('%u', $username, $fs_tpl);

        $full = $this->resolveExistingPath($root, $path, $file);
        if ($full === null) {
            $this->write_log(
                'roundcube',
                sprintf(
                    'rounddav_files resolve_fs_path_from_href: rejected path for href=%s area=%s root=%s',
                    $href,
                    $area,
                    $root
                )
            );
            return null;
        }

        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files resolve_fs_path_from_href: href=%s area=%s root=%s full=%s',
                $href,
                $area,
                $root,
                $full
            )
        );

        return $full;
    }

    private function normalize_url_origin(string $url): ?string
    {
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === null) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        return $scheme . '://' . $host . ':' . $port;
    }

    private function is_allowed_fetch_url(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $origin = $scheme . '://' . $host . ':' . $port;
        $allowedOrigins = [];

        $filesTpl = (string) $this->rc->config->get('rounddav_files_url', '');
        $attachTpl = (string) $this->rc->config->get('rounddav_attach_files_url', '');

        foreach ([$filesTpl, $attachTpl] as $tpl) {
            if ($tpl === '') {
                continue;
            }

            $tplOrigin = $this->normalize_url_origin($tpl);
            if ($tplOrigin !== null) {
                $allowedOrigins[$tplOrigin] = true;
            }
        }

        return isset($allowedOrigins[$origin]);
    }

    private function build_attach_url(string $href): string
    {
        $username   = (string) $this->rc->user->get_username();
        $files_tpl  = (string) $this->rc->config->get('rounddav_files_url', '');
        $attach_tpl = (string) $this->rc->config->get('rounddav_attach_files_url', '');

        if (preg_match('#^https?://#i', $href)) {
            if ($this->is_allowed_fetch_url($href)) {
                $this->write_log('roundcube', 'rounddav_files build_attach_url: accepted absolute href=' . $href);
                return $href;
            }

            $this->write_log('errors', 'rounddav_files build_attach_url: rejected absolute href=' . $href);
            return '';
        }

        $base = '';

        if ($attach_tpl !== '') {
            $base = str_replace('%u', rawurlencode($username), $attach_tpl);
            if (strpos($base, '%f') !== false) {
                $url = str_replace('%f', rawurlencode($href), $base);
                $this->write_log(
                    'roundcube',
                    sprintf(
                        'rounddav_files build_attach_url (attach_tpl+%%f): href=%s base=%s url=%s',
                        $href,
                        $base,
                        $url
                    )
                );
                return $url;
            }
        }

        if ($base === '' && $files_tpl !== '') {
            $base = str_replace('%u', rawurlencode($username), $files_tpl);
        }

        if ($base === '') {
            $this->write_log(
                'roundcube',
                'rounddav_files build_attach_url: NO BASE URL, returning raw href=' . $href
            );
            return $href;
        }

        if ($href !== '' && $href[0] === '?') {
            if (strpos($base, '?') !== false) {
                $url = $base . '&' . ltrim($href, '?');
            } else {
                $url = $base . $href;
            }
        } else {
            $sep = (strpos($base, '?') === false) ? '?' : '&';
            $url = $base . $sep . ltrim($href, '&?');
        }

        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files build_attach_url: href=%s base=%s url=%s',
                $href,
                $base,
                $url
            )
        );

        return $url;
    }

    private function fetch_rounddav_file(string $url, ?string &$content_type_out = null): ?string
    {
        $content_type_out = null;

        if ($url === '' || !$this->is_allowed_fetch_url($url)) {
            $this->write_log('errors', 'rounddav_files: rejected fetch URL=' . $url);
            return null;
        }

        if (!function_exists('curl_init')) {
            $this->write_log('errors', 'rounddav_files: cURL extension missing');
            return null;
        }

        $ch = curl_init($url);
        if (!$ch) {
            $this->write_log('errors', 'rounddav_files: curl_init failed for ' . $url);
            return null;
        }

        $verifySsl = (bool) $this->rc->config->get('rounddav_files_verify_ssl', true);
        $forwardSessionCookie = (bool) $this->rc->config->get('rounddav_files_forward_session_cookie', false);
        $cookie = session_name() . '=' . session_id();

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HEADER         => true,
        ]);
        if ($forwardSessionCookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $this->write_log(
                'errors',
                'rounddav_files: curl_exec failed for ' . $url . ' error=' . curl_error($ch)
            );
            curl_close($ch);
            return null;
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header      = substr($response, 0, $header_size);
        $body        = substr($response, $header_size);

        $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $len         = strlen($body);

        $content_type = null;
        if (preg_match('/^Content-Type:\s*([^\r\n]+)/im', $header, $m)) {
            $content_type = trim($m[1]);
            $content_type_out = $content_type;
        }

        $this->write_log(
            'roundcube',
            sprintf(
                'rounddav_files fetch_rounddav_file: url=%s code=%d content_type=%s len=%d',
                $url,
                $http_code,
                (string) $content_type,
                $len
            )
        );

        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300) {
            return null;
        }

        return $body;
    }

    public function settings_actions(array $args): array
    {
        return $args;
    }

    public function logout_after(array $args): array
    {
        return $args;
    }
}
