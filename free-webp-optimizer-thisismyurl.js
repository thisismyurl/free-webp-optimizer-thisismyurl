jQuery(document).ready(function($) {
    // Quality Slider Feedback
    $('#webp-quality').on('input', function() {
        $('#q-val').text($(this).val());
    });

    // Bulk Optimization Logic
    $('#btn-start').click(function() {
        const ids = fwoVars.pendingIds;
        const q = $('#webp-quality').val();
        if (!ids.length) return;

        $(this).attr('disabled', true).text('Processing...');

        function process(i) {
            if (i >= ids.length) {
                location.reload();
                return;
            }
            $.post(fwoVars.ajaxurl, {
                action: 'webp_bulk_optimize',
                attachment_id: ids[i],
                quality: q,
                _ajax_nonce: fwoVars.nonce
            }).done(function() {
                process(i + 1);
            }).fail(function() {
                process(i + 1);
            });
        }
        process(0);
    });

    // Bulk Restore with Progress Bar
    $('#btn-rollback-all').click(function() {
        const idsToRestore = [];
        $('.restore-btn').each(function() {
            idsToRestore.push($(this).data('id'));
        });

        if (!idsToRestore.length) return;

        if (confirm(fwoVars.msgConfirm)) {
            $(this).attr('disabled', true);
            $('#fwo-progress-container').show();
            let total = idsToRestore.length;
            let current = 0;

            function restoreNext() {
                if (current >= total) {
                    location.reload();
                    return;
                }
                const id = idsToRestore[current];
                $('#fwo-status-message').text('Restoring #' + id + ' (' + (current + 1) + '/' + total + ')');

                $.post(fwoVars.ajaxurl, {
                    action: 'webp_restore',
                    attachment_id: id,
                    _ajax_nonce: fwoVars.nonce
                }).always(function() {
                    current++;
                    let percent = Math.round((current / total) * 100);
                    $('#fwo-progress-bar').css('width', percent + '%');
                    $('#fwo-progress-text').text(percent + '%');
                    restoreNext();
                });
            }
            restoreNext();
        }
    });
});