/**
 * WooCommerce Telebirr Payment Gateway - Dashboard Charts
 * Lightweight canvas-based charts (no external dependencies)
 */
(function () {
    'use strict';

    if (typeof telebirrDashData === 'undefined') {
        return;
    }

    /**
     * Draw a simple bar chart on a canvas
     */
    function drawBarChart(canvasId, labels, values, color) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;

        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.parentElement.getBoundingClientRect();
        var W = rect.width;
        var H = rect.height;

        canvas.width = W * dpr;
        canvas.height = H * dpr;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';
        ctx.scale(dpr, dpr);

        var padTop = 30, padBottom = 50, padLeft = 60, padRight = 20;
        var chartW = W - padLeft - padRight;
        var chartH = H - padTop - padBottom;
        var n = labels.length;

        if (n === 0) {
            ctx.fillStyle = '#94A3B8';
            ctx.font = '14px -apple-system, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data available', W / 2, H / 2);
            return;
        }

        var maxVal = Math.max.apply(null, values);
        if (maxVal === 0) maxVal = 1;

        // Round up maxVal to a nice number
        var magnitude = Math.pow(10, Math.floor(Math.log10(maxVal)));
        maxVal = Math.ceil(maxVal / magnitude) * magnitude;

        var barGap = Math.max(4, chartW * 0.02);
        var barW = (chartW - barGap * (n + 1)) / n;
        barW = Math.min(barW, 48);

        var totalBarsWidth = n * barW + (n + 1) * barGap;
        var offsetX = padLeft + (chartW - totalBarsWidth) / 2;

        // Grid lines
        ctx.strokeStyle = '#F1F5F9';
        ctx.lineWidth = 1;
        var gridLines = 5;
        for (var g = 0; g <= gridLines; g++) {
            var gy = padTop + chartH - (g / gridLines) * chartH;
            ctx.beginPath();
            ctx.moveTo(padLeft, gy);
            ctx.lineTo(W - padRight, gy);
            ctx.stroke();

            // Y labels
            ctx.fillStyle = '#94A3B8';
            ctx.font = '11px -apple-system, sans-serif';
            ctx.textAlign = 'right';
            var yVal = (maxVal * g / gridLines);
            ctx.fillText(yVal >= 1000 ? (yVal / 1000).toFixed(1) + 'k' : yVal.toFixed(0), padLeft - 8, gy + 4);
        }

        // Bars
        for (var i = 0; i < n; i++) {
            var x = offsetX + barGap + i * (barW + barGap);
            var barH = (values[i] / maxVal) * chartH;
            var y = padTop + chartH - barH;

            // Bar fill with rounded top
            var radius = Math.min(4, barW / 4);
            ctx.fillStyle = color || '#0066B3';
            ctx.beginPath();
            ctx.moveTo(x, padTop + chartH);
            ctx.lineTo(x, y + radius);
            ctx.quadraticCurveTo(x, y, x + radius, y);
            ctx.lineTo(x + barW - radius, y);
            ctx.quadraticCurveTo(x + barW, y, x + barW, y + radius);
            ctx.lineTo(x + barW, padTop + chartH);
            ctx.closePath();
            ctx.fill();

            // Value on top of bar
            if (values[i] > 0) {
                ctx.fillStyle = '#334155';
                ctx.font = 'bold 11px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                var displayVal = values[i] >= 1000 ? (values[i] / 1000).toFixed(1) + 'k' : values[i].toString();
                ctx.fillText(displayVal, x + barW / 2, y - 8);
            }

            // X labels
            ctx.fillStyle = '#64748B';
            ctx.font = '11px -apple-system, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(labels[i], x + barW / 2, padTop + chartH + 20);
        }
    }

    /**
     * Draw a donut chart
     */
    function drawDonutChart(canvasId, segments) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;

        var ctx = canvas.getContext('2d');
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.parentElement.getBoundingClientRect();
        var size = Math.min(rect.width, rect.height);

        canvas.width = size * dpr;
        canvas.height = size * dpr;
        canvas.style.width = size + 'px';
        canvas.style.height = size + 'px';
        ctx.scale(dpr, dpr);

        var total = 0;
        for (var s = 0; s < segments.length; s++) {
            total += segments[s].value;
        }

        if (total === 0) {
            ctx.fillStyle = '#94A3B8';
            ctx.font = '14px -apple-system, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data', size / 2, size / 2);
            return;
        }

        var cx = size / 2;
        var cy = size / 2;
        var outerR = size / 2 - 10;
        var innerR = outerR * 0.6;
        var startAngle = -Math.PI / 2;

        for (var i = 0; i < segments.length; i++) {
            var sliceAngle = (segments[i].value / total) * 2 * Math.PI;
            var endAngle = startAngle + sliceAngle;

            ctx.beginPath();
            ctx.arc(cx, cy, outerR, startAngle, endAngle);
            ctx.arc(cx, cy, innerR, endAngle, startAngle, true);
            ctx.closePath();
            ctx.fillStyle = segments[i].color;
            ctx.fill();

            startAngle = endAngle;
        }

        // Center text
        ctx.fillStyle = '#1E293B';
        ctx.font = 'bold 24px -apple-system, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(total.toString(), cx, cy - 8);

        ctx.fillStyle = '#94A3B8';
        ctx.font = '12px -apple-system, sans-serif';
        ctx.fillText('Total', cx, cy + 14);
    }

    // Initialize charts when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        var data = telebirrDashData;

        // Revenue bar chart
        if (data.dailyRevenue) {
            drawBarChart(
                'telebirr-revenue-chart',
                data.dailyRevenue.labels,
                data.dailyRevenue.values,
                '#0066B3'
            );
        }

        // Status donut chart
        if (data.statusBreakdown) {
            drawDonutChart('telebirr-status-chart', data.statusBreakdown);
        }
    });

    // Redraw on resize
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var data = telebirrDashData;
            if (data.dailyRevenue) {
                drawBarChart('telebirr-revenue-chart', data.dailyRevenue.labels, data.dailyRevenue.values, '#0066B3');
            }
            if (data.statusBreakdown) {
                drawDonutChart('telebirr-status-chart', data.statusBreakdown);
            }
        }, 200);
    });
})();
