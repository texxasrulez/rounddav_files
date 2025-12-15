// rounddav_files_compose.js
//
// Compose-window integration for RoundDAV Files:
//  - Adds an "Attach from RoundDAV" button into the compose attachments area.
//  - Wires both that button and the toolbar cloud icon to a dialog
//    that loads the RoundDAV Files UI.
//  - Reads selected file rows inside that iframe and
//    posts them to the plugin's PHP action to become real attachments.
//
// Relies on:
//   - rcmail.env.rounddav_files_url (set in PHP)
//   - rcmail.env.rounddav_attach_files_url (optional override)
//   - RoundDAV Files UI marking selected rows with some combination of
//     .file-row.selected / .selected / data-selected / checked checkboxes.

(function() {
    if (!window.rcmail) {
        return;
    }

    rcmail.addEventListener('init', function() {
        // Only in mail compose
        if (rcmail.env.task !== 'mail' || rcmail.env.action !== 'compose') {
            return;
        }

        // Sanity: we need the RoundDAV Files base URL
        if (!rcmail.env.rounddav_files_url && !rcmail.env.rounddav_attach_files_url) {
            try {
                rcmail.display_message('RoundDAV Files URL not configured', 'error');
            } catch (e) {}
            return;
        }

        console.log(
            '[rounddav_files] init on compose; env.rounddav_files_url =',
            rcmail.env.rounddav_files_url,
            'attach_url =',
            rcmail.env.rounddav_attach_files_url
        );

        // Inject the inline "Attach from RoundDAV" button into the attachments area
        // once the DOM is ready.
        setTimeout(inject_inline_attach_button, 0);

        // Register command used by both inline button and toolbar icon
        rcmail.register_command(
            'plugin.rounddav_files_open',
            function() {
                open_rounddav_picker_dialog();
            },
            true
        );
    });

    /**
     * Inserts a new <a> button into the compose attachments container.
     */
    function inject_inline_attach_button() {
        var container = document.getElementById('compose-attachments');

        console.log('[rounddav_files] inject_inline_attach_button: container =', container);

        if (!container) {
            try {
                rcmail.display_message('RoundDAV: compose attachments container not found', 'error');
            } catch (e) {}
            return;
        }

        // Avoid duplicating if already present
        if (document.getElementById('rcmbtn_rounddav_attach_inline')) {
            return;
        }

        var label;
        try {
            label = rcmail.gettext('attach_from_dav', 'rounddav_files');
        } catch (e) {
            label = 'Attach from RoundDAV';
        }

        var davBtn = document.createElement('a');
        davBtn.href = '#';
        davBtn.id = 'rcmbtn_rounddav_attach_inline';
        davBtn.className = 'button button-rounddav-attach-inline';
        davBtn.textContent = label;

        // Preferred: attach right next to the stock "Attach" button, if we can find it.
        var attachBtn =
            container.querySelector('[data-command="add-attachment"]') ||
            container.querySelector('.button-attach') ||
            document.querySelector('#compose-attachments .button-attach') ||
            document.querySelector('a.button-attach');

        if (attachBtn && attachBtn.parentNode) {
            // Insert directly after the stock attach button
            if (attachBtn.nextSibling) {
                attachBtn.parentNode.insertBefore(davBtn, attachBtn.nextSibling);
            } else {
                attachBtn.parentNode.appendChild(davBtn);
            }
        } else {
            // Fallback: simple wrapper centered at the top of attachments area
            var wrapper = document.createElement('div');
            wrapper.className = 'rounddav-inline-wrapper';
            wrapper.appendChild(davBtn);

            if (container.firstChild) {
                container.insertBefore(wrapper, container.firstChild);
            } else {
                container.appendChild(wrapper);
            }
        }

        console.log('[rounddav_files] inline RoundDAV button wired up', davBtn);

        davBtn.addEventListener('click', function(e) {
            e.preventDefault();
            rcmail.command('plugin.rounddav_files_open', '', this, e);
        });

        try {
            rcmail.display_message('RoundDAV compose integration loaded', 'notice');
        } catch (e) {}
}

    /**
     * Opens a jQuery UI dialog with a full RoundDAV Files UI embedded in an iframe.
     */
    function open_rounddav_picker_dialog() {
        var url = rcmail.env.rounddav_attach_files_url || rcmail.env.rounddav_files_url;
        if (!url) {
            try {
                rcmail.display_message('RoundDAV Files URL not configured', 'error');
            } catch (e) {}
            return;
        }

        // Ensure jQuery UI dialog is available; if not, fallback to new window
        if (!(window.$ && $.fn && $.fn.dialog)) {
            window.open(url, '_blank');
            return;
        }

        // Clean up any previous dialog
        var existing = document.getElementById('rounddav-files-picker-dialog');
        if (existing && existing.parentNode) {
            $(existing).dialog('destroy').remove();
        }

        var title;
        try {
            title = rcmail.gettext('files', 'rounddav_files') || 'RoundDAV Files';
        } catch (e) {
            title = 'RoundDAV Files';
        }

        var $dlg = $('<div>')
            .attr('id', 'rounddav-files-picker-dialog')
            .addClass('rounddav-files-picker-dialog');

        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.id = 'rounddav-files-picker-iframe';
        iframe.name = 'rounddav-files-picker-iframe';
        iframe.setAttribute('frameborder', '0');
        iframe.style.width = '100%';
        iframe.style.height = '100%';

        $dlg.append(iframe);

        // Wide, resizable dialog
        $dlg.dialog({
            modal: true,
            width: 650,
            height: 450,
            resizable: true,
            closeOnEscape: true,
            title: title,
            close: function() {
                $dlg.dialog('destroy').remove();
            },
            buttons: [
                {
                    text: rcmail.gettext('attach') || 'Attach',
                    click: function() {
                        attach_selected_files_from_dialog($dlg);
                    }
                },
                {
                    text: rcmail.gettext('cancel') || 'Cancel',
                    click: function() {
                        $dlg.dialog('close');
                    }
                }
            ]
        });
    }

    /**
     * Reads selected file rows from the dialog's iframe and sends them
     * to the plugin's PHP side to be added as Roundcube attachments.
     */
    function attach_selected_files_from_dialog($dlg) {
        var iframe = document.getElementById('rounddav-files-picker-iframe');
        if (!iframe || !iframe.contentWindow || !iframe.contentWindow.document) {
            try {
                rcmail.display_message('RoundDAV: file list not loaded yet', 'error');
            } catch (e) {}
            return;
        }

        var doc = iframe.contentWindow.document;

        // Be tolerant: support multiple selection styles
        var rows = doc.querySelectorAll(
            'tr.file-row.selected, tr.selected, tr[data-selected="1"]'
        );

        // As a fallback: checked checkboxes
        if (!rows.length) {
            var checkboxRows = [];
            var checkboxes = doc.querySelectorAll('input[type="checkbox"]:checked');
            for (var i = 0; i < checkboxes.length; i++) {
                var tr = checkboxes[i].closest('tr');
                if (tr && checkboxRows.indexOf(tr) === -1) {
                    checkboxRows.push(tr);
                }
            }
            if (checkboxRows.length) {
                rows = checkboxRows;
            }
        }

        if (!rows.length) {
            try {
                rcmail.display_message('Select a file in RoundDAV first', 'warning');
            } catch (e) {}
            return;
        }

        var files = [];
        for (var j = 0; j < rows.length; j++) {
            var row = rows[j];

            // Try several ways to find the file link
            var link = row.querySelector(
                'a.file-download-link, a.file-link, a[href]:not([href="#"])'
            );

            var href = '';
            var name = '';

            if (link) {
                href =
                    link.getAttribute('href') ||
                    link.getAttribute('data-href') ||
                    '';
                name =
                    link.textContent ||
                    link.getAttribute('data-filename') ||
                    href.split('/').pop();
            }

            // Fallbacks from row attributes
            if (!href) {
                href =
                    row.getAttribute('data-path') ||
                    row.getAttribute('data-href') ||
                    '';
            }
            if (!name) {
                name = row.getAttribute('data-name') || name;
            }

            if (!href || !name) {
                continue;
            }

            files.push({
                href: href,
                name: name
            });
        }

        if (!files.length) {
            try {
                rcmail.display_message('RoundDAV: no usable files selected', 'error');
            } catch (e) {}
            return;
        }

        // Fire the plugin action on PHP side to actually attach these
        rcmail.http_post(
            'plugin.rounddav_files_attach_files',
            {
                _id: rcmail.env.compose_id,
                files: JSON.stringify(files)
            },
            true
        );

        // Close dialog after firing
        if ($dlg && $dlg.dialog) {
            $dlg.dialog('close');
        }
    }

    function escape_html(str) {
        if (str == null) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
})();
