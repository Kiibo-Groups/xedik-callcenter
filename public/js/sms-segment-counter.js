// public/js/sms-segment-counter.js
// Espejo en JS de App\Helpers\SmsSegmentHelper (PHP) — mismo criterio GSM-7/Unicode
// y mismos límites de segmento, para que el contador se sienta instantáneo sin round-trip.
(function (global) {
    var GSM7_BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    var GSM7_EXTENDED = "^{}\\[~]|€";

    function isGsm7(text) {
        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            if (GSM7_BASIC.indexOf(ch) === -1 && GSM7_EXTENDED.indexOf(ch) === -1) {
                return false;
            }
        }
        return true;
    }

    function effectiveLength(text, gsm7) {
        if (!gsm7) return Array.from(text).length;
        var len = 0;
        Array.from(text).forEach(function (ch) {
            len += GSM7_EXTENDED.indexOf(ch) !== -1 ? 2 : 1;
        });
        return len;
    }

    function analyze(text) {
        if (!text) return { encoding: 'gsm7', length: 0, segments: 0 };
        var gsm7 = isGsm7(text);
        var length = effectiveLength(text, gsm7);
        var singleLimit = gsm7 ? 160 : 70;
        var concatLimit = gsm7 ? 153 : 67;
        var segments = length <= singleLimit ? 1 : Math.ceil(length / concatLimit);
        return { encoding: gsm7 ? 'gsm7' : 'ucs2', length: length, segments: segments };
    }

    function formatLabel(text, maxRecommendedSegments) {
        var a = analyze(text);
        var label = a.length + ' chars · ' + a.segments + ' segment' + (a.segments === 1 ? '' : 's') + ' (' + a.encoding.toUpperCase() + ')';
        if (maxRecommendedSegments && a.segments > maxRecommendedSegments) {
            label += ' — exceeds recommended max of ' + maxRecommendedSegments;
        }
        return { label: label, analysis: a };
    }

    global.SmsSegmentCounter = { analyze: analyze, formatLabel: formatLabel };
})(window);
