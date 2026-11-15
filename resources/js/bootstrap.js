import _ from 'lodash'
import axios from 'axios'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { connectRealtime } from './realtime'

window._ = _
window.axios = axios
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// Quiet when no Reverb key is configured (see realtime.js).
connectRealtime(import.meta.env, Echo, Pusher, window)
