// Figure reveal (R-51): the foot's counts run up once on arrival and always end on the true value.
// The server already printed the true number, so no script means no change; reduced motion skips it.
document.addEventListener('DOMContentLoaded', () => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

    const fmt = new Intl.NumberFormat('en-US')

    document.querySelectorAll('[data-countup]').forEach((el) => {
        const text = el.textContent.trim()
        const end = parseInt(text.replace(/\D/g, ''), 10)

        if (!Number.isFinite(end) || end < 2) return

        const t0 = performance.now()
        const dur = 600
        const step = (now) => {
            const p = Math.min(1, (now - t0) / dur)
            el.textContent = p < 1 ? fmt.format(Math.round(end * (1 - Math.pow(1 - p, 3)))) : text
            if (p < 1) requestAnimationFrame(step)
        }

        requestAnimationFrame(step)
    })
})
