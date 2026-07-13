(function ($, wp) {
    'use strict';

    const ArchivistMediaSearch = {
        injectTimer: null,
        observer: null,

        init() {
            this.patchMediaModal();
            $(document).ready(() => {
                this.injectSoon(100);
                this.observeMediaToolbar();
            });
            $(document).on('click', '.view-switch a,.media-menu-item,.media-router a', () => this.injectSoon(180));
        },

        patchMediaModal() {
            if (!wp?.media?.view?.Modal || wp.media.view.Modal.prototype.wpakArchivistPatched) return;

            const originalOpen = wp.media.view.Modal.prototype.open;
            wp.media.view.Modal.prototype.open = function () {
                originalOpen.apply(this, arguments);
                setTimeout(() => ArchivistMediaSearch.inject(), 120);
            };
            wp.media.view.Modal.prototype.wpakArchivistPatched = true;
        },

        observeMediaToolbar() {
            if (this.observer || !window.MutationObserver || !document.body) return;

            this.observer = new MutationObserver(() => this.injectSoon(80));
            this.observer.observe(document.body, { childList: true, subtree: true });
        },

        injectSoon(delay) {
            clearTimeout(this.injectTimer);
            this.injectTimer = setTimeout(() => this.inject(), delay);
        },

        inject() {
            $('.media-toolbar-secondary .media-search-input, #media-search-input').each((index, input) => {
                this.mountSearch($(input));
            });
            this.injectStyles();
        },

        mountSearch($search) {
            if ($search.closest('.wpak-ai-search-field').length) return;

            $search.wrap('<span class="wpak-ai-search-field"></span>');
            const $button = $(this.buttonMarkup()).appendTo($search.parent());
            $search.closest('.search-box').find('#search-submit').hide();

            $button.on('click.wpakArchivist', () => this.runAISearch($search, $button));
            $search.on('input.wpakArchivist', () => this.handleNativeInput($search, $button));
            $search.on('keydown.wpakArchivist', (event) => {
                if (event.key === 'Enter' || event.keyCode === 13) {
                    this.handleNativeInput($search, $button);
                }
            });
        },

        buttonMarkup() {
            return `
                <button type="button" class="wpak-ai-search-submit" title="AI media search" aria-label="AI media search">
                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                        <circle cx="8.5" cy="8.5" r="4.2"></circle>
                        <path d="M12 12l4 4M14.5 3.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"></path>
                    </svg>
                </button>
            `;
        },

        async runAISearch($search, $button) {
            const query = String($search.val() || '').trim();
            if (!query) {
                $search.focus();
                return;
            }

            const browser = this.getMediaBrowser();
            if (!browser?.collection) {
                this.submitListTableSearch($search, query);
                return;
            }

            this.setLoading($button, true);
            try {
                const ids = await this.fetchResultIds(query);
                this.applyGridResults(browser.collection, ids);
                this.setButtonState($button, 'active', ids.length);
            } catch (error) {
                this.forceNativeCollection(browser.collection, query);
                this.setButtonState($button, 'error', error.message);
            } finally {
                this.setLoading($button, false);
            }
        },

        async fetchResultIds(query) {
            const settings = window.wpakArchivistMediaSearch || {};
            const body = new URLSearchParams({
                action: 'wpaikits99_archivist_media_search',
                nonce: settings.nonce || '',
                query,
            });

            const response = await fetch(settings.ajaxUrl || window.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.data || 'AI media search failed.');
            }

            return (payload.data.ids || []).map((id) => parseInt(id, 10)).filter(Boolean);
        },

        applyGridResults(collection, ids) {
            this.clearAICollectionProps(collection);
            collection.props.set({
                search: '',
                post__in: ids.length ? ids : [0],
                order: 'ASC',
                orderby: 'post__in',
                wpaikits99_ai_applied: Date.now(),
            });
        },

        handleNativeInput($search, $button) {
            this.setButtonState($button, 'idle');
            this.prepareNativeForm($search.closest('form'));

            const browser = this.getMediaBrowser();
            if (!browser?.collection) return;

            this.clearAICollectionProps(browser.collection);
            if (!String($search.val() || '').trim()) {
                this.forceNativeCollection(browser.collection, '');
            }
        },

        clearAICollectionProps(collection) {
            const props = collection.props;
            ['post__in', 'wpaikits99_ai_search', 'wpaikits99_ai_query', 'wpaikits99_ai_applied'].forEach((key) => {
                props.unset(key, { silent: true });
            });
            if (props.get('orderby') === 'post__in') {
                props.set({ order: 'DESC', orderby: 'date' }, { silent: true });
            }
        },

        forceNativeCollection(collection, search) {
            this.clearAICollectionProps(collection);
            collection.props.set({
                search,
                wpaikits99_native_reset: Date.now(),
            });
        },

        submitListTableSearch($search, query) {
            const $form = $search.closest('form');
            if (!$form.length) return;

            this.prepareNativeForm($form);
            this.ensureHiddenField($form, 'wpaikits99_ai_search', '1');
            this.ensureHiddenField($form, 'wpaikits99_ai_query', query);
            $form.get(0).submit();
        },

        prepareNativeForm($form) {
            if (!$form?.length) return;

            $form.find('input[name="wpaikits99_ai_search"],input[name="wpaikits99_ai_query"]').remove();
            const action = $form.attr('action') || window.location.href;
            try {
                const url = new URL(action, window.location.origin);
                url.searchParams.delete('wpaikits99_ai_search');
                url.searchParams.delete('wpaikits99_ai_query');
                $form.attr('action', url.pathname + url.search);
                this.cleanUrl();
            } catch (error) {
                this.cleanUrl();
            }
        },

        cleanUrl() {
            const url = new URL(window.location.href);
            url.searchParams.delete('wpaikits99_ai_search');
            url.searchParams.delete('wpaikits99_ai_query');
            window.history.replaceState({}, document.title, url.toString());
        },

        ensureHiddenField($form, name, value) {
            let $field = $form.find(`input[name="${name}"]`);
            if (!$field.length) {
                $field = $(`<input type="hidden" name="${name}">`).appendTo($form);
            }
            $field.val(value);
        },

        getMediaBrowser() {
            if (!wp?.media?.frame?.content?.get) return null;
            return wp.media.frame.content.get();
        },

        setLoading($button, isLoading) {
            $button.toggleClass('is-loading', isLoading).prop('disabled', isLoading);
        },

        setButtonState($button, state, detail = '') {
            $button.removeClass('is-active has-error');
            if (state === 'active') {
                $button.addClass('is-active').attr('title', `AI media results applied (${detail})`);
                return;
            }
            if (state === 'error') {
                $button.addClass('has-error').attr('title', detail || 'AI media search failed');
                return;
            }
            $button.attr('title', 'AI media search');
        },

        injectStyles() {
            if ($('#wpak-media-modal-styles').length) return;

            $('head').append(`
                <style id="wpak-media-modal-styles">
                    .wpak-ai-search-field{display:inline-block;max-width:100%;position:relative;vertical-align:middle}
                    .wpak-ai-search-field>#media-search-input,.wpak-ai-search-field>.media-search-input{padding-right:32px!important}
                    .wpak-ai-search-submit{align-items:center;background:transparent;border:none;border-radius:7px;box-shadow:0 1px 2px rgba(17,17,17,.12);color:#000;cursor:pointer;display:inline-flex;height:26px;justify-content:center;padding:0;position:absolute;right:4px;top:50%;transform:translateY(-50%);transition:background .16s ease,border-color .16s ease,box-shadow .16s ease,opacity .16s ease;width:26px;z-index:2}
                    .wpak-ai-search-submit svg{fill:none;height:15px;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.75;width:15px}
                    .wpak-ai-search-submit:hover,.wpak-ai-search-submit.is-active{background:transparent;color:#111}
                    .wpak-ai-search-submit.has-error{background:#9f2f2d;border-color:#9f2f2d;color:#fff}
                    .wpak-ai-search-submit:disabled{cursor:wait;opacity:.76}
                    .wpak-ai-search-submit.is-loading svg{opacity:0}
                    .wpak-ai-search-submit.is-loading:after{animation:wpak-ai-spin .75s linear infinite;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;content:"";height:12px;position:absolute;width:12px}
                    @keyframes wpak-ai-spin{to{transform:rotate(360deg)}}
                </style>
            `);
        },
    };

    ArchivistMediaSearch.init();
})(jQuery, window.wp);
