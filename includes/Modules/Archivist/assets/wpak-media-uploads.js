(function ($, wp) {
    'use strict';

    if (!wp || !wp.media || !wp.Uploader) {
        return;
    }

    var settings = window.wpakArchivistUploads || {};
    var POLL_INTERVAL = 3000;
    var MAX_ATTEMPTS = 40; // ~2 minutes of polling per upload.
    var FIELDS = ['alt', 'title', 'caption', 'description'];

    // Track open Attachment.Details views by model id so a running poll or a
    // manual generation can refresh whatever the user currently has on screen.
    var openViews = {};

    injectStyles();
    patchDetailViews();

    // ---- Upload hook ---------------------------------------------------------

    if (settings.autoWatch === '1') {
        var originalSuccess = wp.Uploader.prototype.success;
        wp.Uploader.prototype.success = function (attachment) {
            if (typeof originalSuccess === 'function') {
                originalSuccess.apply(this, arguments);
            }
            try {
                watch(attachment);
            } catch (error) {
                // Never let the watcher break a normal upload.
            }
        };
    }

    function watch(attachment) {
        if (!attachment || !attachment.id) {
            return;
        }
        var type = attachment.get ? attachment.get('type') : attachment.type;
        if (type && type !== 'image') {
            return;
        }
        attachment.set('wpakGenerating', true);
        redecorate(attachment.id);
        setTimeout(function () {
            poll(attachment, 0);
        }, 1500);
    }

    function poll(attachment, attempt) {
        if (!attachment || !attachment.id) {
            return;
        }
        if (attempt > MAX_ATTEMPTS) {
            attachment.set('wpakGenerating', false);
            redecorate(attachment.id);
            return;
        }

        $.post(settings.ajaxUrl, {
            action: 'wpaikits99_archivist_attachment_status',
            nonce: settings.nonce,
            ids: [attachment.id],
        }).done(function (response) {
            var items = response && response.success && response.data ? response.data.items : null;
            var data = items ? items[attachment.id] : null;

            if (data && data.processed) {
                applyMetadata(attachment, data, false);
                return;
            }
            setTimeout(function () { poll(attachment, attempt + 1); }, POLL_INTERVAL);
        }).fail(function () {
            setTimeout(function () { poll(attachment, attempt + 1); }, POLL_INTERVAL);
        });
    }

    // ---- Manual per-image generation ------------------------------------------

    function generateNow(attachment) {
        if (!attachment || !attachment.id || attachment.get('wpakGenerating')) {
            return;
        }
        attachment.set('wpakGenerating', true);
        attachment.set('wpakError', '');
        redecorate(attachment.id);

        $.post(settings.ajaxUrl, {
            action: 'wpaikits99_archivist_generate_single',
            nonce: settings.nonce,
            id: attachment.id,
        }).done(function (response) {
            if (response && response.success && response.data) {
                applyMetadata(attachment, response.data, true);
                return;
            }
            failGeneration(attachment, String((response && response.data) || 'Generation failed.'));
        }).fail(function (xhr) {
            var message = xhr && xhr.responseJSON && xhr.responseJSON.data
                ? String(xhr.responseJSON.data)
                : 'Generation failed. Check AI Settings and try again.';
            failGeneration(attachment, message);
        });
    }

    function failGeneration(attachment, message) {
        attachment.set('wpakGenerating', false);
        attachment.set('wpakError', message);
        redecorate(attachment.id);
    }

    function applyMetadata(attachment, data, force) {
        var patch = {};
        FIELDS.forEach(function (field) {
            if (data[field]) {
                patch[field] = data[field];
            }
        });
        attachment.set(patch);
        attachment.set('wpakGenerating', false);
        attachment.set('wpakError', '');
        attachment.set('wpakJustDone', true);
        attachment.set('wpakWrote', force ? data.wrote !== false : true);
        attachment.set('wpakForce', !!force);
        redecorate(attachment.id);
    }

    // ---- Details view integration -------------------------------------------

    function patchDetailViews() {
        [wp.media.view.Attachment.Details, wp.media.view.Attachment.Details.TwoColumn].forEach(function (View) {
            if (!View || View.prototype.__wpakPatched) {
                return;
            }
            var origRender = View.prototype.render;
            var origRemove = View.prototype.remove;

            View.prototype.render = function () {
                origRender.apply(this, arguments);
                var view = this;
                var id = view.model && view.model.id;
                if (id) {
                    (openViews[id] = openViews[id] || []);
                    if (openViews[id].indexOf(view) === -1) {
                        openViews[id].push(view);
                    }
                    try { decorate(view); } catch (e) {}
                }
                return this;
            };

            View.prototype.remove = function () {
                var view = this;
                var id = view.model && view.model.id;
                if (id && openViews[id]) {
                    openViews[id] = openViews[id].filter(function (v) { return v !== view; });
                }
                return origRemove.apply(this, arguments);
            };

            View.prototype.__wpakPatched = true;
        });
    }

    function redecorate(id) {
        (openViews[id] || []).forEach(function (view) {
            try { decorate(view); } catch (e) {}
        });
    }

    function decorate(view) {
        var model = view.model;
        if (!model) {
            return;
        }
        var type = model.get('type');
        if (type && type !== 'image') {
            insertStatus(view, null);
            return;
        }

        if (model.get('wpakGenerating')) {
            insertStatus(view, $('<div class="wpak-gen-status wpak-gen-active"><span class="wpak-gen-spinner"></span> Generating AI metadata…</div>'));
            return;
        }

        if (model.get('wpakJustDone')) {
            var force = !!model.get('wpakForce');
            FIELDS.forEach(function (field) { fillField(view, field, model.get(field), force); });
            var wrote = model.get('wpakWrote') !== false;
            var $done = $('<div class="wpak-gen-status wpak-gen-done"></div>').text(
                wrote ? '✓ AI metadata added' : '✓ Existing metadata kept — nothing needed writing'
            );
            insertStatus(view, $done);
            setTimeout(function () {
                $done.fadeOut(400, function () { $(this).remove(); });
                model.set('wpakJustDone', false, { silent: true });
                redecorate(model.id);
            }, 4000);
            return;
        }

        var $wrap = $('<div class="wpak-gen-status wpak-gen-idle"></div>');
        var $btn = $('<button type="button" class="button wpak-gen-btn">Generate with AI</button>');
        $btn.on('click', function () { generateNow(model); });
        $wrap.append($btn);
        var error = model.get('wpakError');
        if (error) {
            $wrap.append($('<span class="wpak-gen-error"></span>').text(error));
        }
        insertStatus(view, $wrap);
    }

    function insertStatus(view, $node) {
        view.$el.find('.wpak-gen-status').remove();
        if (!$node) {
            return;
        }
        var $firstSetting = view.$el.find('.setting[data-setting]').first();
        if ($firstSetting.length) {
            $firstSetting.before($node);
        } else {
            view.$el.find('.attachment-info, .attachment-details').first().prepend($node);
        }
    }

    // Fill a field with the saved value. Empty fields are always filled; when
    // the user explicitly generated (force), the saved server value wins.
    function fillField(view, setting, value, force) {
        if (!value) {
            return;
        }
        var $field = view.$el.find('.setting[data-setting="' + setting + '"]').find('input, textarea').first();
        if ($field.length && (force || !$field.val())) {
            if ($field.val() !== value) {
                $field.val(value).trigger('change');
            }
        }
    }

    function injectStyles() {
        if (document.getElementById('wpak-gen-styles')) {
            return;
        }
        var css = ''
            + '.wpak-gen-status{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:0 0 12px;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:600;line-height:1.4}'
            + '.wpak-gen-active{background:#EEF2FF;color:#3730A3;border:1px solid #E0E7FF}'
            + '.wpak-gen-done{background:#EDF3EC;color:#346538;border:1px solid #DDE9DA}'
            + '.wpak-gen-idle{background:transparent;border:0;padding:0}'
            + '.wpak-gen-error{color:#9F2F2D;font-weight:600}'
            + '.wpak-gen-spinner{flex:none;width:12px;height:12px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;display:inline-block;animation:wpak-gen-spin .8s linear infinite}'
            + '@keyframes wpak-gen-spin{to{transform:rotate(360deg)}}';
        var style = document.createElement('style');
        style.id = 'wpak-gen-styles';
        style.textContent = css;
        document.head.appendChild(style);
    }
})(jQuery, window.wp);
