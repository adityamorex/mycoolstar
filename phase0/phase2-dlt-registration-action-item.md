# Action Item: DLT Registration (required for SMS/OTP feature)

**Who needs to do this**: My Cool Star (client-side, not something we can do on your behalf)
**Why**: Indian telecom regulation (TRAI) blocks SMS from any unregistered sender — required before OTP login can work, no exceptions.
**Timeline**: ~1 week if documents are ready.

## What to do

1. **Sign up with an SMS gateway** — recommended: **MSG91** (india-focused, has a guided DLT setup built into their dashboard).
2. Inside their dashboard, complete DLT registration in 3 steps:

| Step | What it registers | Approval time |
|---|---|---|
| Entity Registration | Your business identity | 1–3 days |
| Header/Sender ID (register as **Transactional**, not Promotional) | The short sender name customers see (e.g. "MYCLST") | ~1 day |
| Template Registration | Exact OTP message wording | 1–2 days |

## Documents to have ready

- PAN (business or authorized signatory)
- GST certificate (or Shop & Establishment license if no GST)
- Certificate of Incorporation / business registration proof
- Aadhaar of the authorized signatory (for e-KYC)

## One rule to know in advance

The OTP message text you register as a **template** must match **exactly** what gets sent later — even a small wording change will get silently blocked. We'll finalize the exact wording with you before this step.

## Once done

Send us the MSG91 API key — that's all we need to wire up the actual OTP send/verify flow on the website.
