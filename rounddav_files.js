// rounddav_files.js
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        rcmail.register_command('rounddav_files', function () {
            rcmail.switch_task('rounddav_files');
        }, true);
    });
}
