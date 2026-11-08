import{b as I,r as p,j as e,H as A,L as k,a as T}from"./app-BWge_6Pm.js";import{A as P,d as E,S as U,a as M,c as L,b as m}from"./app-layout-ng_sO5Wc.js";import{H}from"./heading-633svn9X.js";import{B as g}from"./button-CaR8LIkX.js";import{C as y,c as x,b as f,d as b,a as v}from"./card-DNx3hALU.js";import{I as d}from"./input-D1R8choK.js";import{L as o}from"./label-BxnPV_p4.js";import{S as F}from"./switch-BiggEFN2.js";import{C as q}from"./checkbox-C-B-XvgO.js";import{d as O}from"./index-DNlW8NNM.js";import{P as D}from"./page-help-DWRWvkkh.js";import{u as Y}from"./useTranslation-ClIo4fYC.js";import{A as K}from"./arrow-left-aBjx-3IX.js";/* empty css            */import"./index-DM_us9Rv.js";import"./index-DC-FHNLG.js";import"./createLucideIcon-DXkjkHXn.js";import"./badge-CT0z2oRr.js";import"./minus-Dks8jJft.js";import"./app-logo-icon-DTdfJGQ3.js";import"./brand-logo-BaZA2nXC.js";import"./telephony-6oDxtEaD.js";import"./phone-4mtEr4Rx.js";import"./users-Foo6tdfu.js";import"./clock-DMtXalaR.js";import"./user-cog-DpBo3kpB.js";import"./use-currency-CAyoQGDX.js";import"./check-B1KZ_W4-.js";import"./alert-BL-Enhm-.js";import"./circle-alert-CD3YDOqX.js";import"./circle-check-Kx_ofeHA.js";import"./loader-circle-CyyEgM3V.js";import"./circle-help-D4Ew6flI.js";import"./external-link-C1OUOzim.js";try{(function(){var s=typeof window<"u"?window:typeof global<"u"?global:typeof globalThis<"u"?globalThis:typeof self<"u"?self:{},r=new s.Error().stack;r&&(s._sentryDebugIds=s._sentryDebugIds||{},s._sentryDebugIds[r]="1c958a82-8844-4374-8c22-b458b163ef86",s._sentryDebugIdIdentifier="sentry-dbid-1c958a82-8844-4374-8c22-b458b163ef86")})()}catch{}const B=[{value:"call_completed",label:"Call Completed",description:"Triggered when a call ends with transcript and sentiment data"},{value:"lead_qualified",label:"Lead Qualified",description:"Triggered when AI detects a hot lead (high sentiment/score)"},{value:"contact_added",label:"Contact Added",description:"Triggered when a new contact is created or imported"},{value:"contact_updated",label:"Contact Updated",description:"Triggered when contact information is modified"},{value:"campaign_started",label:"Campaign Started",description:"Triggered when a campaign is launched"},{value:"dtmf_response",label:"DTMF Response",description:"Triggered when a contact presses a phone key"}];function we({integration:s}){const{t:r}=Y(),{name:u}=I().props,i=!!s,[a,n]=p.useState({name:s?.name||"",webhook_url:s?.webhook_url||"",events:s?.events||[],auth_type:s?.auth_type||"none",auth_credentials:s?.auth_credentials||{},is_active:s?.is_active!==void 0?s.is_active:!0}),[l,_]=p.useState({}),[j,C]=p.useState(!1),w=async t=>{t.preventDefault(),C(!0),_({});try{const h=await(await fetch(i?`/api/v1/crm-integrations/${s.id}`:"/api/v1/crm-integrations",{method:i?"PUT":"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":document.querySelector('meta[name="csrf-token"]')?.getAttribute("content")||""},body:JSON.stringify(a)})).json();h.success?T.visit("/integrations",{onSuccess:()=>{alert(`Integration ${i?"updated":"created"} successfully!`)}}):h.errors&&_(h.errors)}catch(c){alert("Failed to save integration: "+c.message)}finally{C(!1)}},S=t=>{n(c=>({...c,events:c.events.includes(t)?c.events.filter(h=>h!==t):[...c.events,t]}))},N=[{title:r("common.dashboard"),href:O().url},{title:r("integrations.crm_integrations"),href:"/integrations"},{title:i?"Edit Integration":"Create Integration",href:""}],R=[{title:"Creating an Integration",content:`1. Name: Give your integration a descriptive name (e.g., "ERPNext Production", "Salesforce CRM")
2. Webhook URL: Your CRM's endpoint that will receive data (must accept POST requests)
3. Events: Select which events should trigger webhooks to your CRM
4. Authentication: Choose the method your CRM requires
5. Test: Always test before activating to verify connectivity`},{title:"Webhook URL Setup",content:`Your CRM must have an endpoint that:
• Accepts HTTP POST requests
• Returns 200 OK status on success
• Processes JSON payload data
• Responds within 30 seconds

Example URLs:
• ERPNext: https://your-erpnext.com/api/method/your_app.api.agentq_webhook
• Salesforce: https://yourinstance.salesforce.com/services/apexrest/agentq
• Custom: https://your-system.com/webhooks/${u.toLowerCase().replace(/\s+/g,"-")}`},{title:"Authentication Setup",content:`Bearer Token (Most Common):
• Used by: HubSpot, Salesforce, ERPNext, most OAuth 2.0 systems
• Format: Your access token or "token api_key:api_secret"
• Header sent: Authorization: Bearer YOUR_TOKEN

API Key:
• Used by: Custom systems, Pipedrive, some legacy APIs
• Header Name: Usually X-API-Key (or custom)
• Header Value: Your API key

HMAC Signature:
• Maximum security with cryptographic verification
• Secret: Shared secret key between systems
• Headers sent: X-AgentQ-Signature, X-AgentQ-Timestamp
• Your CRM must verify signature matches

None:
• For testing only - no authentication headers sent
• Never use in production environments`},{title:"Event Selection Guide",content:`Choose events based on your CRM needs:

Call Completed:
• Creates call logs in CRM
• Includes: transcript, duration, sentiment, recording URL
• Use for: Call history tracking, conversation analysis

Lead Qualified:
• Creates hot lead records automatically
• Includes: sentiment score (>0.8), qualification reason
• Use for: Sales alerts, opportunity creation

Contact Added/Updated:
• Syncs contacts in real-time
• Includes: name, phone, email, company
• Use for: CRM contact database sync

Campaign Started:
• Notifies when campaigns launch
• Includes: campaign details, target count
• Use for: CRM campaign tracking

DTMF Response:
• Tracks phone keypress responses
• Includes: key pressed, timestamp, call context
• Use for: IVR analytics, survey responses`},{title:"Testing Your Integration",content:`After creating:
1. Click "Test" button to send sample webhook
2. Check the response status and message
3. Verify data appears in your CRM
4. Review webhook logs for any errors
5. Retry failed webhooks if needed

Sample test payload sent:
{
  "event": "call_completed",
  "call_id": 999,
  "contact_name": "Test User",
  "phone_number": "+15551234567",
  "duration": 120,
  "status": "completed",
  "transcript": "This is a test webhook...",
  "sentiment": "positive"
}`},{title:"ERPNext Integration Example",content:`To integrate with ERPNext:

1. Create Python endpoint in ERPNext:
# frappe-bench/apps/your_app/api.py
@frappe.whitelist(allow_guest=False)
def agentq_webhook():
    data = frappe.request.get_json()
    if data.get('event') == 'call_completed':
        doc = frappe.get_doc({
            "doctype": "Call Log",
            "duration": data.get('duration'),
            "notes": data.get('transcript')
        })
        doc.insert()
    return {"status": "success"}

2. Get API credentials:
   User → API Access → Generate Keys

3. In ${u} form:
   • URL: https://your-erpnext.com/api/method/your_app.api.agentq_webhook
   • Auth: Bearer Token
   • Token: token YOUR_API_KEY:YOUR_API_SECRET`},{title:"Common Issues & Solutions",content:`Problem: "Connection Timeout"
Solution: Your CRM endpoint must respond within 30s. Use background jobs for long operations.

Problem: "401 Unauthorized"
Solution: Double-check API credentials. Test with curl manually first.

Problem: "Invalid SSL Certificate"
Solution: Ensure your CRM has valid HTTPS certificate. Use Let's Encrypt for free SSL.

Problem: "Webhooks not triggering"
Solution: Check integration is Active. Verify events are selected. Ensure Laravel queue is running.

Problem: "Wrong data format"
Solution: Check your CRM expects JSON payload. Review webhook logs for exact payload sent.`}];return e.jsxs(P,{breadcrumbs:N,children:[e.jsx(A,{title:i?"Edit Integration":"Create Integration"}),e.jsxs("div",{className:"mx-auto max-w-3xl space-y-6",children:[e.jsxs("div",{className:"flex items-center gap-4",children:[e.jsx(k,{href:"/integrations",children:e.jsx(g,{variant:"ghost",size:"icon",children:e.jsx(K,{className:"h-5 w-5"})})}),e.jsx("div",{className:"flex-1",children:e.jsx(H,{title:i?"Edit Integration":"Create CRM Integration",description:r("integrations.connect_agentq_to_your_crm_or_marketing_automatio",{appName:u})})}),e.jsx(D,{title:r("integrations.integration_form_help"),sections:R,documentationUrl:"/docs/documentation.html#crm-integrations"})]}),e.jsxs("form",{onSubmit:w,className:"space-y-6",children:[e.jsxs(y,{children:[e.jsxs(x,{children:[e.jsx(f,{children:r("integrations.basic_information")}),e.jsx(b,{children:"Configure the basic settings for your CRM integration"})]}),e.jsxs(v,{className:"space-y-4",children:[e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"name",children:"Integration Name"}),e.jsx(d,{id:"name",value:a.name,onChange:t=>n({...a,name:t.target.value}),placeholder:"e.g., HubSpot Production, Salesforce CRM",required:!0}),l.name&&e.jsx("p",{className:"text-sm text-red-600",children:l.name})]}),e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"webhook_url",children:"Webhook URL"}),e.jsx(d,{id:"webhook_url",type:"url",value:a.webhook_url,onChange:t=>n({...a,webhook_url:t.target.value}),placeholder:`https://your-crm.com/webhooks/${u.toLowerCase().replace(/\s+/g,"-")}`,required:!0}),e.jsx("p",{className:"text-sm text-muted-foreground",children:"The endpoint URL where webhook data will be sent"}),l.webhook_url&&e.jsx("p",{className:"text-sm text-red-600",children:l.webhook_url})]}),e.jsxs("div",{className:"flex items-center justify-between rounded-lg border p-4",children:[e.jsxs("div",{className:"space-y-0.5",children:[e.jsx(o,{htmlFor:"is_active",children:"Active Status"}),e.jsx("p",{className:"text-sm text-muted-foreground",children:"Enable or disable this integration"})]}),e.jsx(F,{id:"is_active",checked:a.is_active,onCheckedChange:t=>n({...a,is_active:t})})]})]})]}),e.jsxs(y,{children:[e.jsxs(x,{children:[e.jsx(f,{children:r("integrations.events_to_forward")}),e.jsx(b,{children:"Select which events should trigger webhooks to your CRM"})]}),e.jsxs(v,{className:"space-y-3",children:[B.map(t=>e.jsxs("div",{className:"flex items-start space-x-3 rounded-lg border p-4",children:[e.jsx(q,{id:t.value,checked:a.events.includes(t.value),onCheckedChange:()=>S(t.value)}),e.jsxs("div",{className:"flex-1 space-y-1",children:[e.jsx("label",{htmlFor:t.value,className:"cursor-pointer text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70",children:t.label}),e.jsx("p",{className:"text-sm text-muted-foreground",children:t.description})]})]},t.value)),l.events&&e.jsx("p",{className:"text-sm text-red-600",children:l.events})]})]}),e.jsxs(y,{children:[e.jsxs(x,{children:[e.jsx(f,{children:r("integrations.authentication")}),e.jsxs(b,{children:["Configure how ",u," authenticates with your webhook endpoint"]})]}),e.jsxs(v,{className:"space-y-4",children:[e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"auth_type",children:"Authentication Type"}),e.jsxs(E,{value:a.auth_type,onValueChange:t=>n({...a,auth_type:t,auth_credentials:{}}),children:[e.jsx(U,{id:"auth_type",children:e.jsx(M,{})}),e.jsxs(L,{children:[e.jsx(m,{value:"none",children:"None (No Authentication)"}),e.jsx(m,{value:"bearer",children:"Bearer Token"}),e.jsx(m,{value:"api_key",children:"API Key (Custom Header)"}),e.jsx(m,{value:"hmac",children:"HMAC Signature"})]})]})]}),a.auth_type==="bearer"&&e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"bearer_token",children:"Bearer Token"}),e.jsx(d,{id:"bearer_token",type:"password",value:a.auth_credentials?.token||"",onChange:t=>n({...a,auth_credentials:{token:t.target.value}}),placeholder:"your-bearer-token"}),e.jsx("p",{className:"text-sm text-muted-foreground",children:"Sent as: Authorization: Bearer [token]"})]}),a.auth_type==="api_key"&&e.jsxs(e.Fragment,{children:[e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"api_key_header",children:"Header Name"}),e.jsx(d,{id:"api_key_header",value:a.auth_credentials?.key||"",onChange:t=>n({...a,auth_credentials:{...a.auth_credentials,key:t.target.value}}),placeholder:"X-API-Key"})]}),e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"api_key_value",children:"API Key Value"}),e.jsx(d,{id:"api_key_value",type:"password",value:a.auth_credentials?.value||"",onChange:t=>n({...a,auth_credentials:{...a.auth_credentials,value:t.target.value}}),placeholder:"your-api-key"})]})]}),a.auth_type==="hmac"&&e.jsxs("div",{className:"space-y-2",children:[e.jsx(o,{htmlFor:"hmac_secret",children:"HMAC Secret"}),e.jsx(d,{id:"hmac_secret",type:"password",value:a.auth_credentials?.secret||"",onChange:t=>n({...a,auth_credentials:{secret:t.target.value}}),placeholder:"your-hmac-secret"}),e.jsx("p",{className:"text-sm text-muted-foreground",children:"Signature sent in X-AgentQ-Signature header (HMAC-SHA256)"})]})]})]}),e.jsxs("div",{className:"flex justify-end gap-3",children:[e.jsx(k,{href:"/integrations",children:e.jsx(g,{type:"button",variant:"outline",children:"Cancel"})}),e.jsx(g,{type:"submit",disabled:j||a.events.length===0,children:j?"Saving...":i?"Update Integration":"Create Integration"})]})]})]})]})}export{we as default};
//# sourceMappingURL=form-DaKc91qj.js.map
