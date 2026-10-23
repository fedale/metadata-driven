import { Controller } from '@hotwired/stimulus';

/*
 * The strength meter of a password field (setting "strengthMeter"): four bars and a word under
 * the input, updated while typing.
 *
 * A hint only, computed in the browser from the length and the kinds of characters; the rules of
 * the field, checked on the server, decide what is accepted.
 */
const LEVELS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];

export default class extends Controller {
    static values = { minLength: { type: Number, default: 8 } };

    connect() {
        this.meter = document.createElement('div');
        this.meter.className = 'password-strength';
        this.meter.innerHTML = '<span></span><span></span><span></span><span></span><small aria-live="polite"></small>';
        this.element.insertAdjacentElement('afterend', this.meter);
        this.update();
    }

    disconnect() {
        this.meter?.remove();
    }

    update() {
        const level = this.level(this.element.value);
        this.meter.dataset.level = String(level ?? 0);
        this.meter.hidden = null === level;
        this.meter.querySelector('small').textContent = null === level ? '' : LEVELS[level];
    }

    /**
     * 0 (shorter than the minimum) to 4; null for an empty input.
     */
    level(password) {
        if ('' === password) {
            return null;
        }
        const length = [...password].length;
        if (length < this.minLengthValue) {
            return 0;
        }
        const kinds = [/\p{Ll}/u, /\p{Lu}/u, /\d/u, /[^\p{L}\p{N}]/u].filter((kind) => kind.test(password)).length;
        // Every kind of character beyond the first, and every 4 characters beyond the minimum, adds strength.
        const score = (kinds - 1) + Math.floor((length - this.minLengthValue) / 4);

        return Math.max(1, Math.min(4, score));
    }
}
