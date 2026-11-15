// Realtime (Reverb) is optional. A build or an environment with no VITE_REVERB_APP_KEY used to throw
// "You must pass your app key when you instantiate Pusher" on every page; now it connects only when a key is present,
// and a connection that cannot be set up never stops the rest of the page's script.
export function realtimeConfig(env = {}) {
    const key = typeof env.VITE_REVERB_APP_KEY === 'string' ? env.VITE_REVERB_APP_KEY.trim() : ''

    if (key === '') {
        return null
    }

    const host = location.hostname

    return {
        broadcaster: 'reverb',
        key,
        wsHost: env.VITE_REVERB_HOST ?? `ws.${host}`,
        wsPort: env.VITE_REVERB_PORT ?? 80,
        wssPort: env.VITE_REVERB_PORT ?? 443,
        forceTLS: (env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    }
}

export function connectRealtime(env, Echo, Pusher, target = globalThis) {
    const config = realtimeConfig(env)

    if (config === null) {
        return null
    }

    try {
        target.Pusher = Pusher
        target.Echo = new Echo(config)

        return target.Echo
    } catch (e) {
        target.Echo = undefined

        return null
    }
}
