import { Controller } from '@hotwired/stimulus';

/*
 * The current files of a file field holding several files (RecordFileListType): moves a file
 * up or down, removes it, and writes the ids left, in their order, into the hidden "kept"
 * input that the server reads.
 *
 * The buttons are hidden in the markup and shown here: without this controller the hidden
 * input keeps every file as it is. Nothing is listened to outside the element, so there is
 * nothing to clean up on disconnect, and a Turbo render starts again from the markup.
 */
export default class extends Controller {
    static targets = ['item', 'actions', 'kept'];

    connect() {
        this.actionsTargets.forEach((actions) => {
            actions.hidden = false;
        });
        this.refresh();
    }

    up(event) {
        const item = this.itemOf(event);
        const previous = item?.previousElementSibling;
        if (previous) {
            previous.before(item);
            this.refresh();
            this.keepFocus(event.currentTarget, item, 'down');
        }
    }

    down(event) {
        const item = this.itemOf(event);
        const next = item?.nextElementSibling;
        if (next) {
            next.after(item);
            this.refresh();
            this.keepFocus(event.currentTarget, item, 'up');
        }
    }

    remove(event) {
        const item = this.itemOf(event);
        if (!item) {
            return;
        }
        const neighbour = item.nextElementSibling ?? item.previousElementSibling;
        item.remove();
        this.refresh();
        // The focus goes to the next file's "Remove", or to the file input once the list is empty.
        const focus = neighbour?.querySelector('[data-action~="file-list#remove"]')
            ?? this.element.querySelector('input[type="file"]');
        focus?.focus();
    }

    refresh() {
        if (this.hasKeptTarget) {
            this.keptTarget.value = this.itemTargets.map((item) => item.dataset.id).join(',');
        }
        const last = this.itemTargets.length - 1;
        this.itemTargets.forEach((item, index) => {
            const up = item.querySelector('[data-action~="file-list#up"]');
            const down = item.querySelector('[data-action~="file-list#down"]');
            if (up) {
                up.disabled = 0 === index;
            }
            if (down) {
                down.disabled = last === index;
            }
        });
    }

    // Moving the element drops the focus: it goes back to the button pressed, or to the other
    // arrow once the file reaches an end of the list and the button is disabled.
    keepFocus(button, item, other) {
        (button.disabled ? item.querySelector(`[data-action~="file-list#${other}"]`) : button)?.focus();
    }

    itemOf(event) {
        return event.currentTarget.closest('[data-file-list-target="item"]');
    }
}
