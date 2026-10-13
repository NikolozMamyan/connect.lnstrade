import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    disconnect() {
        this.hide();
    }

    show(event) {
        clearTimeout(this.hideTimeout);
        const trigger = event.currentTarget;

        if (this.trigger === trigger) {
            return;
        }

        this.hide();
        this.trigger = trigger;
        this.tooltip = trigger.nextElementSibling.content.firstElementChild.cloneNode(true);
        this.tooltip.style.visibility = 'hidden';
        this.tooltip.addEventListener('mouseenter', () => clearTimeout(this.hideTimeout));
        this.tooltip.addEventListener('mouseleave', () => this.scheduleHide());
        document.body.appendChild(this.tooltip);
        this.positionFrame = window.requestAnimationFrame(() => {
            this.positionFrame = null;
            this.position();
        });
    }

    position() {
        if (!this.trigger || !this.tooltip) {
            return;
        }

        const anchor = this.trigger.getBoundingClientRect();
        const table = this.element.getBoundingClientRect();

        if (anchor.right <= Math.max(0, table.left) || anchor.left >= Math.min(window.innerWidth, table.right)
            || anchor.bottom <= 0 || anchor.top >= window.innerHeight) {
            this.hide();
            return;
        }

        const popup = this.tooltip.getBoundingClientRect();
        const left = Math.min(Math.max(12, anchor.left + (anchor.width - popup.width) / 2), window.innerWidth - popup.width - 12);
        const top = anchor.bottom + popup.height + 10 <= window.innerHeight - 12
            ? anchor.bottom + 10
            : Math.max(12, anchor.top - popup.height - 10);

        this.tooltip.style.left = `${left}px`;
        this.tooltip.style.top = `${top}px`;
        this.tooltip.style.visibility = 'visible';
    }

    scheduleHide() {
        clearTimeout(this.hideTimeout);
        this.hideTimeout = window.setTimeout(() => {
            if (!this.tooltip?.matches(':hover') && !this.trigger?.matches(':focus')) {
                this.hide();
            }
        }, 160);
    }

    dismiss(event) {
        if (!this.trigger?.contains(event.target) && !this.tooltip?.contains(event.target)) {
            this.hide();
        }
    }

    hide() {
        clearTimeout(this.hideTimeout);
        window.cancelAnimationFrame(this.positionFrame);
        this.positionFrame = null;
        this.tooltip?.remove();
        this.tooltip = null;
        this.trigger = null;
    }
}
