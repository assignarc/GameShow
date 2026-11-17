// Web Audio API Synthesizer for Game Show Sound Effects (zero external audio file dependencies)
class GameSoundFX {
    constructor() {
        this.ctx = null;
        this.enabled = true;
        this._audioCache = {};
        this._activeClip = null;
        this._bindAutoUnlock();
    }

    _bindAutoUnlock() {
        const unlock = () => {
            if (!this.enabled) return;
            this.init();
        };
        if (typeof window !== 'undefined') {
            ['click', 'touchstart', 'keydown', 'pointerdown'].forEach(evt => {
                window.addEventListener(evt, unlock, { passive: true });
            });
        }
    }

    init() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) {
                this.ctx = new AudioCtx();
            }
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume().catch(() => {});
        }
    }

    _runWhenReady(fn) {
        if (!this.enabled) return;
        this.init();
        if (!this.ctx) return;
        if (this.ctx.state === 'suspended') {
            this.ctx.resume().then(() => {
                if (this.ctx && this.ctx.state === 'running') {
                    fn();
                }
            }).catch(() => {});
        } else {
            fn();
        }
    }

    playTick() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, now);
            osc.frequency.exponentialRampToValueAtTime(440, now + 0.05);

            gain.gain.setValueAtTime(0.18, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);

            osc.connect(gain);
            gain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.05);
        });
    }

    playWarningTick() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();

            osc.type = 'triangle';
            osc.frequency.setValueAtTime(987.77, now); // B5

            gain.gain.setValueAtTime(0.32, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);

            osc.connect(gain);
            gain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.08);
        });
    }

    playTimeUpBuzzer() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const osc1 = this.ctx.createOscillator();
            const osc2 = this.ctx.createOscillator();
            const gain = this.ctx.createGain();

            osc1.type = 'sawtooth';
            osc2.type = 'sawtooth';

            osc1.frequency.setValueAtTime(130, now);
            osc2.frequency.setValueAtTime(134, now); // dissonant beating

            gain.gain.setValueAtTime(0.4, now);
            gain.gain.linearRampToValueAtTime(0.4, now + 0.5);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.7);

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(this.ctx.destination);

            osc1.start(now);
            osc2.start(now);
            osc1.stop(now + 0.7);
            osc2.stop(now + 0.7);
        });
    }

    playDingCorrect() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const notes = [523.25, 659.25, 783.99, 1046.50]; // C Major Arpeggio

            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();

                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + idx * 0.08);

                gain.gain.setValueAtTime(0.001, now + idx * 0.08);
                gain.gain.linearRampToValueAtTime(0.28, now + idx * 0.08 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.08 + 0.45);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(now + idx * 0.08);
                osc.stop(now + idx * 0.08 + 0.5);
            });
        });
    }

    playFanfare() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const chords = [
                { t: 0.0, f: [392, 523.25] },
                { t: 0.15, f: [392, 523.25] },
                { t: 0.3, f: [392, 523.25] },
                { t: 0.45, f: [523.25, 659.25, 783.99] }
            ];

            chords.forEach(c => {
                c.f.forEach(freq => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(freq, now + c.t);

                    gain.gain.setValueAtTime(0.22, now + c.t);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + c.t + 0.35);

                    osc.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now + c.t);
                    osc.stop(now + c.t + 0.4);
                });
            });
        });
    }

    playSplashIntro() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            // Celebratory Game Show Opening Theme (brass + chime fanfare)
            const sequence = [
                { t: 0.00, d: 0.16, f: [261.63, 329.63, 392.00], type: 'triangle', v: 0.24 },
                { t: 0.18, d: 0.16, f: [293.66, 369.99, 440.00], type: 'triangle', v: 0.24 },
                { t: 0.36, d: 0.16, f: [329.63, 415.30, 493.88], type: 'triangle', v: 0.26 },
                { t: 0.54, d: 0.22, f: [349.23, 440.00, 523.25], type: 'triangle', v: 0.28 },
                { t: 0.80, d: 0.14, f: [392.00, 493.88, 587.33], type: 'triangle', v: 0.28 },
                { t: 0.96, d: 0.65, f: [523.25, 659.25, 783.99, 1046.50], type: 'triangle', v: 0.30 }
            ];

            sequence.forEach(step => {
                step.f.forEach(freq => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = step.type;
                    osc.frequency.setValueAtTime(freq, now + step.t);

                    gain.gain.setValueAtTime(0.001, now + step.t);
                    gain.gain.linearRampToValueAtTime(step.v, now + step.t + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + step.t + step.d);

                    osc.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now + step.t);
                    osc.stop(now + step.t + step.d + 0.05);
                });
            });
        });
    }

    playTeamUnveil() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            // Rising sparkle + grand chord for unveiling competing teams
            const notes = [392.00, 493.88, 587.33, 783.99, 987.77, 1174.66];
            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                const start = now + idx * 0.065;
                osc.frequency.setValueAtTime(freq, start);

                gain.gain.setValueAtTime(0.001, start);
                gain.gain.linearRampToValueAtTime(0.28, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.45);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(start);
                osc.stop(start + 0.5);
            });
        });
    }

    playRuleWhoosh() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            // Two-note chime + whoosh for unveiling a rule
            const chimeNotes = [523.25, 659.25, 783.99];
            chimeNotes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                const start = now + idx * 0.055;
                osc.frequency.setValueAtTime(freq, start);

                gain.gain.setValueAtTime(0.001, start);
                gain.gain.linearRampToValueAtTime(0.26, start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.28);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(start);
                osc.stop(start + 0.32);
            });
        });
    }

    playRoundStartHorn() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            // High-energy "LET'S PLAY!" arena horn + octave lock
            const hits = [
                { t: 0.00, d: 0.12, f: [440, 554.37, 659.25] },
                { t: 0.14, d: 0.12, f: [440, 554.37, 659.25] },
                { t: 0.28, d: 0.50, f: [587.33, 739.99, 880, 1174.66] }
            ];
            hits.forEach(h => {
                h.f.forEach(freq => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, now + h.t);

                    gain.gain.setValueAtTime(0.001, now + h.t);
                    gain.gain.linearRampToValueAtTime(0.30, now + h.t + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + h.t + h.d);

                    osc.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now + h.t);
                    osc.stop(now + h.t + h.d + 0.05);
                });
            });
        });
    }

    playPointsAwarded() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            // Celebratory arcade coin + jackpot chord
            const steps = [
                { t: 0.00, d: 0.12, f: [987.77] },
                { t: 0.08, d: 0.28, f: [1318.51] },
                { t: 0.20, d: 0.48, f: [523.25, 659.25, 783.99, 1046.50, 1567.98] }
            ];
            steps.forEach(s => {
                s.f.forEach(freq => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'triangle';
                    osc.frequency.setValueAtTime(freq, now + s.t);

                    gain.gain.setValueAtTime(0.001, now + s.t);
                    gain.gain.linearRampToValueAtTime(0.28, now + s.t + 0.015);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + s.t + s.d);

                    osc.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now + s.t);
                    osc.stop(now + s.t + s.d + 0.04);
                });
            });
        });
    }

    playPointsPenalty() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const steps = [
                { t: 0.00, d: 0.16, f: 349.23 },
                { t: 0.14, d: 0.28, f: 233.08 }
            ];
            steps.forEach(s => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(s.f, now + s.t);

                gain.gain.setValueAtTime(0.001, now + s.t);
                gain.gain.linearRampToValueAtTime(0.25, now + s.t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, now + s.t + s.d);

                osc.connect(gain);
                gain.connect(this.ctx.destination);

                osc.start(now + s.t);
                osc.stop(now + s.t + s.d + 0.04);
            });
        });
    }

    _createNoiseBuffer(durationSec) {
        const sampleRate = this.ctx.sampleRate;
        const length = Math.floor(sampleRate * durationSec);
        const buffer = this.ctx.createBuffer(1, length, sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < length; i++) {
            data[i] = Math.random() * 2 - 1;
        }
        return buffer;
    }

    playDrumroll() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const noiseBuf = this._createNoiseBuffer(0.06);
            // 18 accelerating snare hits + final cymbal/kick accent
            let t = 0;
            for (let i = 0; i < 18; i++) {
                const src = this.ctx.createBufferSource();
                src.buffer = noiseBuf;
                const filter = this.ctx.createBiquadFilter();
                filter.type = 'bandpass';
                filter.frequency.setValueAtTime(1100 + i * 35, now + t);
                const gain = this.ctx.createGain();
                const vol = 0.08 + (i / 18) * 0.22;
                gain.gain.setValueAtTime(0.001, now + t);
                gain.gain.linearRampToValueAtTime(vol, now + t + 0.008);
                gain.gain.exponentialRampToValueAtTime(0.001, now + t + 0.055);
                src.connect(filter);
                filter.connect(gain);
                gain.connect(this.ctx.destination);
                src.start(now + t);
                src.stop(now + t + 0.06);
                t += Math.max(0.04, 0.095 - i * 0.003);
            }
            // Final cymbal splash
            const crashSrc = this.ctx.createBufferSource();
            crashSrc.buffer = this._createNoiseBuffer(0.7);
            const hp = this.ctx.createBiquadFilter();
            hp.type = 'highpass';
            hp.frequency.setValueAtTime(2800, now + t);
            const cGain = this.ctx.createGain();
            cGain.gain.setValueAtTime(0.32, now + t);
            cGain.gain.exponentialRampToValueAtTime(0.001, now + t + 0.65);
            crashSrc.connect(hp);
            hp.connect(cGain);
            cGain.connect(this.ctx.destination);
            crashSrc.start(now + t);
            crashSrc.stop(now + t + 0.7);
        });
    }

    playSadTrombone() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const notes = [
                { t: 0.00, d: 0.30, f: 311.13 },
                { t: 0.32, d: 0.30, f: 293.66 },
                { t: 0.64, d: 0.30, f: 277.18 },
                { t: 0.96, d: 0.75, f: 261.63, slideTo: 220 }
            ];
            notes.forEach(n => {
                const osc = this.ctx.createOscillator();
                const filter = this.ctx.createBiquadFilter();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(n.f, now + n.t);
                if (n.slideTo) {
                    osc.frequency.linearRampToValueAtTime(n.slideTo, now + n.t + n.d);
                }
                filter.type = 'lowpass';
                filter.frequency.setValueAtTime(1100, now + n.t);
                gain.gain.setValueAtTime(0.001, now + n.t);
                gain.gain.linearRampToValueAtTime(0.26, now + n.t + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.001, now + n.t + n.d);
                osc.connect(filter);
                filter.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + n.t);
                osc.stop(now + n.t + n.d + 0.05);
            });
        });
    }

    playDunDunDun() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const hits = [
                { t: 0.00, d: 0.22, freqs: [146.83, 293.66, 349.23] },
                { t: 0.26, d: 0.22, freqs: [138.59, 277.18, 329.63] },
                { t: 0.54, d: 0.85, freqs: [130.81, 261.63, 311.13, 392.00] }
            ];
            hits.forEach(h => {
                h.freqs.forEach(f => {
                    const osc = this.ctx.createOscillator();
                    const filter = this.ctx.createBiquadFilter();
                    const gain = this.ctx.createGain();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(f, now + h.t);
                    filter.type = 'lowpass';
                    filter.frequency.setValueAtTime(1400, now + h.t);
                    gain.gain.setValueAtTime(0.001, now + h.t);
                    gain.gain.linearRampToValueAtTime(0.22, now + h.t + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + h.t + h.d);
                    osc.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + h.t);
                    osc.stop(now + h.t + h.d + 0.05);
                });
            });
        });
    }

    playWrongBuzz() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            [0, 0.16].forEach((offset, idx) => {
                const dur = idx === 0 ? 0.12 : 0.28;
                [95, 102].forEach(f => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(f, now + offset);
                    gain.gain.setValueAtTime(0.001, now + offset);
                    gain.gain.linearRampToValueAtTime(0.28, now + offset + 0.015);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + offset + dur);
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + offset);
                    osc.stop(now + offset + dur + 0.03);
                });
            });
        });
    }

    playApplause() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const clapBuf = this._createNoiseBuffer(0.07);
            // Rapid overlapping clap bursts for ~1.8s
            for (let i = 0; i < 36; i++) {
                const t = i * 0.045 + Math.random() * 0.02;
                const src = this.ctx.createBufferSource();
                src.buffer = clapBuf;
                const bp = this.ctx.createBiquadFilter();
                bp.type = 'bandpass';
                bp.frequency.setValueAtTime(1200 + Math.random() * 1400, now + t);
                bp.Q.setValueAtTime(1.5, now + t);
                const gain = this.ctx.createGain();
                const env = i < 8 ? (i + 1) / 8 : (i > 26 ? (36 - i) / 10 : 1);
                gain.gain.setValueAtTime(0.001, now + t);
                gain.gain.linearRampToValueAtTime(0.22 * env, now + t + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.001, now + t + 0.065);
                src.connect(bp);
                bp.connect(gain);
                gain.connect(this.ctx.destination);
                src.start(now + t);
                src.stop(now + t + 0.07);
            }
        });
    }

    playIplHorn() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const hits = [
                { t: 0.00, d: 0.14 },
                { t: 0.17, d: 0.14 },
                { t: 0.34, d: 0.55 }
            ];
            hits.forEach(h => {
                [466.16, 469.5, 932.33].forEach(f => {
                    const osc = this.ctx.createOscillator();
                    const filter = this.ctx.createBiquadFilter();
                    const gain = this.ctx.createGain();
                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(f, now + h.t);
                    filter.type = 'bandpass';
                    filter.frequency.setValueAtTime(1400, now + h.t);
                    gain.gain.setValueAtTime(0.001, now + h.t);
                    gain.gain.linearRampToValueAtTime(0.24, now + h.t + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + h.t + h.d);
                    osc.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + h.t);
                    osc.stop(now + h.t + h.d + 0.04);
                });
            });
        });
    }

    playCheersClink() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            [2637, 3136, 3951].forEach((f, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                const start = now + idx * 0.03;
                osc.frequency.setValueAtTime(f, start);
                gain.gain.setValueAtTime(0.001, start);
                gain.gain.linearRampToValueAtTime(0.24, start + 0.006);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.45);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(start);
                osc.stop(start + 0.48);
            });
        });
    }

    playRecordScratch() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(820, now);
            osc.frequency.exponentialRampToValueAtTime(280, now + 0.08);
            osc.frequency.exponentialRampToValueAtTime(640, now + 0.16);
            osc.frequency.exponentialRampToValueAtTime(85, now + 0.34);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.28, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.36);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.38);
        });
    }

    playBoxingBell() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            [0.0, 0.18].forEach(offset => {
                [1180, 1775, 2360].forEach(f => {
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(f, now + offset);
                    gain.gain.setValueAtTime(0.001, now + offset);
                    gain.gain.linearRampToValueAtTime(0.24, now + offset + 0.008);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + offset + 0.55);
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now + offset);
                    osc.stop(now + offset + 0.6);
                });
            });
        });
    }

    playMagicSparkle() {
        this._runWhenReady(() => {
            const now = this.ctx.currentTime;
            const notes = [783.99, 987.77, 1174.66, 1567.98, 1975.53, 2349.32];
            notes.forEach((f, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                const start = now + idx * 0.05;
                osc.frequency.setValueAtTime(f, start);
                gain.gain.setValueAtTime(0.001, start);
                gain.gain.linearRampToValueAtTime(0.22, start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.35);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(start);
                osc.stop(start + 0.4);
            });
        });
    }

    _playStudioClip(name, fallbackFn) {
        if (!this.enabled) return;
        try {
            if (this._activeClip) {
                this._activeClip.pause();
                this._activeClip.currentTime = 0;
            }
            let clip = this._audioCache[name];
            if (!clip) {
                clip = new Audio(`/sfx/${name}.mp3?v=2`);
                clip.preload = 'none';
                this._audioCache[name] = clip;
            }
            clip.currentTime = 0;
            clip.volume = 0.85;
            this._activeClip = clip;
            const p = clip.play();
            if (p && typeof p.catch === 'function') {
                p.catch(() => {
                    if (fallbackFn) fallbackFn();
                });
            }
        } catch (e) {
            if (fallbackFn) fallbackFn();
        }
    }

    playByName(name) {
        const fallbacks = {
            drumroll: () => this.playDrumroll(),
            sad_trombone: () => this.playSadTrombone(),
            dun_dun_dun: () => this.playDunDunDun(),
            wrong_buzz: () => this.playWrongBuzz(),
            applause: () => this.playApplause(),
            ipl_horn: () => this.playIplHorn(),
            cheers_clink: () => this.playCheersClink(),
            record_scratch: () => this.playRecordScratch(),
            boxing_bell: () => this.playBoxingBell(),
            magic_sparkle: () => this.playMagicSparkle(),
            kahan_se_aate: () => this.playSadTrombone(),
            mogambo_khush_hua: () => this.playFanfare(),
            control_uday: () => this.playRecordScratch(),
            tussi_great_ho: () => this.playCheersClink(),
            nana_laugh: () => this.playApplause(),
            ruko_zara: () => this.playBoxingBell(),
        };
        if (fallbacks[name]) {
            this._playStudioClip(name, fallbacks[name]);
        }
    }
}

window.soundFX = new GameSoundFX();

