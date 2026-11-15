import { Controller } from '@hotwired/stimulus';

/*
 * The variable picker of the workflow editor: a click puts {{ path }} where the cursor is in the
 * definition, and the cursor after it.
 */
export default class extends Controller {
    static targets = ['input'];

    insert({ params: { path } }) {
        const input = this.inputTarget;
        const text = `{{ ${path} }}`;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        input.focus();
        input.setSelectionRange(start + text.length, start + text.length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
