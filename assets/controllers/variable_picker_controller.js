import { Controller } from '@hotwired/stimulus';

/*
 * The variable picker of the workflow editors: a click puts {{ path }} where the cursor is, in the
 * text input or area last focused inside the controller's element (the YAML definition, or an
 * option of the visual editor); the input event that follows updates a live component's model.
 */
export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.last = null;
        this.remember = (event) => {
            if (event.target.matches('textarea, input[type="text"], input:not([type])')) {
                this.last = event.target;
            }
        };
        this.element.addEventListener('focusin', this.remember);
    }

    disconnect() {
        this.element.removeEventListener('focusin', this.remember);
    }

    insert({ params: { path } }) {
        const input = this.last && this.element.contains(this.last) ? this.last : (this.hasInputTarget ? this.inputTarget : null);
        if (!input) {
            return;
        }
        const text = `{{ ${path} }}`;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        input.focus();
        input.setSelectionRange(start + text.length, start + text.length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
