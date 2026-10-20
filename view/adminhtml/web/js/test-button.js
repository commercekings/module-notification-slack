/**
 * The "Send test message" button: posts to the admin controller and shows its answer next to the button.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var button = $(element),
            result = $('#' + config.resultId);

        function show(text, success) {
            result.text(text).css('margin-left', '1rem').css('color', success ? '#185b00' : '#e22626');
        }

        button.on('click', function () {
            button.prop('disabled', true);
            result.text(config.sending).css('color', '');
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY, website: config.website}
            }).done(function (response) {
                show(response && response.message ? response.message : config.failed, !!(response && response.success));
            }).fail(function () {
                show(config.failed, false);
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    };
});
