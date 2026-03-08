/**
 * Leads — CP JavaScript
 * Handles live preview and chart rendering in the control panel.
 */
(function () {
    'use strict';

    // Live preview for popup edit page
    function initLivePreview() {
        var templateSelect = document.getElementById('templateKey');
        var popupTypeSelect = document.getElementById('popupType');

        if (!templateSelect || !popupTypeSelect) return;

        // Auto-update template when popup type changes
        popupTypeSelect.addEventListener('change', function () {
            var type = this.value;
            var currentTemplate = templateSelect.value;

            // Suggest matching template
            if (currentTemplate.indexOf('modal') !== -1 && type !== 'modal') {
                var prefix = currentTemplate.split('-')[0];
                var newTemplate = prefix + '-' + (type === 'slidein' ? 'slidein' : (type === 'bar' ? 'bar' : 'inline'));
                // Check if option exists
                var options = templateSelect.options;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].value === newTemplate) {
                        templateSelect.value = newTemplate;
                        break;
                    }
                }
            }
        });
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLivePreview);
    } else {
        initLivePreview();
    }
})();
