{{--
    Reusable AJAX Select2 initialiser.

    Put class "ajax-select2" on a select plus data-ajax-url (and optional
    data-placeholder / data-min-input / data-allow-clear). Render only the
    currently selected option(s) server-side; they are preserved on edit pages.

    Notes:
      - These selects deliberately omit the "select" class so the global
        script.js initialiser leaves them alone (no double init).
      - window.initAjaxSelect2(scope) can be called for dynamically added rows
        (e.g. BOM component lines). Already-initialised selects are skipped.
--}}
@once
@push('scripts')
<script>
(function () {
    function initAjaxSelect2(scope) {
        if (typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.select2 === 'undefined') return;
        var $ = window.jQuery;
        var $root = scope ? $(scope) : $(document);

        $root.find('select.ajax-select2').each(function () {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) return; // already initialised

            $el.select2({
                width: '100%',
                allowClear: !!$el.data('allow-clear'),
                placeholder: $el.data('placeholder') || 'Search...',
                minimumInputLength: parseInt($el.data('min-input') || 1, 10),
                ajax: {
                    url: $el.data('ajax-url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        // PRODUCT-BOUNDARY-2: forward an optional role context (e.g.
                        // bom_component / bom_output / production_order) so each lookup
                        // only offers the right products. Empty context = original behaviour.
                        return {
                            q: params.term || '',
                            page: params.page || 1,
                            only_active: 1,
                            context: $el.data('context') || '',
                        };
                    },
                    processResults: function (data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results || [],
                            pagination: { more: !!(data.pagination && data.pagination.more) },
                        };
                    },
                    cache: true,
                },
            });
        });
    }

    // Expose for dynamically added rows.
    window.initAjaxSelect2 = initAjaxSelect2;

    // jQuery ready fires even if the DOM is already parsed.
    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(function () { initAjaxSelect2(document); });
    }
})();
</script>
@endpush
@endonce
