{{--
    Shared notification-sound engine for the admin chat workspace.
    ─────────────────────────────────────────────────────────────────────────────
    Exposes window.tgSupportSound — a tiny Web Audio API helper. Included in the
    admin-chat and admin-settings layouts so the chat page (new-message alert) and
    the Settings → Основные preview play exactly the same sound. No build step
    needed (plain inline script, not a Vite module).

    • The active sound is fixed to «Колокольчики» (ACTIVE = 'bells'); there is no
      in-app chooser. Swap the ACTIVE key to change it. Other presets stay defined.
    • Audio must be unlocked from a user gesture (autoplay policy) via unlock().
--}}
<script>
(function () {
    if (window.tgSupportSound) { return; }

    var ctx = null;

    // Lazily create / resume a single shared AudioContext.
    function audioCtx() {
        try {
            if (!ctx) {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) { return null; }
                ctx = new Ctx();
            }
            if (ctx.state === 'suspended') { ctx.resume(); }
            return ctx;
        } catch (e) { return null; }
    }

    // Schedule one tone with a soft attack/decay envelope.
    function note(ac, opts) {
        var osc = ac.createOscillator();
        var gain = ac.createGain();
        osc.type = opts.type || 'sine';
        osc.frequency.setValueAtTime(opts.freq, opts.start);
        if (opts.glideTo) {
            osc.frequency.exponentialRampToValueAtTime(opts.glideTo, opts.start + opts.dur);
        }
        var peak = opts.peak || 0.5;
        gain.gain.setValueAtTime(0.0001, opts.start);
        gain.gain.exponentialRampToValueAtTime(peak, opts.start + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.0001, opts.start + opts.dur);
        osc.connect(gain).connect(ac.destination);
        osc.start(opts.start);
        osc.stop(opts.start + opts.dur + 0.03);
    }

    // Each preset schedules its tones on the shared context.
    var presets = {
        double: {
            label: 'Двойной',
            desc: 'Два восходящих тона',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 880,  start: t,        dur: 0.30, peak: 0.50 });
                note(ac, { freq: 1175, start: t + 0.17, dur: 0.40, peak: 0.55 });
            },
        },
        chime: {
            label: 'Перезвон',
            desc: 'Тройной колокольчик вверх',
            play: function (ac) {
                var t = ac.currentTime;
                [1047, 1319, 1568].forEach(function (f, i) {
                    note(ac, { freq: f, start: t + i * 0.15, dur: 0.55, peak: 0.48 });
                });
            },
        },
        soft: {
            label: 'Мягкий',
            desc: 'Тёплый низкий сигнал',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 587, start: t,        dur: 0.55, peak: 0.55, type: 'triangle' });
                note(ac, { freq: 784, start: t + 0.20, dur: 0.65, peak: 0.55, type: 'triangle' });
            },
        },
        alert: {
            label: 'Внимание',
            desc: 'Заметный сигнал в три тона',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 988,  start: t,        dur: 0.16, peak: 0.50, type: 'triangle' });
                note(ac, { freq: 988,  start: t + 0.21, dur: 0.16, peak: 0.50, type: 'triangle' });
                note(ac, { freq: 1319, start: t + 0.45, dur: 0.45, peak: 0.58, type: 'triangle' });
            },
        },
        pop: {
            label: 'Хлопок',
            desc: 'Короткий «пузырёк» вверх',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 520, glideTo: 1240, start: t, dur: 0.22, peak: 0.55 });
            },
        },
        drop: {
            label: 'Капля',
            desc: 'Мягкий нисходящий тон',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 1320, glideTo: 660, start: t, dur: 0.45, peak: 0.55 });
            },
        },
        telegram: {
            label: 'Мессенджер',
            desc: 'Быстрый сигнал в стиле мессенджера',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 660,  start: t,        dur: 0.13, peak: 0.50 });
                note(ac, { freq: 880,  start: t + 0.11, dur: 0.13, peak: 0.52 });
                note(ac, { freq: 1320, start: t + 0.22, dur: 0.34, peak: 0.55 });
            },
        },
        marimba: {
            label: 'Маримба',
            desc: 'Тёплый деревянный перебор',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 392, start: t,        dur: 0.40, peak: 0.55, type: 'triangle' });
                note(ac, { freq: 523, start: t + 0.13, dur: 0.45, peak: 0.55, type: 'triangle' });
                note(ac, { freq: 659, start: t + 0.26, dur: 0.55, peak: 0.55, type: 'triangle' });
            },
        },
        crystal: {
            label: 'Кристалл',
            desc: 'Высокий искристый перелив',
            play: function (ac) {
                var t = ac.currentTime;
                [1568, 1976, 2349, 2637].forEach(function (f, i) {
                    note(ac, { freq: f, start: t + i * 0.08, dur: 0.45, peak: 0.42 });
                });
            },
        },
        pulse: {
            label: 'Пульс',
            desc: 'Двойной настойчивый сигнал',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 740, start: t,        dur: 0.18, peak: 0.55, type: 'triangle' });
                note(ac, { freq: 740, start: t + 0.26, dur: 0.18, peak: 0.55, type: 'triangle' });
            },
        },
        stardust: {
            label: 'Звездопад',
            desc: 'Искристый перелив вниз',
            play: function (ac) {
                var t = ac.currentTime;
                [2637, 2349, 1976, 1568, 1319].forEach(function (f, i) {
                    note(ac, { freq: f, start: t + i * 0.07, dur: 0.40, peak: 0.40 });
                });
            },
        },
        glass: {
            label: 'Стекло',
            desc: 'Звон стекла с долгим хвостом',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 2093, start: t,        dur: 0.70, peak: 0.42 });
                note(ac, { freq: 3136, start: t + 0.02, dur: 0.70, peak: 0.30 });
                note(ac, { freq: 2637, start: t + 0.10, dur: 0.60, peak: 0.36 });
            },
        },
        sparkle: {
            label: 'Искры',
            desc: 'Быстрая россыпь высоких нот',
            play: function (ac) {
                var t = ac.currentTime;
                [1760, 2093, 2637, 2093, 3136, 2637].forEach(function (f, i) {
                    note(ac, { freq: f, start: t + i * 0.05, dur: 0.30, peak: 0.36 });
                });
            },
        },
        bells: {
            label: 'Колокольчики',
            desc: 'Высокая пара с долгим звоном',
            play: function (ac) {
                var t = ac.currentTime;
                note(ac, { freq: 1976, start: t,        dur: 1.90, peak: 0.45 });
                note(ac, { freq: 2637, start: t + 0.16, dur: 2.00, peak: 0.45 });
            },
        },
    };

    // The notification sound is fixed to «Колокольчики» — there is no in-app
    // chooser. The other presets stay defined for quick swapping in code.
    var ACTIVE = 'bells';

    window.tgSupportSound = {
        presets: presets,
        unlock: function () { audioCtx(); },
        play: function (key) {
            var ac = audioCtx();
            if (!ac) { return; }
            (presets[key] || presets[ACTIVE]).play(ac);
        },
        playSelected: function () { this.play(ACTIVE); },
    };
})();
</script>
