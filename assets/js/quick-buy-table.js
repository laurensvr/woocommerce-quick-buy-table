(function ($) {
    'use strict';

    function formatPrice(amount) {
        if (typeof WCQBT === 'undefined') {
            return amount.toFixed(2);
        }

        var decimals = parseInt(WCQBT.decimals, 10);
        if (isNaN(decimals)) {
            decimals = 2;
        }

        var decimalSeparator = WCQBT.decimal_separator || ',';
        var thousandSeparator = WCQBT.thousand_separator || '.';
        var currencySymbol = WCQBT.currency_symbol || '€';
        var priceFormat = WCQBT.price_format || '%1$s%2$s';

        var negative = amount < 0 ? '-' : '';
        amount = Math.abs(amount);

        var number = amount.toFixed(decimals);
        var parts = number.split('.');
        var integerPart = parts[0];
        var fractionPart = parts.length > 1 ? parts[1] : '';

        integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
        if (decimals > 0) {
            number = integerPart + decimalSeparator + fractionPart;
        } else {
            number = integerPart;
        }

        var formatted = priceFormat.replace('%1$s', currencySymbol).replace('%2$s', negative + number);

        return formatted;
    }

    function normalizeQuantity(value, step) {
        var quantity = parseInt(value, 10);
        if (isNaN(quantity) || quantity < 0) {
            quantity = 0;
        }

        step = parseInt(step, 10);
        if (!isNaN(step) && step > 1 && quantity > 0) {
            quantity = Math.max(step, Math.ceil(quantity / step) * step);
        }

        return quantity;
    }

    function updateRow($row) {
        var $input = $row.find('input[type="number"]');
        if (!$input.length) {
            return;
        }

        var step = parseInt($row.find('.wc-qbt-quantity').data('step'), 10) || 1;
        var value = normalizeQuantity($input.val(), step);
        $input.val(value);

        var price = parseFloat($row.data('price')) || 0;
        var subtotal = price * value;
        $row.find('.wc-qbt-subtotal').text(formatPrice(subtotal));
    }

    function updateSummary($form) {
        var totalQuantity = 0;
        var totalAmount = 0;

        $form.find('.wc-qbt-table__row').each(function () {
            var $row = $(this);
            var $input = $row.find('input[type="number"]');
            if (!$input.length) {
                return;
            }

            var quantity = parseInt($input.val(), 10) || 0;
            var price = parseFloat($row.data('price')) || 0;

            totalQuantity += quantity;
            totalAmount += quantity * price;
        });

        var quantityLabel = (typeof WCQBT !== 'undefined' ? WCQBT.summaryQuantityLabel : 'Totaal aantal') + ': ' + totalQuantity;
        var amountLabel = (typeof WCQBT !== 'undefined' ? WCQBT.summaryAmountLabel : 'Totaal bedrag') + ': ' + formatPrice(totalAmount);

        $form.find('.wc-qbt-summary__quantity').text(quantityLabel);
        $form.find('.wc-qbt-summary__amount').text(amountLabel);
    }

    function bindQuantityButtons($form) {
        $form.on('click', '.wc-qbt-quantity__button--plus', function (event) {
            event.preventDefault();
            var $container = $(this).closest('.wc-qbt-quantity');
            var $input = $container.find('input[type="number"]');
            var step = parseInt($container.data('step'), 10) || 1;
            var current = parseInt($input.val(), 10) || 0;
            current += step;
            $input.val(current).trigger('change');
        });

        $form.on('click', '.wc-qbt-quantity__button--minus', function (event) {
            event.preventDefault();
            var $container = $(this).closest('.wc-qbt-quantity');
            var $input = $container.find('input[type="number"]');
            var step = parseInt($container.data('step'), 10) || 1;
            var current = parseInt($input.val(), 10) || 0;
            current = Math.max(0, current - step);
            $input.val(current).trigger('change');
        });

        $form.on('change keyup', 'input[type="number"]', function () {
            var $input = $(this);
            var $row = $input.closest('.wc-qbt-table__row');
            var step = parseInt($row.find('.wc-qbt-quantity').data('step'), 10) || 1;
            var normalized = normalizeQuantity($input.val(), step);
            $input.val(normalized);
            updateRow($row);
            updateSummary($form);
        });
    }

    $(function () {
        var $form = $('.wc-qbt-form');
        if (!$form.length) {
            return;
        }

        $form.find('.wc-qbt-table__row').each(function () {
            updateRow($(this));
        });

        updateSummary($form);
        bindQuantityButtons($form);
    });
})(jQuery);
