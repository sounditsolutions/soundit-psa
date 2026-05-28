Subject: Re: HDB integration with custom PSA

Hey Daryl,

Thanks for the guidance! We went with ConnectWise Manage as the integration since it had the most features.

We've got the CW Manage API endpoints built on our side — companyinfo, boards, statuses, types, priorities, contacts, tickets, etc. When we test with curl everything works great. But the Integration Test button in HDB gives us "Unknown error."

Looking at our server access logs, here's what we see HDB doing during the test:

1. `GET /service/boards` → 200 OK ✓
2. `GET /login/companyinfo/SoundPSA` → 200 OK ✓
3. ...nothing else. No further requests hit our server, then it reports "Unknown error."

So it looks like HDB gets a successful companyinfo response but then stops before making any more API calls. Our companyinfo returns the standard CW format:

```json
{
    "CompanyName": "SoundPSA",
    "CompanyID": "SoundPSA",
    "Codebase": "v4_6_release/",
    "VersionCode": "v2021.3",
    "VersionNumber": "v4.6.1000",
    "IsCloud": "True",
    "SiteUrl": "https://your-psa-domain/api/tier2tickets"
}
```

A few questions if you don't mind:

- After companyinfo succeeds, what's the next call HDB tries to make? Is there something in the response it's validating that might cause it to bail?
- Does HDB reconstruct the API URL from the Codebase in companyinfo, or does it use the endpoint URL we entered in settings?
- Is there a way to see more detailed error info on the HDB side? A debug log or something?

Happy to hop on a quick call if that's easier. Our endpoint is live and all the CW endpoints are responding — just need to figure out what HDB expects after companyinfo.

Thanks!
Charlie
