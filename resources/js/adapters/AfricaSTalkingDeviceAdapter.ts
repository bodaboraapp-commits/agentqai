/**
 * Africa's Talking browser-softphone adapter.
 *
 * AT's client SDK is a prebuilt browser bundle; it is loaded from AT's CDN on
 * demand (the package's own README documents the global `Africastalking` and
 * the `<script>` include) instead of being bundled into every page. A
 * capability token — minted server-side by AfricaSTalkingWebRTCTokenProvider —
 * is all the browser receives; the API key never leaves the server.
 *
 * SDK reference: https://unpkg.com/africastalking-client@1.0.6/build/africastalking.js
 * Methods: Client(token), client.call/answer/hangup/dtmf/mute/unmute/hold/unhold/destroy
 * Events: ready, calling, incomingcall, callaccepted, hangup, offline, closed
 */
import axios from 'axios';
import type { VoiceDeviceAdapter, VoiceCall, CallStatus } from '@/types/telephony';

type EventHandler = (...args: unknown[]) => void;

// AT's browser SDK, vendored from the CDN build their README documents
// (https://unpkg.com/africastalking-client@1.0.8/build/africastalking.js).
// Bundling it removes the third-party CDN/CSP dependency that could silently
// prevent the client from loading at all — in which case the browser never
// dials and the AT dashboard shows no session.
// @ts-expect-error - Vite asset-URL import; the SDK ships no type declarations
import atSdkAssetUrl from '@/vendor/africastalking.js?url';

const AT_SDK_CDN_FALLBACK = 'https://unpkg.com/africastalking-client@1.0.8/build/africastalking.js';

/** The SDK is a UMD bundle that publishes itself as window.Africastalking. */
let sdkPromise: Promise<any> | null = null;

function injectSdk(url: string): Promise<any> {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.onload = () => {
      console.info('[AT] SDK script loaded:', url);
      const sdk = (window as any).Africastalking;
      if (sdk) resolve(sdk);
      else reject(new Error('Africa\'s Talking SDK loaded without a global'));
    };
    script.onerror = () => reject(new Error('Could not load the Africa\'s Talking softphone SDK from '.concat(url)));
    document.head.appendChild(script);
  });
}

function loadAtSdk(): Promise<any> {
  const existing = (window as any).Africastalking;
  if (existing) return Promise.resolve(existing);
  if (sdkPromise) return sdkPromise;

  console.info('[AT] loading vendored SDK asset');
  sdkPromise = injectSdk(atSdkAssetUrl)
    .catch((firstError) => {
      console.warn('[AT] vendored SDK failed, trying CDN fallback:', firstError);
      return injectSdk(AT_SDK_CDN_FALLBACK);
    })
    .catch((error) => {
      console.error('[AT] SDK load failed:', error);
      sdkPromise = null;
      throw error;
    });

  return sdkPromise;
}

// ─── VoiceCall wrapper ──────────────────────────────────────────────────────

class AfricaSTalkingCall implements VoiceCall {
  readonly provider = 'africastalking' as const;
  status: CallStatus = 'dialing';
  id: string | null = null;
  appCallId: string | null = null;
  fromNumber: string | null = null;
  toNumber: string | null = null;

  private muted = false;
  private listeners: Map<string, Set<EventHandler>> = new Map();

  constructor(private readonly client: any) {}

  accept(): void {
    this.client.answer();
    this.status = 'connected';
    this.emit('accept');
  }

  reject(): void {
    try {
      this.client.hangup();
    } finally {
      this.status = 'ended';
      this.emit('rejected');
    }
  }

  hangup(): void {
    try {
      this.client.hangup();
    } finally {
      this.status = 'ended';
      this.emit('disconnected');
    }
  }

  mute(shouldMute: boolean): void {
    if (shouldMute) this.client.mute();
    else this.client.unmute();
    this.muted = shouldMute;
  }

  isMuted(): boolean {
    return this.muted;
  }

  sendDigits(digits: string): void {
    this.client.dtmf(digits);
  }

  on(event: string, handler: EventHandler): void {
    if (!this.listeners.has(event)) this.listeners.set(event, new Set());
    this.listeners.get(event)!.add(handler);
  }

  removeAllListeners(): void {
    this.listeners.clear();
  }

  get nativeCall(): unknown {
    return this.client;
  }

  emit(event: string, ...args: unknown[]): void {
    this.listeners.get(event)?.forEach((fn) => {
      try {
        fn(...args);
      } catch (e) {
        console.error(`[AfricaSTalkingCall] Error in "${event}" handler:`, e);
      }
    });
  }
}

// ─── Device adapter ─────────────────────────────────────────────────────────

export class AfricaSTalkingDeviceAdapter implements VoiceDeviceAdapter {
  readonly provider = 'africastalking' as const;

  /**
   * AT emits `offline` only after the token has already expired, so the
   * consumer must schedule its own refresh from the token lifetime.
   */
  readonly signalsTokenExpiry = false;

  private client: any = null;
  private registered = false;
  private activeCall: AfricaSTalkingCall | null = null;
  private callStartedAt: number | null = null;
  private listeners: Map<string, Set<EventHandler>> = new Map();

  /**
   * Media diagnostics for "answers then drops after ~5s with no audio":
   * proves whether the browser can capture a mic at all (a blocked mic makes
   * AT's bridge tear the call down) and logs the permission/device state.
   */
  private async logAudioCapabilities(): Promise<void> {
    try {
      if (!navigator.mediaDevices?.getUserMedia) {
        console.warn('[AT] audio: getUserMedia unavailable in this browser/context');
        return;
      }

      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const tracks = stream.getAudioTracks();
      console.info(
        '[AT] audio: microphone OK',
        tracks.map((track) => ({
          label: track.label,
          enabled: track.enabled,
          muted: track.muted,
          state: track.readyState,
          settings: track.getSettings?.() ?? null,
        })),
      );
      tracks.forEach((track) => track.stop());
    } catch (error: any) {
      console.warn('[AT] audio: microphone NOT available — call audio will fail:', error?.name, error?.message);
    }
  }

  /**
   * After AT reports the call accepted (PSTN leg answered), check the SDK's
   * remote audio element. Browsers can leave it paused/muted, which is
   * indistinguishable from "no voice" to the user.
   */
  private async logRemoteAudioState(): Promise<void> {
    const audio = document.querySelector<HTMLAudioElement>('audio#remote-stream-audio, audio');
    if (!audio) {
      console.warn('[AT] audio: no remote <audio> element found after callaccepted');
      return;
    }

    try {
      if (audio.paused) await audio.play();
    } catch (error: any) {
      console.warn('[AT] audio: remote element could not play:', error?.name, error?.message);
    }

    console.info('[AT] audio: remote element', {
      paused: audio.paused,
      muted: audio.muted,
      volume: audio.volume,
      readyState: audio.readyState,
      srcObject: Boolean(audio.srcObject),
    });
  }

  isRegistered(): boolean {
    return this.registered;
  }

  async initialize(token: string): Promise<void> {
    if (this.client) this.destroy();

    console.info('[AT] initialising client', { tokenPrefix: token.slice(0, 10) });
    const AT = await loadAtSdk();
    // Self-hosted tones: the SDK's default ringing/dialing MP3s come from
    // res.cloudinary.com, which browser tracking-prevention blocks (and which
    // would be a third-party dependency anyway). Same-origin copies avoid both.
    this.client = new AT.Client(token, {
      sounds: {
        ringing: '/sounds/at-ringing.mp3',
        dialing: '/sounds/at-dialing.mp3',
      },
    });
    this.registered = false;

    await new Promise<void>((resolve, reject) => {
      const timeout = window.setTimeout(
        () => {
          console.error(
            '[AT] client did not become ready within 20s — AT WebRTC gateway ' +
            '(wss://webrtc.africastalking.com/connect) is not accepting the client. ' +
            'Check the deploy-check "softphone" block: a 500 handshake means the AT gateway is down, not your browser.',
          );
          reject(new Error(
            'Africa\'s Talking WebRTC gateway is not accepting the client (wss://webrtc.africastalking.com/connect). ' +
            'Use a SIP softphone/campaigns while AT fixes the gateway, or retry.',
          ));
        },
        20000,
      );

      const ready = () => {
        window.clearTimeout(timeout);
        this.registered = true;
        console.info('[AT] client ready — calls can be placed');
        this.emit('registered');
        void this.logAudioCapabilities();
        resolve();
      };

      this.client.on('ready', ready, false);

      this.client.on('offline', () => {
        console.warn('[AT] client offline (token expired or socket lost)');
        this.registered = false;
        this.emit('tokenExpiring');
        this.emit('unregistered');
      }, false);

      this.client.on('closed', () => {
        console.warn('[AT] client socket closed');
        this.registered = false;
        this.emit('unregistered');
      }, false);

      this.client.on('incomingcall', (params: any) => {
        const call = new AfricaSTalkingCall(this.client);
        call.status = 'ringing';
        call.fromNumber = params?.from ?? params?.callerNumber ?? null;
        call.toNumber = params?.to ?? params?.destinationNumber ?? null;
        this.activeCall = call;
        this.emit('incoming', call);
      }, false);

      this.client.on('calling', () => {
        console.info('[AT] calling — waiting for the AT network to answer/route the call');
        if (this.activeCall) this.activeCall.status = 'dialing';
        this.emit('calling');
      }, false);

      this.client.on('notready', () => {
        console.warn('[AT] client notready — registration lost');
        this.registered = false;
        this.emit('unregistered');
      }, false);

      this.client.on('missedcall', (info: any) => {
        console.warn('[AT] missedcall:', info);
        this.emit('missedcall', info);
      }, false);

      this.client.on('error', (error: any) => {
        console.error('[AT] client error:', error);
        if (this.activeCall) this.activeCall.status = 'error';
        this.emit('error', error);
      }, false);

      this.client.on('callaccepted', () => {
        console.info('[AT] callaccepted');
        if (this.activeCall) this.activeCall.status = 'connected';
        this.emit('callaccepted');
        void this.logRemoteAudioState();
      }, false);

      this.client.on('hangup', (cause: any) => {
        // AT sends {code, reason} here. This is the authoritative diagnosis
        // for a call that never rings the destination: 404/403/480/486-style
        // codes point at routing/account/number config, local ones at media.
        console.warn('[AT] hangup:', cause ? JSON.stringify(cause) : '(no cause)', cause?.code, cause?.reason);
        const call = this.activeCall;
        this.activeCall = null;

        // AT does not reliably send a status callback for the client's own
        // call record, so report the outcome to the app: without this the Call
        // row created by /calls/initiate stays "initiated" and never shows up
        // as completed in Call Logs.
        if (call?.appCallId) {
          const durationSeconds = this.callStartedAt === null
            ? undefined
            : Math.max(0, Math.round((Date.now() - this.callStartedAt) / 1000));

          void axios.post('/api/africastalking/call-completed', {
            call_id: Number(call.appCallId),
            // AT 200 "Session Terminated" is a normal hang-up; anything else
            // is recorded as a failed call with the provider's reason.
            status: cause?.code != null && Number(cause.code) !== 200 ? 'failed' : 'completed',
            duration_seconds: durationSeconds,
            hangup_reason: typeof cause?.reason === 'string' ? cause.reason : undefined,
            hangup_code: cause?.code != null ? String(cause.code) : undefined,
          }).catch((error: any) => {
            // Call logging must never break the softphone UI, but a silent
            // failure here turns into "calls not logged" with no clue.
            console.warn(
              '[AT] call-completed report failed:',
              error?.response?.status ?? error?.message ?? error,
            );
          });
        }

        this.callStartedAt = null;

        if (call) {
          call.status = 'ended';
          call.emit('disconnected', cause);
        }
        this.emit('hangup', cause);
      }, false);
    });
  }

  destroy(): void {
    if (this.client) {
      try {
        this.client.destroy();
      } catch {
        // ignore cleanup errors
      }
      this.client = null;
    }
    this.registered = false;
    this.activeCall = null;
    this.listeners.clear();
  }

  async updateToken(token: string): Promise<void> {
    // AT has no updateToken: a refresh means rebuilding the client, which is
    // not safe during a call.
    if (this.hasActiveCall()) {
      throw new Error('Cannot refresh the Africa\'s Talking token during a call');
    }

    this.destroy();
    await this.initialize(token);
  }

  hasActiveCall(): boolean {
    return this.activeCall !== null && this.activeCall.status !== 'ended';
  }

  async makeCall(to: string, params?: Record<string, string>): Promise<VoiceCall> {
    if (!this.client) {
      throw new Error('Africa\'s Talking client not initialised');
    }

    console.info('[AT] dialling', to, { registered: this.registered });

    try {
      this.client.call(to);
      console.info('[AT] call() accepted by SDK — if the AT dashboard shows no session, the SDK/client is not registered on the AT network');
    } catch (e) {
      console.error('[AT] call() threw:', e);
      this.emit('error', e);
      throw e;
    }

    const call = new AfricaSTalkingCall(this.client);
    call.toNumber = to;
    call.status = 'dialing';
    this.activeCall = call;
    this.callStartedAt = Date.now();

    // The softphone passes the app Call id as `call_id`; keep it for
    // disposition/call-log correlation (AT itself does not carry it).
    const appCallId = params?.call_id ?? params?.appCallId;
    if (appCallId) call.appCallId = appCallId;

    return call;
  }

  on(event: string, handler: EventHandler): void {
    if (!this.listeners.has(event)) this.listeners.set(event, new Set());
    this.listeners.get(event)!.add(handler);
  }

  removeAllListeners(): void {
    this.listeners.clear();
  }

  private emit(event: string, ...args: unknown[]): void {
    this.listeners.get(event)?.forEach((fn) => {
      try {
        fn(...args);
      } catch (e) {
        console.error(`[AfricaSTalkingAdapter] Error in "${event}" handler:`, e);
      }
    });
  }
}
