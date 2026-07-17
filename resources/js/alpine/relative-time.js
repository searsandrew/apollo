const RECENT_UPDATE_INTERVAL_MS = 10000;
const DEFAULT_UPDATE_INTERVAL_MS = 60000;
const JUST_NOW_THRESHOLD_SECONDS = 5;
const SECONDS_PER_MINUTE = 60;
const MINUTES_PER_HOUR = 60;
const HOURS_PER_DAY = 24;
const DAYS_PER_MONTH = 30;
const DAYS_PER_YEAR = 365;

export default function relativeTime({ timestamp, fallback = 'just now' } = {}) {
    return {
        date: new Date(timestamp),
        fallback,
        formatter: null,
        label: fallback,
        timer: null,

        init() {
            this.formatter = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, {
                numeric: 'always',
            });

            this.update();
            this.schedule();
        },

        destroy() {
            clearTimeout(this.timer);
        },

        isValidDate() {
            return ! Number.isNaN(this.date.getTime());
        },

        elapsedSeconds() {
            if (! this.isValidDate()) {
                return 0;
            }

            return Math.max(0, Math.floor((Date.now() - this.date.getTime()) / 1000));
        },

        schedule() {
            if (! this.isValidDate()) {
                return;
            }

            clearTimeout(this.timer);

            this.timer = setTimeout(() => {
                this.update();
                this.schedule();
            }, this.elapsedSeconds() < SECONDS_PER_MINUTE ? RECENT_UPDATE_INTERVAL_MS : DEFAULT_UPDATE_INTERVAL_MS);
        },

        update() {
            if (! this.isValidDate()) {
                this.label = this.fallback;

                return;
            }

            this.label = this.format(this.elapsedSeconds());
        },

        format(seconds) {
            if (seconds < JUST_NOW_THRESHOLD_SECONDS) {
                return 'just now';
            }

            if (seconds < SECONDS_PER_MINUTE) {
                return this.formatter.format(-seconds, 'second');
            }

            const minutes = Math.floor(seconds / SECONDS_PER_MINUTE);

            if (minutes < MINUTES_PER_HOUR) {
                return this.formatter.format(-minutes, 'minute');
            }

            const hours = Math.floor(minutes / MINUTES_PER_HOUR);

            if (hours < HOURS_PER_DAY) {
                return this.formatter.format(-hours, 'hour');
            }

            const days = Math.floor(hours / HOURS_PER_DAY);

            if (days < DAYS_PER_MONTH) {
                return this.formatter.format(-days, 'day');
            }

            if (days < DAYS_PER_YEAR) {
                return this.formatter.format(-Math.floor(days / DAYS_PER_MONTH), 'month');
            }

            return this.formatter.format(-Math.floor(days / DAYS_PER_YEAR), 'year');
        },
    };
}
