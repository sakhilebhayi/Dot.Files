import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.location = { hostname: 'files.example.test' }
const { realtimeConfig, connectRealtime } = await import('../../resources/js/realtime.js')

class FakeEcho {
    constructor(config) {
        if (!config.key) throw new Error('You must pass your app key when you instantiate Pusher.')
        this.config = config
    }
}

test('no key: no connection, nothing thrown, nothing on the window', () => {
    for (const env of [{}, { VITE_REVERB_APP_KEY: '' }, { VITE_REVERB_APP_KEY: '   ' }, { VITE_REVERB_APP_KEY: undefined }]) {
        const target = {}
        assert.equal(realtimeConfig(env), null)
        assert.equal(connectRealtime(env, FakeEcho, {}, target), null)
        assert.equal(target.Echo, undefined)
    }
})

test('a key connects with the same options as before', () => {
    const target = {}
    const echo = connectRealtime({ VITE_REVERB_APP_KEY: 'abc', VITE_REVERB_PORT: 8080, VITE_REVERB_SCHEME: 'http' }, FakeEcho, { p: 1 }, target)
    assert.equal(echo, target.Echo)
    assert.deepEqual(target.Echo.config, {
        broadcaster: 'reverb', key: 'abc', wsHost: 'ws.files.example.test', wsPort: 8080, wssPort: 8080, forceTLS: false, enabledTransports: ['ws', 'wss'],
    })
    assert.deepEqual(target.Pusher, { p: 1 })
})

test('a constructor that throws degrades to no realtime', () => {
    const target = {}
    class Broken { constructor() { throw new Error('boom') } }
    assert.equal(connectRealtime({ VITE_REVERB_APP_KEY: 'abc' }, Broken, {}, target), null)
    assert.equal(target.Echo, undefined)
})
