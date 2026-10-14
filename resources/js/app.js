// Alpine components of the workspace shell (decisions.md D-040). Alpine comes
// with Livewire; these register before it starts. Everything kept in the
// browser is a convenience (widths, tabs) and survives a failing localStorage.

const read = (key, fallback) => {
    try {
        const value = localStorage.getItem(key)
        return value === null ? fallback : JSON.parse(value)
    } catch (e) {
        return fallback
    }
}
const write = (key, value) => {
    try {
        localStorage.setItem(key, JSON.stringify(value))
    } catch (e) {}
}
const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

// Drags a handle; calls onMove(clientX, clientY) and onEnd(). Text selection
// and transitions are off while dragging (html.resizing in app.css); rows =
// an up/down divider (row-resize cursor).
const drag = (event, onMove, onEnd, rows = false) => {
    if (event.button !== 0) return
    event.preventDefault()
    const root = document.documentElement
    root.classList.add('resizing', ...(rows ? ['resizing-rows'] : []))
    const move = (e) => onMove(e.clientX, e.clientY)
    const up = () => {
        removeEventListener('pointermove', move)
        removeEventListener('pointerup', up)
        root.classList.remove('resizing', 'resizing-rows')
        onEnd()
    }
    addEventListener('pointermove', move)
    addEventListener('pointerup', up)
}

document.addEventListener('alpine:init', () => {
    // Side panel width: drag its edge; dragging it almost shut closes it.
    window.Alpine.data('panelResize', () => ({
        width: clamp(read('panel-width', 288), 200, 560),
        closing: false,
        start(event) {
            const left = this.$refs.panel.getBoundingClientRect().left
            const before = this.width
            drag(event, (x) => {
                this.closing = x - left < 150
                this.width = clamp(x - left, 200, 560)
            }, () => {
                if (this.closing) {
                    this.closing = false
                    this.width = before
                    document.querySelector('[data-panel-toggle]')?.click()
                } else {
                    write('panel-width', this.width)
                }
            })
        },
        nudge(step) {
            this.width = clamp(this.width + step, 200, 560)
            write('panel-width', this.width)
        },
    }))

    // Request above response: drag the divider to set the height (px) of the
    // request's tab area ([data-stack-body]); the header above it never shrinks.
    window.Alpine.data('stackPane', (key, initial = 280) => ({
        height: clamp(read(key, initial), 80, 2000),
        max() {
            return Math.max(80, Math.round(window.innerHeight * 0.85))
        },
        start(event) {
            const from = event.clientY - this.height
            drag(event, (x, y) => {
                this.height = clamp(y - from, 80, this.max())
            }, () => write(key, Math.round(this.height)), true)
        },
        nudge(step) {
            this.height = clamp(this.height + step, 80, this.max())
            write(key, Math.round(this.height))
        },
        reset() {
            this.height = initial
            write(key, initial)
        },
    }))

    // <x-dropdown>: a styled single select (resources/views/components/dropdown.blade.php).
    // The list is teleported to <body> and placed under the button (or above it
    // when there is no room below); it closes on scroll and resize.
    window.Alpine.data('dropdown', (items, placeholder, id, align = 'left') => ({
        items,
        placeholder,
        value: null,
        open: false,
        active: -1,
        position: '',
        get current() {
            return this.items.find((item) => this.same(item.value)) ?? null
        },
        same(value) {
            return this.value !== null && this.value !== undefined && String(value) === String(this.value)
        },
        list() {
            return document.getElementById(id + '-list')
        },
        place() {
            const r = this.$refs.button.getBoundingClientRect()
            const below = window.innerHeight - r.bottom
            const vertical = below < 220 && r.top > below
                ? 'bottom: ' + (window.innerHeight - r.top + 4) + 'px; max-height: ' + Math.min(288, r.top - 8) + 'px;'
                : 'top: ' + (r.bottom + 4) + 'px; max-height: ' + Math.min(288, below - 8) + 'px;'
            const horizontal = align === 'right'
                ? 'right: ' + (window.innerWidth - r.right) + 'px;'
                : 'left: ' + r.left + 'px;'
            this.position = vertical + ' ' + horizontal + ' min-width: ' + r.width + 'px;'
        },
        toggle() {
            this.open ? this.close(true) : this.show(0)
        },
        show(step) {
            const selected = this.items.findIndex((item) => this.same(item.value))
            this.active = selected >= 0 ? selected : step < 0 ? this.items.length - 1 : 0
            this.place()
            this.open = true
            this.$nextTick(() => {
                if (! this.open) return // closed again before this ran (fast Esc)
                this.list()?.focus()
                this.list()?.querySelectorAll('[role=option]')[this.active]?.scrollIntoView({ block: 'nearest' })
            })
        },
        move(step) {
            this.active = (this.active + step + this.items.length) % this.items.length
            this.$nextTick(() => this.list()?.querySelectorAll('[role=option]')[this.active]?.scrollIntoView({ block: 'nearest' }))
        },
        choose(item) {
            if (! item) return
            this.value = item.value
            this.close(true)
        },
        close(focus = false) {
            if (! this.open) return
            this.open = false
            if (focus) this.$refs.button.focus()
        },
    }))

    // Open tabs, Postman-style: pages announce themselves with a `tab-open`
    // window event ({key, kind, label, method?, url, collection?, request?,
    // pane?, unsaved?}); the bar remembers them per workspace.
    window.Alpine.data('workspaceTabs', (workspace, home) => ({
        key: 'tabs:' + workspace,
        tabs: read('tabs:' + workspace, []),
        active: null,
        init() {
            this.$nextTick(() => this.reveal())
        },
        save() {
            write(this.key, this.tabs.slice(-30))
        },
        open(tab) {
            const index = this.tabs.findIndex((t) => t.key === tab.key)
            if (index === -1) this.tabs.push(tab)
            else this.tabs[index] = { ...this.tabs[index], ...tab }
            this.active = tab.key
            this.save()
            this.$nextTick(() => this.reveal())
        },
        go(tab) {
            const page = window.__workbench
            if (page && tab.collection === page.collection) {
                if (tab.kind === 'request') {
                    window.dispatchEvent(new CustomEvent('open-request', { detail: { id: tab.request } }))
                    return
                }
                window.dispatchEvent(new CustomEvent('open-pane', { detail: { pane: tab.pane } }))
                return
            }
            window.location.href = tab.url
        },
        close(tab) {
            const index = this.tabs.findIndex((t) => t.key === tab.key)
            if (index === -1) return
            if (tab.key === this.active && tab.unsaved && ! confirm('Close this tab and discard your unsaved changes?')) return
            this.tabs.splice(index, 1)
            this.save()
            if (tab.key !== this.active) return
            const next = this.tabs[index] ?? this.tabs[index - 1]
            if (next) this.go(next)
            else window.location.href = home
        },
        // `tab-gone` ({keys?, prefixes?}): the thing behind these tabs was deleted.
        forget({ keys = [], prefixes = [] }) {
            this.tabs = this.tabs.filter((t) => ! keys.includes(t.key) && ! prefixes.some((p) => t.key.startsWith(p)))
            this.save()
        },
        closeOthers() {
            this.tabs = this.tabs.filter((t) => t.key === this.active)
            this.save()
        },
        closeAll() {
            const current = this.tabs.find((t) => t.key === this.active)
            if (current?.unsaved && ! confirm('Close all tabs and discard your unsaved changes?')) return
            this.tabs = []
            this.save()
            window.location.href = home
        },
        scroll(direction) {
            const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches
            this.$refs.strip.scrollBy({ left: direction * 240, behavior: reduced ? 'auto' : 'smooth' })
        },
        reveal() {
            this.$refs.strip?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
        },
    }))
})
