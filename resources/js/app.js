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

// Drags a vertical handle; calls onMove(clientX) and onEnd(). Text selection
// and transitions are off while dragging (html.resizing in app.css).
const drag = (event, onMove, onEnd) => {
    if (event.button !== 0) return
    event.preventDefault()
    const root = document.documentElement
    root.classList.add('resizing')
    const move = (e) => onMove(e.clientX)
    const up = () => {
        removeEventListener('pointermove', move)
        removeEventListener('pointerup', up)
        root.classList.remove('resizing')
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

    // Two panes side by side with a draggable divider; size = left pane in %.
    window.Alpine.data('splitPane', (key, initial = 55) => ({
        size: clamp(read(key, initial), 25, 75),
        start(event) {
            const box = this.$root.getBoundingClientRect()
            drag(event, (x) => {
                this.size = clamp(((x - box.left) / box.width) * 100, 25, 75)
            }, () => write(key, Math.round(this.size)))
        },
        nudge(step) {
            this.size = clamp(this.size + step, 25, 75)
            write(key, Math.round(this.size))
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
            this.$refs.strip.scrollBy({ left: direction * 240, behavior: 'smooth' })
        },
        reveal() {
            this.$refs.strip?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
        },
    }))
})
