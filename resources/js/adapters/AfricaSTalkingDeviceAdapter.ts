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
  private listeners: Map<string, Set<EventHandler>> = new Map();

  isRegistered(): boolean {
    return this.registered;
  }

  async initialize(token: string): Promise<void> {
    if (this.client) this.destroy();

    console.info('[AT] initialising client', { tokenPrefix: token.slice(0, 10) });
    const AT = await loadAtSdk();
    this.client = new AT.Client(token);
    this.registered = false;

    await new Promise<void>((resolve, reject) => {
      const timeout = window.setTimeout(
        () => {
          console.error('[AT] client did not become ready within 20s');
          reject(new Error('Africa\'s Talking softphone did not become ready'));
        },
        20000,
      );

      const ready = () => {
        window.clearTimeout(timeout);
        this.registered = true;
        console.info('[AT] client ready — calls can be placed');
        this.emit('registered');
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
        if (this.activeCall) this.activeCall.status = 'dialing';
        this.emit('calling');
      }, false);

      this.client.on('error', (error: any) => {
        console.error('[AT] client error:', error);
        if (this.activeCall) this.activeCall.status = 'error';
        this.emit('error', error);
      }, false);

      this.client.on('callaccepted', () => {
        if (this.activeCall) this.activeCall.status = 'connected';
        this.emit('callaccepted');
      }, false);

      this.client.on('hangup', (cause: any) => {
        const call = this.activeCall;
        this.activeCall = null;
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
