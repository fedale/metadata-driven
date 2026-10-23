import { Controller } from '@hotwired/stimulus';

/*
 * The repeatable rows of a one-to-many section of a composite form.
 *
 * "Add" appends the section's prototype, its placeholder replaced by a key no row uses;
 * "Remove" drops a row. The minimum and maximum number of rows disable the buttons; the
 * server checks both again. Rows of nested sections belong to their own controller.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'add'];
    static values = { prototype: String, prototypeName: String, index: Number, min: Number, max: Number };

    connect() {
        this.update();
    }

    add() {
        if (this.maxValue > 0 && this.rowTargets.length >= this.maxValue) {
            return;
        }
        const html = this.prototypeValue.replaceAll(this.prototypeNameValue, String(this.indexValue));
        this.indexValue += 1;
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.update();
        this.rowTargets[this.rowTargets.length - 1].querySelector('input:not([type=hidden]), select, textarea')?.focus();
    }

    remove(event) {
        if (this.rowTargets.length <= this.minValue) {
            return;
        }
        event.target.closest('[data-collection-target="row"]').remove();
        this.update();
    }

    update() {
        const rows = this.rowTargets.length;
        if (this.hasAddTarget) {
            this.addTarget.disabled = this.maxValue > 0 && rows >= this.maxValue;
        }
        for (const row of this.rowTargets) {
            const button = row.querySelector(':scope > .composite-remove, :scope > .composite-row-header > .composite-remove');
            if (button) {
                button.hidden = rows <= this.minValue;
            }
        }
    }
}
