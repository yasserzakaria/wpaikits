(function ($) {
    'use strict';

    $(document).ready(function () {
        const $testBtn = $('#wpak-test-key-btn');
        const $apiKeyInput = $('#wpaikits99_gemini_api_key');
        const $statusDiv = $('#wpak-api-status');
        const $routes = $('.wpak-admin-route');
        const $routeLinks = $('.wpak-admin-nav a[data-route]');

        const isPro = window.wpakSettings && window.wpakSettings.isPro === true;
        const routeAliases = { api: 'profiles', settings: 'hub', skills: 'architect', mentions: 'architect', fleet: 'context', wand: 'magic-wand' };
        const routeCopy = {
            hub: ['WP AI Kits', 'Small, focused AI kits that fix real WordPress problems.'],
            profiles: ['AI Settings', 'Connect the AI providers Media AI Kit uses. Add more than one so a busy provider never stops the job.'],
            context: ['AI Context', 'Global AI training, brand voice, and the block fleet.'],
            architect: ['Editor AI Kit', 'AI Block Builder, Skills, and @ Mention controls for page creation.'],
            'magic-wand': ['Magic Wand Kit', 'Focused inline AI editing for selected Gutenberg blocks.'],
            archivist: ['Media AI Kit', isPro
                ? 'Semantic search, auto-generated media metadata, and Pinecone indexing.'
                : 'Generate useful alt text, clean titles, and searchable descriptions for your WordPress Media Library.'],
        };

        const syncRoute = function () {
            const rawRoute = (window.location.hash || '#hub').replace('#', '') || 'hub';
            const route = routeAliases[rawRoute] || rawRoute;
            const routeMatches = function () { return $(this).data('route') === route; };
            const safeRoute = $routes.filter(routeMatches).length ? route : 'hub';
            const safeMatches = function () { return $(this).data('route') === safeRoute; };
            $routes.removeClass('is-active').filter(safeMatches).addClass('is-active');
            const $matches = $routeLinks.filter(safeMatches);
            $routeLinks.removeClass('is-active');
            ($matches.filter('.is-default').first().length ? $matches.filter('.is-default').first() : $matches.first()).addClass('is-active');
            $('.wpak-admin-topbar h1').text(routeCopy[safeRoute][0]);
            $('.wpak-admin-topbar p').last().text(routeCopy[safeRoute][1]);
        };

        $(window).on('hashchange', syncRoute);
        syncRoute();

        $apiKeyInput.on('input', function () {
            $testBtn.prop('disabled', !$(this).val().trim());
        });

        const showKeyStatus = function (success, message) {
            $statusDiv.show()
                .addClass(success ? 'wpak-status-success' : 'wpak-status-error')
                .empty()
                .append($('<i>', { class: 'ph ' + (success ? 'ph-check-circle' : 'ph-warning-circle'), 'aria-hidden': 'true' }))
                .append(document.createTextNode(' ' + message));
        };

        $testBtn.on('click', function () {
            const apiKey = $apiKeyInput.val().trim();
            if (!apiKey) return;

            $testBtn.find('.wpak-btn-text').hide();
            $testBtn.find('.wpak-btn-spinner').show();
            $testBtn.prop('disabled', true);
            $statusDiv.hide().removeClass('wpak-status-success wpak-status-error');

            $.ajax({
                url: wpakSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpaikits99_test_gemini_key',
                    nonce: wpakSettings.nonce,
                    api_key: apiKey,
                },
                success: function (response) {
                    showKeyStatus(response.success, String(response.data || ''));
                },
                error: function () {
                    showKeyStatus(false, 'Network error. Check console.');
                },
                complete: function () {
                    $testBtn.find('.wpak-btn-text').show();
                    $testBtn.find('.wpak-btn-spinner').hide();
                    $testBtn.prop('disabled', false);
                },
            });
        });
    });
})(jQuery);
