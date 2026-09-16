/*
 * Edytor układu sali — część przeglądarkowa (Etap 7, blok E).
 *
 * Alpine trzyma SZKIC układu: klikanie w kratkę zmienia go natychmiast, bez
 * żądania do serwera. Zapis wysyła cały układ do HallLayoutEditor::save(),
 * gdzie HallLayoutService sprawdza wszystko jeszcze raz. Reguły tutaj służą
 * wygodzie (od razu widać, że podwójne miejsce się nie zmieści), nie bezpieczeństwu.
 *
 * Format miejsca: { id, x, y, type: 'standard'|'double'|'accessible', category_id, active, label }
 */
window.hallLayoutEditor = function (config) {
    return {
        mode: config.mode,
        seats: config.seats,
        categories: config.categories,
        categoryId: config.categoryId,
        maxRows: config.maxRows,
        maxColumns: config.maxColumns,
        tool: config.mode === 'full' ? 'standard' : 'category',
        extraRows: 0,
        extraColumns: 0,
        hint: '',
        saving: false,

        init() {
            this.relabel();
        },

        get rows() {
            const used = Math.max(0, ...this.seats.map((s) => s.y));
            return Math.min(this.maxRows, Math.max(1, used + this.margin() + this.extraRows));
        },

        get columns() {
            const used = Math.max(0, ...this.seats.map((s) => s.x + (s.type === 'double' ? 1 : 0)));
            return Math.min(this.maxColumns, Math.max(1, used + this.margin() + this.extraColumns));
        },

        margin() {
            return this.mode === 'full' ? 1 : 0;
        },

        get emptyCells() {
            if (this.mode !== 'full') {
                return [];
            }
            const cells = [];
            for (let y = 1; y <= this.rows; y++) {
                for (let x = 1; x <= this.columns; x++) {
                    if (!this.seatAt(x, y)) {
                        cells.push({ x, y, key: x + ':' + y });
                    }
                }
            }
            return cells;
        },

        get activeCount() {
            return this.seats.filter((s) => s.active).length;
        },

        countIn(categoryId) {
            return this.seats.filter((s) => s.active && s.category_id === categoryId).length;
        },

        color(seat) {
            const category = this.categories.find((c) => c.id === seat.category_id);
            return category ? category.color : '#999999';
        },

        seatAt(x, y) {
            return this.seats.find((s) => s.y === y && (s.x === x || (s.type === 'double' && s.x + 1 === x)));
        },

        free(x, y, except) {
            if (x < 1 || x > this.maxColumns) {
                return false;
            }
            const seat = this.seatAt(x, y);
            return !seat || seat === except;
        },

        clickSeat(seat) {
            this.hint = '';
            switch (this.tool) {
                case 'standard':
                case 'accessible':
                case 'double':
                    return this.setType(seat, this.tool);
                case 'category':
                    seat.category_id = this.categoryId;
                    return;
                case 'toggle':
                    seat.active = !seat.active;
                    return;
                case 'erase':
                    if (this.mode === 'full') {
                        this.seats.splice(this.seats.indexOf(seat), 1);
                        this.relabel();
                    }
            }
        },

        clickEmpty(cell) {
            this.hint = '';
            if (!['standard', 'accessible', 'double'].includes(this.tool)) {
                return;
            }
            if (this.tool === 'double' && !this.free(cell.x + 1, cell.y, null)) {
                this.hint = 'Miejsce podwójne zajmuje dwie kratki — obok nie ma wolnej.';
                return;
            }
            this.seats.push({ id: null, x: cell.x, y: cell.y, type: this.tool, category_id: this.categoryId, active: true, label: '' });
            this.relabel();
        },

        setType(seat, type) {
            if (this.mode !== 'full' && (type === 'double' || seat.type === 'double')) {
                this.hint = 'W tej sali nie można zmieniać szerokości miejsc (historia sprzedaży albo nadchodzące seanse).';
                return;
            }
            if (type === 'double' && seat.type !== 'double' && !this.free(seat.x + 1, seat.y, seat)) {
                this.hint = 'Miejsce podwójne zajmuje dwie kratki — obok nie ma wolnej.';
                return;
            }
            seat.type = type;
        },

        // Rzędy i numery jak na serwerze (tylko podgląd w trybie pełnym): rzędy z miejscami
        // po y dostają kolejne litery, numery po x od lewej. W trybie ograniczonym etykiety
        // pochodzą z bazy i się nie zmieniają.
        relabel() {
            if (this.mode !== 'full') {
                return;
            }
            const rows = [...new Set(this.seats.map((s) => s.y))].sort((a, b) => a - b);
            rows.forEach((y, index) => {
                this.seats
                    .filter((s) => s.y === y)
                    .sort((a, b) => a.x - b.x)
                    .forEach((s, n) => { s.label = String.fromCharCode(65 + index) + (n + 1); });
            });
        },

        async generate() {
            const seats = await this.$wire.generate();
            if (Array.isArray(seats)) {
                this.seats = seats;
                this.extraRows = 0;
                this.extraColumns = 0;
                this.relabel();
            }
        },

        async save() {
            this.saving = true;
            try {
                await this.$wire.save(this.seats.map(({ id, x, y, type, category_id, active }) => ({ id, x, y, type, category_id, active })));
            } finally {
                this.saving = false;
            }
        },

        loaded(detail) {
            this.mode = detail.mode;
            this.seats = detail.seats;
            this.extraRows = 0;
            this.extraColumns = 0;
            if (this.mode !== 'full' && ['double', 'erase'].includes(this.tool)) {
                this.tool = 'category';
            }
        },
    };
};
