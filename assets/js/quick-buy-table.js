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

    function getEmptyText($element) {
        if (!$element || !$element.length) {
            return '';
        }

        var emptyText = $element.data('emptyText');
        if (emptyText) {
            return emptyText;
        }

        if (typeof WCQBT !== 'undefined' && WCQBT.summaryEmptyText) {
            return WCQBT.summaryEmptyText;
        }

        return 'Geen producten geselecteerd.';
    }

    function renderSummaryList($container, items) {
        if (!$container || !$container.length) {
            return;
        }

        var emptyText = getEmptyText($container);
        $container.empty();

        if (!items.length) {
            $('<li/>', {
                'class': 'wc-qbt-summary-item wc-qbt-summary-item--empty',
                text: emptyText
            }).appendTo($container);
            return;
        }

        items.forEach(function (item) {
            var $item = $('<li/>', {
                'class': 'wc-qbt-summary-item',
                'data-product-id': item.id
            });

            $('<span/>', {
                'class': 'wc-qbt-summary-item__name',
                text: item.name
            }).appendTo($item);

            $('<span/>', {
                'class': 'wc-qbt-summary-item__meta',
                text: item.quantity + ' × ' + formatPrice(item.price)
            }).appendTo($item);

            $('<span/>', {
                'class': 'wc-qbt-summary-item__subtotal',
                text: formatPrice(item.subtotal)
            }).appendTo($item);

            $item.appendTo($container);
        });
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
        var items = [];

        $form.find('.wc-qbt-table__row').each(function () {
            var $row = $(this);
            var productId = $row.data('productId');
            if (!productId) {
                return;
            }

            var $input = $row.find('input[type="number"]');
            if (!$input.length) {
                return;
            }

            var quantity = parseInt($input.val(), 10) || 0;
            var price = parseFloat($row.data('price')) || 0;
            var name = $row.data('productName');
            if (!name) {
                name = $.trim($row.find('.wc-qbt-product__name').text());
            }

            totalQuantity += quantity;
            totalAmount += quantity * price;

            if (quantity > 0) {
                items.push({
                    id: productId,
                    name: name,
                    quantity: quantity,
                    price: price,
                    subtotal: quantity * price
                });
            }
        });

        var quantityLabel = (typeof WCQBT !== 'undefined' ? WCQBT.summaryQuantityLabel : 'Totaal aantal producten') + ': ' + totalQuantity;
        var amountLabel = (typeof WCQBT !== 'undefined' ? WCQBT.summaryAmountLabel : 'Totale waarde') + ': ' + formatPrice(totalAmount);
        var shortLabel = (typeof WCQBT !== 'undefined' ? WCQBT.summaryShortLabel : 'Artikelen');

        $form.find('.wc-qbt-summary__quantity').text(quantityLabel);
        $form.find('.wc-qbt-summary__amount').text(amountLabel);

        renderSummaryList($form.find('.wc-qbt-summary__items'), items);
        renderSummaryList($form.find('.wc-qbt-floating-summary__items'), items);

        $form.find('.wc-qbt-floating-summary__short-quantity').text(shortLabel + ': ' + totalQuantity);
        $form.find('.wc-qbt-floating-summary__short-amount').text(formatPrice(totalAmount));
        $form.find('.wc-qbt-floating-summary__quantity').text(quantityLabel);
        $form.find('.wc-qbt-floating-summary__amount').text(amountLabel);
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

    function initFloatingSummary($form) {
        var $floating = $form.find('.wc-qbt-floating-summary');
        if (!$floating.length) {
            return;
        }

        var $toggle = $floating.find('.wc-qbt-floating-summary__toggle');
        var openLabel = $toggle.data('openLabel') || (typeof WCQBT !== 'undefined' ? WCQBT.summaryToggleOpen : 'Bekijk bestelling');
        var closeLabel = $toggle.data('closeLabel') || (typeof WCQBT !== 'undefined' ? WCQBT.summaryToggleClose : 'Sluit bestelling');

        $floating.find('.wc-qbt-floating-summary__toggle-text').text(openLabel);

        $toggle.on('click', function () {
            var expanded = $toggle.attr('aria-expanded') === 'true';
            expanded = !expanded;
            $toggle.attr('aria-expanded', expanded ? 'true' : 'false');
            $floating.toggleClass('wc-qbt-floating-summary--expanded', expanded);
            $floating.find('.wc-qbt-floating-summary__panel').attr('hidden', !expanded);
            $floating.find('.wc-qbt-floating-summary__toggle-text').text(expanded ? closeLabel : openLabel);
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

        initFloatingSummary($form);
        updateSummary($form);
        bindQuantityButtons($form);
    });
})(jQuery);
