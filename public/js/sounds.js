// Web Audio API Synthesizer for Game Show Sound Effects (zero external audio file dependencies)
class GameSoundFX {
    constructor() {
        this.ctx = null;
        this.enabled = true;
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
}

window.soundFX = new GameSoundFX();

