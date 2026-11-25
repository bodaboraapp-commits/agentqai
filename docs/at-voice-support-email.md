# Africa's Talking Support Email (Voice / SIP / WebRTC)

**To:** voice@africastalking.com
**Subject:** SIP REGISTER still returns 403 with renewed SIP passwords (AgentQ_AI)

---

Hi AT Voice team,

We renewed the SIP passwords for both SIP phones (`agent` and `agent1`) and tested once each, using the exact passwords displayed in Voice > Phones.

## Results

- Transports tested:
  - SIP over TCP `ke.sip.africastalking.com:5060`
  - SIP over WSS `wss://ke.sip.africastalking.com/ws` with `Sec-WebSocket-Protocol: sip`
- Usernames tried:
  - `agent1.AgentQ_AI`
  - `agent1.AgentQ_AI@ke.sip.africastalking.com`
- Both receive the Digest challenge:
  - `SIP/2.0 401 Unauthorized`
  - `realm="ke.sip.africastalking.com"`
- The authenticated REGISTER then returns:
  - `SIP/2.0 403 Forbidden`
- The same `403 Forbidden` happens for `agent.AgentQ_AI`.

We stopped after one attempt per account to avoid an IP block from repeated failed authentication.

## Questions

1. Please confirm the exact **auth username** format for these SIP phones.
2. Is SIP REGISTER **IP restricted or allow listed** for SIP phones? If yes, please allow list:
   - Production server: `162.0.209.206`
   - Tell us which IPs are currently permitted.
3. Are the SIP phones active under the **production app** and not only in sandbox?
4. If our earlier failed attempts caused an IP block, please unblock us. We have stopped retrying.

## WebRTC gateway

Separately, the WebRTC gateway still fails:

- SDK: `africastalking-client@1.0.8` (latest npm)
- Capability token: `POST https://webrtc.africastalking.com/capability-token/request` returns **201** with `clientName: user_1`, `incoming: true`, `outgoing: true`
- WebSocket: `GET wss://webrtc.africastalking.com/connect` with `Upgrade: websocket`, `Sec-WebSocket-Version: 13`, `Sec-WebSocket-Protocol: janus-protocol`, `Origin: https://agentqai.cx` returns:
  - `HTTP/1.1 500 Internal Server Error`
  - `{"errorMessage":"There was an internal server error."}`
- A plain `GET /connect` also returns 500. `/connect/` and `/janus` return 404.
- Reproduced from two independent networks.

Please check the WebRTC gateway for app `AgentQ_AI`, confirm that WebRTC client access is enabled for the production app, and advise the correct path or subprotocol for SDK 1.0.8.

## Our Voice XML (ready, matches Dial documentation)

```xml
<Response>
  <Dial sequential="true" record="true"
        phoneNumbers="agent1.AgentQ_AI@ke.sip.africastalking.com,agent.AgentQ_AI@ke.sip.africastalking.com,AgentQ_AI.user_1" />
</Response>
```

Once SIP registration or the WebRTC gateway works, this XML will ring both SIP agents and the browser client.

## Reference

- Dial: https://developers.africastalking.com/docs/voice/actions/dial
- WebRTC usage: https://developers.africastalking.com/docs/voice/webRTC_client/usage

Regards,
Edward
AgentQ AI
+254 756 112 633
