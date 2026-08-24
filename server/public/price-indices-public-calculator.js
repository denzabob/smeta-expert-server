(function () {
    'use strict';

    var form = document.querySelector('[data-public-index-calculator]');
    if (!form || typeof window.fetch !== 'function') {
        return;
    }

    var submit = form.querySelector('button[type="submit"]');
    var error = document.getElementById('calculation-error');
    var result = document.getElementById('calculation-result');
    var chainBody = result.querySelector('[data-result-chain]');
    var months = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

    function period(value) {
        var parts = String(value).split('-');
        var month = months[Number(parts[1]) - 1];
        return month ? month + ' ' + parts[0] : String(value);
    }

    function decimal(value, minimumScale) {
        var parts = String(value).split('.');
        var fraction = parts[1] || '';
        while (fraction.length < minimumScale) {
            fraction += '0';
        }
        return parts[0] + (fraction ? ',' + fraction : '');
    }

    function money(value) {
        var formatted = decimal(value, 2).split(',');
        formatted[0] = formatted[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return formatted.join(',') + ' ₽';
    }

    function change(value) {
        var stringValue = String(value);
        var zero = /^-?0(?:\.0+)?$/.test(stringValue);
        var negative = stringValue.charAt(0) === '-';
        var unsigned = negative ? stringValue.slice(1) : stringValue;
        var direction = zero ? 'без изменения' : (negative ? 'снижение' : 'рост');
        return (zero ? '' : (negative ? '−' : '+')) + decimal(unsigned, 2) + ' % (' + direction + ')';
    }

    function cell(row, value) {
        var node = document.createElement('td');
        node.textContent = value;
        row.appendChild(node);
    }

    function errorMessage(payload) {
        var messages = {
            invalid_amount: 'Проверьте сумму: используйте положительное число не более чем с двумя знаками после запятой.',
            invalid_period_range: 'Проверьте начальный и конечный периоды.',
            period_before_available_range: 'Начальный период находится раньше доступных данных.',
            period_after_available_range: 'Конечный период находится позже доступных данных.',
            period_too_long: 'Выбранный период превышает допустимую длину.',
            incomplete_observation_chain: 'Для выбранного периода нет полной последовательности месячных значений.',
            unsupported_series_calculation: 'Этот статистический ряд не поддерживает публичный расчёт.',
            public_series_not_available: 'Опубликованный ряд недоступен для расчёта.',
            public_snapshot_unavailable: 'Публичный snapshot временно недоступен для безопасного расчёта.'
        };
        return messages[payload && payload.code] || 'Не удалось выполнить расчёт. Попробуйте ещё раз.';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.reportValidity()) {
            return;
        }

        var fields = new FormData(form);
        var amount = String(fields.get('amount') || '').trim().replace(',', '.');
        var payload = {
            start_period: String(fields.get('start_period')),
            end_period: String(fields.get('end_period'))
        };
        if (amount !== '') {
            payload.amount = amount;
        }

        error.hidden = true;
        result.hidden = true;
        submit.disabled = true;
        submit.textContent = 'Выполняется…';
        form.setAttribute('aria-busy', 'true');

        window.fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            credentials: 'omit',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok) {
                    throw body;
                }
                return body.data;
            });
        }).then(function (data) {
            result.querySelector('[data-result-period]').textContent = period(data.period.start) + ' → ' + period(data.period.end);
            result.querySelector('[data-result-coefficient]').textContent = decimal(data.coefficient, 12);
            result.querySelector('[data-result-change]').textContent = change(data.change_percent);

            var amountRow = result.querySelector('[data-result-amount-row]');
            if (data.amount) {
                result.querySelector('[data-result-amount]').textContent = money(data.amount.original) + ' → ' + money(data.amount.adjusted);
                amountRow.hidden = false;
            } else {
                amountRow.hidden = true;
            }

            chainBody.replaceChildren();
            data.chain.forEach(function (factor) {
                var row = document.createElement('tr');
                cell(row, period(factor.period));
                cell(row, decimal(factor.index, 2));
                cell(row, decimal(factor.factor, 12));
                cell(row, decimal(factor.running_coefficient, 12));
                chainBody.appendChild(row);
            });

            var publication = data.provenance.publication;
            var source = data.provenance.source;
            var classifier = data.page.classifier;
            result.querySelector('[data-result-provenance]').textContent =
                data.provenance.provider + ' · статистический ряд ' + classifier.code + ' — ' + data.page.title +
                ' · данные по ' + period(data.provenance.snapshot.period_to) +
                ' · публикация ' + publication.reference +
                ' · источник ' + source.filename + ' · SHA-256 ' + source.sha256;
            result.hidden = false;
            result.focus();
        }).catch(function (payload) {
            error.textContent = errorMessage(payload);
            error.hidden = false;
        }).finally(function () {
            submit.disabled = false;
            submit.textContent = 'Рассчитать';
            form.removeAttribute('aria-busy');
        });
    });
}());
