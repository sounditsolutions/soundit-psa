<?php

namespace Database\Seeders;

use App\Enums\ClientStage;
use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Database\Seeder;

/**
 * OvernightExperimentSeeder
 *
 * Creates ~50 aged fake tickets with realistic conversation histories for an
 * overnight AI ConversationReviewer experiment. All clients/contacts are fictional
 * with @example.com emails. Dev DB only — NOT committed, NOT touching prod.
 *
 * Scenario distribution:
 *   15  resolved-but-never-closed  (open status; last msg = client confirming fix)
 *   10  client-ghosted             (we asked; client never replied; weeks old)
 *    8  awaiting-us (slipped)      (client asked; we never replied; old)
 *    8  pending-client             (status=pending_client; waiting on info)
 *    5  junk                       (spam/auto-notification/nonsense)
 *    4  genuinely active           (recent back-and-forth; still live)
 *   --
 *   50  total
 */
class OvernightExperimentSeeder extends Seeder
{
    // Technician users from the dev DB
    private array $techIds = [1, 2, 3, 4]; // Alex Morgan, Priya Shah, Devon Carter, Sam Whitaker

    // Tracks created client/contact pairs
    private array $clientContacts = [];

    public function run(): void
    {
        $this->command->info('[OvernightExperimentSeeder] Starting...');

        $this->ensureClients();
        $this->command->info('[OvernightExperimentSeeder] Clients ready: '.count($this->clientContacts).' client/contact pairs');

        $counts = [
            'resolved_not_closed' => 0,
            'client_ghosted' => 0,
            'awaiting_us' => 0,
            'pending_client' => 0,
            'junk' => 0,
            'active' => 0,
        ];

        // ── Scenario A: ~15 resolved-but-never-closed ──────────────────────────
        $resolvedScenarios = [
            ['subject' => 'Outlook keeps crashing on startup', 'desc' => "Hi, my Outlook crashes every time I open it. I've tried restarting but no luck. Running Windows 11, Office 365 subscription.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Hi there! Let's get that sorted. Can you try running Office Repair from Apps & Features? Start → Settings → Apps → Microsoft 365 → Modify → Quick Repair.", 'days_ago' => 62],
                ['who' => 'client', 'body' => 'Tried the quick repair but still crashing.', 'days_ago' => 61],
                ['who' => 'agent', 'body' => "Thanks for trying. Let's do an Online Repair instead — same path, but choose Online Repair. It'll take 10–15 minutes.", 'days_ago' => 61],
                ['who' => 'client', 'body' => 'That did it! Outlook is opening fine now. Thank you so much!', 'days_ago' => 60],
            ], 'opened_days_ago' => 63, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Cannot connect to shared printer on second floor', 'desc' => 'Our accounts payable team (3 people) lost access to the HP LaserJet on the second floor print queue after the Windows update yesterday.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => "I can see the printer queue had a driver conflict after KB5030219. I've pushed the corrected driver via RMM. Can you try printing a test page?", 'days_ago' => 45],
                ['who' => 'client', 'body' => 'It works for Margo and Denton, but not for Lisa yet.', 'days_ago' => 45],
                ['who' => 'agent', 'body' => "Lisa's machine needed the driver manually. I've logged in and fixed it remotely.", 'days_ago' => 44],
                ['who' => 'client', 'body' => 'All three of us are printing now! All good. Thanks for the quick fix.', 'days_ago' => 43],
            ], 'opened_days_ago' => 46, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'VPN disconnects every 20 minutes', 'desc' => 'Our remote users (about 5 staff working from home) are getting dropped from the VPN roughly every 20 minutes. Happens on multiple devices. Very disruptive to work.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Thanks for reporting this. I've adjusted the idle timeout on the VPN concentrator from 20 min to 4 hours, and enabled dead-peer-detection retry. Can you test tomorrow morning?", 'days_ago' => 78],
                ['who' => 'client', 'body' => "Much better — we've been on for 3 hours straight. I'll confirm tomorrow if it holds.", 'days_ago' => 77],
                ['who' => 'client', 'body' => "Confirmed — no drops at all yesterday or today. That's fixed! Really appreciate it.", 'days_ago' => 76],
            ], 'opened_days_ago' => 80, 'status' => TicketStatus::New, 'priority' => TicketPriority::P2],

            ['subject' => 'Excel file corrupted — cannot open', 'desc' => "Our finance manager's Excel file with Q2 budget data is showing 'file format is not valid'. She's been working on it for weeks and is panicking.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "I've recovered it from the OneDrive version history — found a copy from 2 days ago before the corruption. I'm emailing it to you now. Can you confirm you received it and the data looks right?", 'days_ago' => 55],
                ['who' => 'client', 'body' => "Got it! The data is all there, including the most recent figures she entered Monday. She's so relieved. Thank you!!", 'days_ago' => 55],
            ], 'opened_days_ago' => 56, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'New laptop setup for onboarding — James Winters', 'desc' => 'We have a new hire starting Monday (James Winters, account manager). Need the standard laptop setup: company email, M365, file access to the Sales shared drive, Salesforce.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Setup complete. James's account is provisioned, laptop enrolled in Intune, M365 activated, Salesforce access granted. The laptop is ready to ship — tracking number sent separately.", 'days_ago' => 30],
                ['who' => 'client', 'body' => "James started today and everything worked perfectly out of the box. He's already in his email and CRM. Great work!", 'days_ago' => 28],
            ], 'opened_days_ago' => 35, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Password reset not working through portal', 'desc' => "Several users are reporting the self-service password reset portal throws an error. They're clicking the link in the email but it says 'token expired' immediately.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Root cause found: the SSPR token lifetime was set to 1 minute (should be 15). I've corrected the Azure AD policy. Can you test with one of the affected users?", 'days_ago' => 88],
                ['who' => 'client', 'body' => 'Just tested with Sandra — it worked! Link was still valid and she reset successfully. Thanks for tracking that down.', 'days_ago' => 87],
            ], 'opened_days_ago' => 90, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],

            ['subject' => 'Teams calls drop on wireless — conference room C', 'desc' => 'Teams calls in conference room C drop out every few minutes. Only in that room — the two other conference rooms are fine. Clients have noticed.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => 'I looked at the AP logs — the WAP in room C was on an overcrowded 2.4GHz channel (channel 6, congested). Moved it to 5GHz channel 149 and increased transmit power. Can you test from that room?', 'days_ago' => 40],
                ['who' => 'client', 'body' => 'Had a 90-minute Teams call in there this morning with no drops at all. Fixed! Thank you.', 'days_ago' => 38],
            ], 'opened_days_ago' => 42, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'QuickBooks cannot connect to company file', 'desc' => "QuickBooks Desktop Pro 2023 stopped connecting to the company file on the server this morning. Getting 'H202 multi-user error'. Three users affected.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "H202 is a multi-user hosting service issue. I've restarted the QuickBooks Database Server Manager on your server and re-configured the firewall exceptions for ports 8019 and 56728. Try connecting now.", 'days_ago' => 48],
                ['who' => 'client', 'body' => "All three users can connect now. Perfect. We were down for a couple hours but we're back up. Thanks.", 'days_ago' => 48],
            ], 'opened_days_ago' => 49, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'Spam filter blocking legitimate vendor emails', 'desc' => "Emails from our main parts supplier (parts@oakridge-supply.com) are going to quarantine. This has been happening about a week and we've missed several order confirmations.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "I've reviewed the quarantine logs — the domain oakridge-supply.com was added to the blocklist after a temporary IP reputation issue on their sending server. I've added their domain to the safe sender list and released the queued messages. You should receive them now.", 'days_ago' => 68],
                ['who' => 'client', 'body' => 'Got all the queued messages and a fresh one from them just now. All good. Thanks for sorting that quickly!', 'days_ago' => 67],
            ], 'opened_days_ago' => 70, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],

            ['subject' => 'Second monitor not detected after desk move', 'desc' => 'Moved my desk over the weekend and now my second monitor is not detected. The DisplayPort cable seems fine. HDMI adapter works but the resolution is wrong.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => 'The DisplayPort cable can loosen during a move. Try firmly reseating the cable at both ends and right-click desktop → Display Settings → Detect. Also check Device Manager for display adapters.', 'days_ago' => 33],
                ['who' => 'client', 'body' => 'Reseating the cable at the monitor end fixed it. Both monitors are showing now and at the right resolution. Thanks for the simple fix!', 'days_ago' => 33],
            ], 'opened_days_ago' => 34, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P4],

            ['subject' => 'OneDrive sync stopped — not uploading new files', 'desc' => "My OneDrive has had a sync pending icon for 3 days. New files aren't uploading to the cloud. The status bar says 'Processing changes' but nothing moves.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "This is a known issue with the OneDrive client version you have. I've reset the OneDrive sync engine (via the command-line reset) and updated to the latest build. The initial re-index may take an hour. Let me know if it's still stuck after that.", 'days_ago' => 51],
                ['who' => 'client', 'body' => 'It resynced overnight and everything is green this morning. File I saved yesterday is already in SharePoint. All sorted, cheers!', 'days_ago' => 50],
            ], 'opened_days_ago' => 53, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'MFA suddenly prompting every login even on trusted device', 'desc' => 'Since Monday, my MFA is prompting me every single time I log in on my work laptop, even though it used to remember the device for 30 days. Very annoying.', 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Your device's MFA persistent token was cleared when a Conditional Access policy was updated last weekend (added device compliance check). I've re-registered your laptop as compliant in Intune. Sign out, sign back in, check 'Don't ask again for 30 days' — should be back to normal.", 'days_ago' => 44],
                ['who' => 'client', 'body' => "That fixed it. Only prompted me once and then I checked the box and now it's back to once a month. Thank you!", 'days_ago' => 43],
            ], 'opened_days_ago' => 46, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Backup jobs failing — Comet "no connection to destination"', 'desc' => "Our nightly backup jobs have been failing for the past 4 nights with 'no connection to destination' in Comet. The storage bucket should be fine — we haven't changed anything.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Investigated: the storage bucket access key was rotated by your cloud team last Thursday but the Comet job wasn't updated with the new credentials. I've updated the storage configuration in Comet with the new key. Triggering a manual backup run now.", 'days_ago' => 57],
                ['who' => 'client', 'body' => "Manual run completed successfully — 24GB backed up. We'll monitor tonight's scheduled run. Thanks for tracking down the root cause.", 'days_ago' => 57],
                ['who' => 'client', 'body' => 'Scheduled backup ran overnight without issues. All good now. Thank you.', 'days_ago' => 56],
            ], 'opened_days_ago' => 59, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'Request to add 5 new users to staff SharePoint', 'desc' => 'We have 5 summer interns starting July 1. They need read-only access to the Staff Documents SharePoint site (not the Finance or HR sections).', 'resolution_convo' => [
                ['who' => 'agent', 'body' => "All 5 interns added as Visitors to Staff Documents SharePoint with the restricted group that excludes Finance and HR subsites. They'll be able to browse but not edit. Confirmed with a test login for one account.", 'days_ago' => 29],
                ['who' => 'client', 'body' => "Checked with one of the interns — she can see everything she needs and can't get into the Finance section. Perfect. Thanks!", 'days_ago' => 28],
            ], 'opened_days_ago' => 31, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P4],

            ['subject' => 'Website contact form stopped sending emails', 'desc' => "Our website contact form submissions stopped arriving about 2 weeks ago. We only noticed when a customer called to follow up on a quote request they'd submitted online.", 'resolution_convo' => [
                ['who' => 'agent', 'body' => "Found it — the SMTP credentials on the web server were using a mailbox account that was disabled when the previous employee left. I've updated the form to use the shared support@yourcompany email with its app password. Test form submission sent.", 'days_ago' => 39],
                ['who' => 'client', 'body' => "Got the test email. Just had our first real inquiry come through the form too. All working. Thanks — good thing that customer called or we'd have missed even more.", 'days_ago' => 38],
            ], 'opened_days_ago' => 41, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],
        ];

        foreach ($resolvedScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => $s['status'],
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => now()->subDays($s['opened_days_ago'] - 1),
            ]);
            $this->addConversation($ticket, $s['resolution_convo'], $s['opened_days_ago']);
            $counts['resolved_not_closed']++;
        }

        // ── Scenario B: ~10 client-ghosted ─────────────────────────────────────
        $ghostedScenarios = [
            ['subject' => 'Intermittent slowness on accounting software', 'desc' => 'Sage 50 has been running slow intermittently — takes 30+ seconds to open reports. Happens a few times a day.', 'convo' => [
                ['who' => 'client', 'body' => 'Hi, Sage 50 is running really slow — especially opening monthly reports. Maybe 30 seconds to load.', 'days_ago' => 50],
                ['who' => 'agent', 'body' => 'Thanks for the report. To narrow this down, can you tell me: does it happen on all PCs or just one? And is Sage running locally or connecting to a server?', 'days_ago' => 49],
            ], 'opened_days_ago' => 50, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Laptop battery not charging past 80%', 'desc' => "My Dell laptop battery only charges to 80% and stops. The battery health indicator shows 'consider replacing battery'.", 'convo' => [
                ['who' => 'client', 'body' => "My laptop won't charge past 80%. Is there a fix or do I need a new battery?", 'days_ago' => 35],
                ['who' => 'agent', 'body' => "Dell has a 'Battery Health Mode' that caps charging at 80% to extend lifespan — check the Dell Power Manager app. If that's enabled and you want 100% charge, disable it there. Can you let us know if that setting is active?", 'days_ago' => 34],
            ], 'opened_days_ago' => 35, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P4],

            ['subject' => 'Request for second email signature template', 'desc' => "We'd like a second signature template for our sales team — slightly different from the standard one, with a promotional banner.", 'convo' => [
                ['who' => 'client', 'body' => 'Can we get a second email signature with our summer promo banner for the sales team?', 'days_ago' => 44],
                ['who' => 'agent', 'body' => 'Absolutely. Can you send me the promo banner image (PNG preferred, ~600px wide) and any text changes vs. the standard signature? Once I have those, I can build it out.', 'days_ago' => 43],
            ], 'opened_days_ago' => 44, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P4],

            ['subject' => 'Cannot login to client billing portal', 'desc' => "Getting 'Invalid credentials' on the billing portal even after resetting my password three times.", 'convo' => [
                ['who' => 'client', 'body' => 'I keep getting invalid credentials on the billing portal. Tried resetting but same error.', 'days_ago' => 58],
                ['who' => 'agent', 'body' => "I've verified the portal account exists and the password reset link was sent successfully. Could you try an incognito/private browser window and also confirm which email address you're using to log in?", 'days_ago' => 57],
            ], 'opened_days_ago' => 58, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Zoom meeting recordings not appearing in cloud', 'desc' => "Zoom cloud recordings from the last two weeks aren't showing up. We need them for client meeting records.", 'convo' => [
                ['who' => 'client', 'body' => "Our Zoom cloud recordings from the past two weeks aren't showing up in our account.", 'days_ago' => 40],
                ['who' => 'agent', 'body' => "Checked your Zoom account settings — cloud recording is enabled and the storage isn't full. This sometimes happens when the meeting host wasn't properly signed into the licensed account. Can you confirm which user is hosting these meetings so I can check their specific settings?", 'days_ago' => 39],
            ], 'opened_days_ago' => 40, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Wireless keyboard occasionally stops responding', 'desc' => 'My wireless keyboard randomly stops responding for 5-10 seconds a few times per day. Battery is fresh.', 'convo' => [
                ['who' => 'client', 'body' => 'Wireless keyboard freezes up for a few seconds randomly during the day. Already replaced the batteries.', 'days_ago' => 60],
                ['who' => 'agent', 'body' => 'Could be USB radio interference or a driver issue. Try: 1) Moving the USB receiver to a port on the back of the PC away from other USB devices, and 2) Check if the keyboard is Bluetooth or uses a USB dongle — which is yours?', 'days_ago' => 59],
            ], 'opened_days_ago' => 60, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P4],

            ['subject' => 'SharePoint folder permissions for project team', 'desc' => 'Need a dedicated SharePoint folder for the Westbrook project with access for 8 specific people — some internal, some external contractors.', 'convo' => [
                ['who' => 'client', 'body' => 'We need a SharePoint folder for the Westbrook project. 8 people need access — 5 internal and 3 external contractors.', 'days_ago' => 47],
                ['who' => 'agent', 'body' => 'Happy to set that up. Please send me: the names and email addresses of all 8 people, the access level each needs (Edit vs. View only), and whether the folder should be under an existing SharePoint site or in a new one.', 'days_ago' => 46],
            ], 'opened_days_ago' => 47, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P3],

            ['subject' => 'Request to upgrade Office to latest version', 'desc' => "We're still on Office 2019 and want to upgrade to Microsoft 365. Looking for a quote and timeline.", 'convo' => [
                ['who' => 'client', 'body' => 'We want to move from Office 2019 to Microsoft 365. Can you put together a quote for us?', 'days_ago' => 70],
                ['who' => 'agent', 'body' => "Great move! To put together an accurate quote, can you confirm: how many licensed users (full-time + part-time), whether you need Exchange Online or have other email, and if you use any on-premise servers we'd need to factor in?", 'days_ago' => 69],
            ], 'opened_days_ago' => 70, 'status' => TicketStatus::New, 'priority' => TicketPriority::P4],

            ['subject' => 'Noise cancellation not working on headset', 'desc' => 'The noise cancellation on my Jabra headset stopped working after I updated the Jabra Direct app. Background office noise is coming through on calls.', 'convo' => [
                ['who' => 'client', 'body' => "Noise cancellation on my Jabra headset doesn't work anymore since the app update.", 'days_ago' => 32],
                ['who' => 'agent', 'body' => 'This is a known issue with Jabra Direct version 6.14 — the ANC setting can get reset on update. In Jabra Direct, go to your headset → Sidetone → and also check Microphone → Enable noise filter. Does that setting show as enabled?', 'days_ago' => 31],
            ], 'opened_days_ago' => 32, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P4],

            ['subject' => 'Adobe Acrobat asking to purchase again', 'desc' => "Acrobat Pro is showing a 'Your trial has ended — purchase to continue' message. We have a paid subscription and have been using it for a year.", 'convo' => [
                ['who' => 'client', 'body' => "Acrobat Pro says 'trial has ended' and wants us to buy it. We have a subscription!", 'days_ago' => 65],
                ['who' => 'agent', 'body' => "This usually happens when Adobe loses the license link. Can you try signing out of the Adobe account in Acrobat (Help → Sign Out) and signing back in? Also — is the subscription under a personal Adobe ID or your company email? That'll help me check the license assignment.", 'days_ago' => 64],
            ], 'opened_days_ago' => 65, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],
        ];

        foreach ($ghostedScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => $s['status'],
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => now()->subDays($s['opened_days_ago'] - 1),
            ]);
            $this->addConversation($ticket, $s['convo'], $s['opened_days_ago']);
            $counts['client_ghosted']++;
        }

        // ── Scenario C: ~8 awaiting-us (slipped) ───────────────────────────────
        // Last message is the client asking us something — we never replied
        $awaitingUsScenarios = [
            ['subject' => 'Email sending failure from Xero', 'desc' => "Xero is failing to send invoice emails to clients. Error: 'SMTP authentication failure'.", 'convo' => [
                ['who' => 'agent', 'body' => "Hi, I'll look into the Xero SMTP config. Can you tell me which email provider Xero is connecting to — Office 365, Gmail, or a custom server?", 'days_ago' => 54],
                ['who' => 'client', 'body' => "We're using Office 365. The SMTP settings haven't changed in months. Could this be related to the MFA changes you made last week?", 'days_ago' => 53],
            ], 'opened_days_ago' => 55, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'New staff PC setup — Jane Holloway', 'desc' => 'We have a new team member starting in accounting (Jane Holloway). Needs a full setup: PC, email account, accounting software access.', 'convo' => [
                ['who' => 'agent', 'body' => "Great — we'll get Jane set up. Just to confirm: which accounting software package (Sage, Xero, QuickBooks)? And will she be using a new PC or an existing one being repurposed?", 'days_ago' => 72],
                ['who' => 'client', 'body' => "She needs Xero and Sage. We bought a new HP EliteDesk from Office Depot — it's sitting here in the box. Should we send it to you or can you remote in once it's powered on and connected?", 'days_ago' => 71],
            ], 'opened_days_ago' => 73, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],

            ['subject' => 'Teams voicemail not transcribing', 'desc' => 'Teams voicemails stopped transcribing about 10 days ago. Just shows a blank message, no text. We rely on this for our receptionist.', 'convo' => [
                ['who' => 'agent', 'body' => "Transcription failures usually trace to a Teams Phone license issue or a policy change. I've checked and the transcription service is showing errors on your tenant. I've escalated to Microsoft — reference number SR1234567. Typical response is 24–72 hours.", 'days_ago' => 37],
                ['who' => 'client', 'body' => "Thanks. Any update from Microsoft? It's been 4 days and still not working. This is affecting our receptionist quite a lot.", 'days_ago' => 33],
            ], 'opened_days_ago' => 38, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'Request: install SolidWorks on engineering workstations', 'desc' => "We've purchased 3 SolidWorks licenses. Need them installed on the three engineering workstations (ENGWS01, ENGWS02, ENGWS03).", 'convo' => [
                ['who' => 'agent', 'body' => "Received. To proceed, we'll need the SolidWorks license key(s) and the installer from your SolidWorks account (or you can share your customer portal login). Do you have those ready?", 'days_ago' => 82],
                ['who' => 'client', 'body' => "I sent the license keys and portal login to devon@bluetier-it.example.com yesterday. Did it arrive? Want to make sure it didn't go to junk.", 'days_ago' => 81],
            ], 'opened_days_ago' => 83, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],

            ['subject' => 'Printer offline after network switch replacement', 'desc' => 'After the network switch in the back office was replaced last Friday, the Ricoh copier is showing offline. It was working before.', 'convo' => [
                ['who' => 'agent', 'body' => "The switch replacement changed the VLAN assignment. The Ricoh may have gotten a new IP via DHCP. Can you check the printer's LCD panel for its current IP address and send it to me?", 'days_ago' => 60],
                ['who' => 'client', 'body' => "The IP on the display says 192.168.5.47. Also — is it possible to give it a fixed IP so this doesn't happen again next time?", 'days_ago' => 59],
            ], 'opened_days_ago' => 61, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Cannot install software — admin rights blocked', 'desc' => "Several staff are unable to install approved software because they don't have admin rights. The department manager says this is causing productivity issues.", 'convo' => [
                ['who' => 'agent', 'body' => "I've reviewed the policy — users are correctly in the standard users group per security policy. Rather than grant admin rights, we can add specific approved apps to a deployment policy. Can you send me a list of the software titles that need installing?", 'days_ago' => 43],
                ['who' => 'client', 'body' => "I've emailed a list of 8 apps to support. Also — is there a way to do this as a self-service portal where staff can request approved apps? That would save everyone a lot of back-and-forth.", 'days_ago' => 41],
            ], 'opened_days_ago' => 44, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Cloud backup storage warning — 90% full', 'desc' => 'Got an alert that our cloud backup storage is at 90% capacity. Just noticed the retention is keeping 365 days but we only need 90 days.', 'convo' => [
                ['who' => 'agent', 'body' => 'Good catch. Reducing retention from 365 to 90 days will free significant space. I can make that change — it will delete backup snapshots older than 90 days on the next cleanup run. Just want to confirm: are you happy to proceed, and is there any legal/compliance reason you need to keep 365 days?', 'days_ago' => 66],
                ['who' => 'client', 'body' => "No compliance reason — 90 days is fine for us. Go ahead and make the change. One more thing: can you also check whether the backups include our NAS drive? We added that 6 months ago and I'm not sure if it's covered.", 'days_ago' => 64],
            ], 'opened_days_ago' => 67, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Domain renewal reminder — action needed?', 'desc' => 'Got an email saying our domain is expiring in 45 days. Is this something you manage or do we need to renew it ourselves?', 'convo' => [
                ['who' => 'agent', 'body' => "Good question. I checked our records — your domain is registered with GoDaddy under your account (not managed by us). You'll need to renew directly at godaddy.com. That said, I can help you set it to auto-renew so you don't have to think about it each year. Want me to walk you through that?", 'days_ago' => 78],
                ['who' => 'client', 'body' => "Yes please! Also — can you check if our SSL cert is in the same situation? It was set up at the same time and I want to make sure we don't have the website go down.", 'days_ago' => 77],
            ], 'opened_days_ago' => 79, 'status' => TicketStatus::New, 'priority' => TicketPriority::P3],
        ];

        foreach ($awaitingUsScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => $s['status'],
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => now()->subDays($s['opened_days_ago'] - 1),
            ]);
            $this->addConversation($ticket, $s['convo'], $s['opened_days_ago']);
            $counts['awaiting_us']++;
        }

        // ── Scenario D: ~8 pending-client ──────────────────────────────────────
        $pendingClientScenarios = [
            ['subject' => 'Laptop screen flickering — possible hardware fault', 'desc' => 'My laptop screen flickers when I open applications. Sometimes goes dark for a second then comes back.', 'convo' => [
                ['who' => 'client', 'body' => 'Screen keeps flickering especially when I open Chrome or Teams.', 'days_ago' => 22],
                ['who' => 'agent', 'body' => 'Likely a display driver or GPU throttling issue. Updated your display driver remotely. To rule out hardware, can you test with an external monitor plugged in for a day and let us know if the flicker happens on the external display too?', 'days_ago' => 21],
            ], 'opened_days_ago' => 23, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P3],

            ['subject' => 'Wi-Fi drops frequently at home office', 'desc' => 'Working from home — Wi-Fi keeps dropping every couple hours. Home router, ISP is Comcast.', 'convo' => [
                ['who' => 'client', 'body' => 'My home Wi-Fi keeps dropping. Happens every 2 hours or so. Using a Comcast router.', 'days_ago' => 18],
                ['who' => 'agent', 'body' => "A few things to try at home: 1) Power cycle the router (unplug 30 seconds). 2) If you have a choice between 2.4GHz and 5GHz networks, try 5GHz — it's less congested for home use. 3) Keep an eye on when it drops — is it the same time each day? We may also be able to provide a mesh access point through the IT budget if the home router is consistently poor. Can you try those steps and update us on whether it helps?", 'days_ago' => 17],
            ], 'opened_days_ago' => 19, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P4],

            ['subject' => 'Urgent: cannot access payroll system — end of month', 'desc' => "I cannot log into ADP payroll. Getting 'your account has been locked'. Payroll runs tomorrow.", 'convo' => [
                ['who' => 'client', 'body' => 'URGENT: locked out of ADP. Payroll is due tomorrow morning!', 'days_ago' => 15],
                ['who' => 'agent', 'body' => "On it. ADP account locks are controlled by ADP — I've contacted their support and the account should be unlocked within 2 hours (they have an expedited process for payroll situations). I'll update you the moment it's confirmed. In the meantime, do you have a backup contact at ADP or admin access to your ADP account through another user?", 'days_ago' => 15],
                ['who' => 'client', 'body' => 'ADP just called me and unlocked it. I can get in now. Thanks for getting on that so fast — crisis averted!', 'days_ago' => 14],
                ['who' => 'agent', 'body' => "Great news! For next time: your ADP account is now set to notify you before it locks (after 5 failed attempts). We've also noted the expedited unlock number in your client notes. Is there anything else you need for payroll?", 'days_ago' => 14],
            ], 'opened_days_ago' => 16, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P2],

            ['subject' => 'Google Chrome extension causing browser crashes', 'desc' => 'Chrome crashes after about 10 minutes of use. Just started this week. I recently installed a couple of extensions.', 'convo' => [
                ['who' => 'client', 'body' => 'Chrome crashes every 10 minutes. I installed a Grammarly extension and a PDF editor last week.', 'days_ago' => 20],
                ['who' => 'agent', 'body' => 'Extension conflicts are a common crash cause. Try disabling both extensions (chrome://extensions) and run for an hour. If it stops crashing, re-enable one at a time to find the culprit. Can you let us know the result?', 'days_ago' => 19],
            ], 'opened_days_ago' => 21, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P4],

            ['subject' => 'Network share not visible after Windows 11 upgrade', 'desc' => "After upgrading to Windows 11 last week, I can't see the department file share (\\\\fileserver\\projects) in File Explorer. Other staff on Windows 10 can still access it.", 'convo' => [
                ['who' => 'client', 'body' => "Can't see the Projects file share after Windows 11 upgrade. Other people on Win10 can access it fine.", 'days_ago' => 12],
                ['who' => 'agent', 'body' => "Windows 11 sometimes drops SMBv1 support which older shares need. I've run a remote check — your share uses SMB2 so that's not the issue. More likely the drive mapping was lost during upgrade. I've re-mapped the drive via script and it should appear as Z: drive now. Can you open File Explorer and check if Projects is there?", 'days_ago' => 11],
            ], 'opened_days_ago' => 13, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P3],

            ['subject' => 'Phone system voicemail box full — calls going to busy signal', 'desc' => 'Our main office number (206-555-0142) is giving callers a busy signal. Reception says the voicemail might be full.', 'convo' => [
                ['who' => 'client', 'body' => 'Our main phone number is going straight to busy. Receptionist thinks voicemail is full.', 'days_ago' => 17],
                ['who' => 'agent', 'body' => "Checked the PBX — your main mailbox was at 99% (218 saved messages, 200MB limit). I've cleared messages older than 90 days (these were already listened to). Mailbox is now at 31%. Can you confirm incoming calls are connecting normally now?", 'days_ago' => 16],
            ], 'opened_days_ago' => 18, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P2],

            ['subject' => 'Excel macro stopped working after update', 'desc' => "Our monthly reporting macro broke after an Office update last Tuesday. It runs halfway and then throws a 'runtime error 91 — object variable not set'.", 'convo' => [
                ['who' => 'client', 'body' => "Monthly report macro crashes with 'runtime error 91' since the Office update.", 'days_ago' => 24],
                ['who' => 'agent', 'body' => "Runtime error 91 after an update usually means a late-binding object reference changed. Can you send me the macro file (or the VBA code for the relevant module)? I'll be able to identify the broken line and update the reference.", 'days_ago' => 23],
            ], 'opened_days_ago' => 25, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P3],

            ['subject' => 'PDF forms not saving filled-in data', 'desc' => 'Our client intake forms (PDF) that staff fill in using Adobe Reader are not saving the field data. When you re-open the file, all the text fields are blank.', 'convo' => [
                ['who' => 'client', 'body' => 'PDF forms we fill in using Adobe Reader lose all the data when saved. The fields are blank when you reopen.', 'days_ago' => 28],
                ['who' => 'agent', 'body' => "This happens when a PDF form has 'Reader rights' disabled — only editable in full Acrobat. To confirm: when you open the file, does it show a yellow bar saying 'Fill & Sign' with limited features? And are all affected staff using Reader (free) vs. full Acrobat Pro?", 'days_ago' => 27],
            ], 'opened_days_ago' => 29, 'status' => TicketStatus::PendingClient, 'priority' => TicketPriority::P3],
        ];

        foreach ($pendingClientScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => TicketStatus::PendingClient,
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => now()->subDays($s['opened_days_ago'] - 1),
            ]);
            $this->addConversation($ticket, $s['convo'], $s['opened_days_ago']);
            $counts['pending_client']++;
        }

        // ── Scenario E: ~5 junk ────────────────────────────────────────────────
        $junkScenarios = [
            ['subject' => 'Delivery failed: Re: Re: Re: FW: Invoice #44291', 'desc' => 'MAILER-DAEMON@mail.server.ru: <bounce@mailer-nd.com> does not exist at this server. Mail Delivery Status Report. This message was created automatically by mail delivery software. A message that you sent could not be delivered to one or more of its recipients. This is a permanent error.', 'convo' => [], 'opened_days_ago' => 55, 'priority' => TicketPriority::P4],

            ['subject' => '🎉 You\'ve been selected! Claim your $500 Amazon Gift Card', 'desc' => 'Congratulations! As a valued customer you have been randomly selected to receive a $500 Amazon Gift Card. To claim your prize click: http://bit.ly/claim-prize-now. Offer expires in 24 hours. This is not spam. You opted in via our partner site.', 'convo' => [], 'opened_days_ago' => 30, 'priority' => TicketPriority::P4],

            ['subject' => 'noreply@alerts.uptimerobot.com: Monitor is UP', 'desc' => 'UptimeRobot Alert: [UP] yourwebsite.com is up. Response time: 342ms. This is an automated monitoring notification. To manage your alert contacts visit uptimerobot.com/dashboard.', 'convo' => [], 'opened_days_ago' => 20, 'priority' => TicketPriority::P4],

            ['subject' => 'CRON: /usr/bin/php /var/www/cron.php >> /var/log/cron.log 2>&1', 'desc' => 'No output.', 'convo' => [], 'opened_days_ago' => 45, 'priority' => TicketPriority::P4],

            ['subject' => 'Unsubscribe confirmation from noreply@newsletter-service.com', 'desc' => 'You have been successfully unsubscribed from the Acme Newsletter. You will no longer receive marketing emails from us. If this was a mistake, resubscribe at: acmenewsletter.example.com/subscribe. Thank you.', 'convo' => [], 'opened_days_ago' => 60, 'priority' => TicketPriority::P4],
        ];

        foreach ($junkScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => TicketStatus::New,
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => null,
            ]);
            if (! empty($s['convo'])) {
                $this->addConversation($ticket, $s['convo'], $s['opened_days_ago']);
            }
            $counts['junk']++;
        }

        // ── Scenario F: ~4 genuinely active ────────────────────────────────────
        $activeScenarios = [
            ['subject' => 'Microsoft 365 tenant migration planning', 'desc' => 'We need to migrate from our current Microsoft 365 tenant (under old company name) to a new tenant under the rebranded company name. About 45 users, 60TB OneDrive, Exchange Online.', 'convo' => [
                ['who' => 'agent', 'body' => "Thanks for bringing us in on this. A 45-user tenant migration is significant — we'd recommend a phased approach: mail first, then SharePoint/OneDrive, then Teams. A few questions: What's your target completion date? Do you have any compliance requirements (HIPAA, SOC2)? Are you using Azure AD Connect with on-prem AD?", 'days_ago' => 8],
                ['who' => 'client', 'body' => "Target is end of Q3 (September 30). No HIPAA/SOC2, but we do have an on-prem server with Active Directory sync'd to Azure AD. That complicates things, right?", 'days_ago' => 7],
                ['who' => 'agent', 'body' => "Yes — the AD sync means we need to handle AAD Connect cutover carefully. The on-prem identities need to be re-synced to the new tenant. I'm drafting a project plan and will have it to you by end of week. In the meantime, can you share the number of shared mailboxes, distribution lists, and any custom domains you use?", 'days_ago' => 6],
                ['who' => 'client', 'body' => "We have 8 shared mailboxes, 12 distribution lists, and 3 custom domains (one primary, two legacy aliases). I'll send you the full inventory spreadsheet tomorrow morning.", 'days_ago' => 5],
                ['who' => 'agent', 'body' => "Perfect — that's very helpful. Looking forward to the inventory. I'll factor that into the migration plan and we can schedule a kickoff call for early next week once you've had a chance to review.", 'days_ago' => 4],
            ], 'opened_days_ago' => 9, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'Ransomware investigation — suspicious encryption activity', 'desc' => "Our NAS device started showing encrypted files this morning (.crypted extension). About 200 files affected so far, mostly in the Marketing shared folder. We've isolated the NAS from the network already.", 'convo' => [
                ['who' => 'agent', 'body' => "Good call isolating the NAS immediately. DO NOT attempt to restore or decrypt files yet. I'm starting an incident response now. First priority: identify the infected endpoint that caused the encryption. Can you send me a list of which staff accessed the Marketing share in the last 24 hours?", 'days_ago' => 3],
                ['who' => 'client', 'body' => "Marketing share is accessed by 6 staff. I'm pulling the access log — emailing it now. One of the marketing team PCs was making strange sounds this morning, could that be it?", 'days_ago' => 3],
                ['who' => 'agent', 'body' => "Received the access log. The marketing team PC (Tracy G.) is showing anomalous behavior in the RMM — high disk I/O at 7:12 AM consistent with encryption activity. I've isolated that machine remotely. Running a scan now. The NAS backups are intact (last good backup 11 PM last night). We can restore the 200 files from backup. Do you want to proceed with restore once we've confirmed the infected machine is clean?", 'days_ago' => 2],
                ['who' => 'client', 'body' => "Yes, please proceed with restore from backup. Is Tracy's machine saveable or does it need to be wiped? She has some local files that aren't backed up.", 'days_ago' => 1],
            ], 'opened_days_ago' => 4, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P2],

            ['subject' => 'New office setup — IT infrastructure for 20-person expansion', 'desc' => "We're opening a second office location across town with 20 new workstations, a print room, conference room AV, and we need network infrastructure. Looking for a quote and project plan.", 'convo' => [
                ['who' => 'agent', 'body' => "Exciting news! For a 20-person office, we'd typically plan: structured cabling (Cat6A), a PoE switch stack, firewall/router, AP coverage for the floor plan, print server, and the AV setup for conference rooms. Can you share: rough sq footage, number of conference rooms, and is this a new building or an existing fit-out?", 'days_ago' => 7],
                ['who' => 'client', 'body' => "It's an existing fit-out, 4,000 sq ft. Two conference rooms. We'd love a Zoom Rooms setup in both. The current tenant left some Cat5e cabling but we're not sure if it's sufficient.", 'days_ago' => 6],
                ['who' => 'agent', 'body' => "Cat5e works for 1GbE which is fine for most workstations, but we'd recommend replacing anything near APs with Cat6A to support wireless AX. I'll put together a site visit — can you get us access this week to walk the floor and check the cable runs? Zoom Rooms for two conference rooms I can quote separately.", 'days_ago' => 5],
                ['who' => 'client', 'body' => "Thursday afternoon (2–4pm) works for a site visit. I'll make sure the building manager is there to let you in. Also — we need everything ready by August 15. Is that achievable?", 'days_ago' => 4],
                ['who' => 'agent', 'body' => "Thursday 2pm works. August 15 is tight but achievable if we get the order placed by July 10 — lead times on switches are running 3–4 weeks right now. I'll scope it out Thursday and have a quote and timeline to you by end of that week.", 'days_ago' => 3],
            ], 'opened_days_ago' => 8, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],

            ['subject' => 'Intermittent VOIP call quality issues — choppy audio', 'desc' => 'Staff are reporting choppy, robotic audio on VOIP calls 2-3 times per day. Started about 5 days ago. Affects outbound calls more than inbound.', 'convo' => [
                ['who' => 'client', 'body' => 'Getting reports of choppy VOIP calls. Sounds robotic on outbound calls, happens a few times a day.', 'days_ago' => 5],
                ['who' => 'agent', 'body' => "VOIP choppiness is usually a QoS or jitter issue. I've pulled your router stats — seeing burst packet loss of 3–8% during business hours (9am–12pm peak). Looks like your SIP traffic isn't being prioritized over regular web traffic. I can implement QoS tagging on the router. This'll require a brief 2-minute maintenance window. Can you arrange a time today or tomorrow?", 'days_ago' => 4],
                ['who' => 'client', 'body' => 'Today at 11:30am works — we have a slow period then. Is 2 minutes a hard guarantee or could it be longer?', 'days_ago' => 3],
                ['who' => 'agent', 'body' => "Config push is about 60 seconds, but I always allow 5 minutes for the router to re-establish sessions. VOIP should be back up within 2 minutes. Confirming 11:30am — I'll ping you at 11:25 before I start.", 'days_ago' => 2],
                ['who' => 'client', 'body' => "Confirmed. We'll make sure no one is on a call at 11:30. Also — after the change, how can we tell if it's working? What should we look for?", 'days_ago' => 1],
            ], 'opened_days_ago' => 6, 'status' => TicketStatus::InProgress, 'priority' => TicketPriority::P3],
        ];

        foreach ($activeScenarios as $s) {
            $pair = $this->randomClientContact();
            $ticket = $this->createTicket($pair, [
                'subject' => $s['subject'],
                'description' => $s['desc'],
                'status' => $s['status'],
                'priority' => $s['priority'],
                'source' => TicketSource::Email,
                'opened_at' => now()->subDays($s['opened_days_ago']),
                'responded_at' => now()->subDays($s['opened_days_ago'] - 1),
            ]);
            $this->addConversation($ticket, $s['convo'], $s['opened_days_ago']);
            $counts['active']++;
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $total = array_sum($counts);
        $this->command->info('[OvernightExperimentSeeder] Done!');
        $this->command->table(
            ['Scenario', 'Count'],
            [
                ['resolved_not_closed', $counts['resolved_not_closed']],
                ['client_ghosted',      $counts['client_ghosted']],
                ['awaiting_us',         $counts['awaiting_us']],
                ['pending_client',      $counts['pending_client']],
                ['junk',                $counts['junk']],
                ['active',              $counts['active']],
                ['TOTAL',               $total],
            ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Ensure we have 6 new seeder-owned clients with example.com contacts.
     * Also loads all 10 existing fake clients (IDs 1-10).
     */
    private function ensureClients(): void
    {
        // Reuse existing clearly-fake, fully-operational clients (IDs 1-10)
        $existingClients = [
            [1, 1],   // Acme Dental Practice → Anjali Patel
            [1, 2],   // Acme Dental Practice → Lori Bennett
            [2, 4],   // Brightside Marketing → Jordan Reeves
            [3, 6],   // Vandelay Industries → George Costanza
            [4, 10],  // Pinnacle Insurance → Daniel OBrien
            [5, 12],  // HarborView Law → Eleanor Tan
            [6, 14],  // Crestmont Architects → Iris Khoury
            [7, 16],  // Sterling Realty → Beau Rivera
            [8, 17],  // Apex Trading → Vivian Holt
            [9, 18],  // TechFlow Distribution → Mason Greer
            [10, 19], // Cascade Wellness → Naomi Brock
        ];

        foreach ($existingClients as [$clientId, $contactId]) {
            $client = Client::find($clientId);
            $contact = Person::find($contactId);
            if ($client && $contact) {
                $this->clientContacts[] = ['client' => $client, 'contact' => $contact];
            }
        }

        // Create 6 new fake clients with new contacts
        $newClientDefs = [
            ['name' => 'Maple Street Dental', 'domain' => 'maplestreetdental', 'first' => 'Rachel', 'last' => 'Hoffman'],
            ['name' => 'Riverside Bookkeeping LLC', 'domain' => 'riversidebookkeeping', 'first' => 'Tom', 'last' => 'Feldstein'],
            ['name' => 'Northgate Veterinary Clinic', 'domain' => 'northgatevet', 'first' => 'Priya', 'last' => 'Nair'],
            ['name' => 'Summit Civil Engineering', 'domain' => 'summitcivil', 'first' => 'Derek', 'last' => 'Weston'],
            ['name' => 'Bluebell Property Management', 'domain' => 'bluebellpm', 'first' => 'Cassie', 'last' => 'Drummond'],
            ['name' => 'Ironwood Fabrication Inc', 'domain' => 'ironwoodfab', 'first' => 'Luis', 'last' => 'Ortega'],
        ];

        foreach ($newClientDefs as $def) {
            $client = Client::create([
                'name' => $def['name'],
                'stage' => ClientStage::Active->value,
                'is_active' => true,
            ]);

            $contact = Person::create([
                'client_id' => $client->id,
                'first_name' => $def['first'],
                'last_name' => $def['last'],
                'email' => strtolower($def['first'].'.'.$def['last'].'@'.$def['domain'].'.example.com'),
                'is_active' => true,
            ]);

            $this->clientContacts[] = ['client' => $client, 'contact' => $contact];
        }
    }

    /** Pick a random client+contact pair. */
    private function randomClientContact(): array
    {
        return $this->clientContacts[array_rand($this->clientContacts)];
    }

    /** Create a ticket with explicit fields. */
    private function createTicket(array $pair, array $attrs): Ticket
    {
        /** @var Client $client */
        $client = $pair['client'];
        /** @var Person $contact */
        $contact = $pair['contact'];

        $assigneeId = (random_int(0, 3) < 3)   // ~75% assigned
            ? $this->techIds[array_rand($this->techIds)]
            : null;

        return Ticket::create(array_merge([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'assignee_id' => $assigneeId,
            'source' => TicketSource::Email->value,
            'type' => TicketType::Incident->value,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P3->value,
            'opened_at' => now()->subDays(30),
        ], $this->castAttrs($attrs)));
    }

    /**
     * Cast enum objects to their string/int values for mass-assignment.
     */
    private function castAttrs(array $attrs): array
    {
        foreach ($attrs as $k => $v) {
            if ($v instanceof \BackedEnum) {
                $attrs[$k] = $v->value;
            }
        }

        return $attrs;
    }

    /**
     * Add a conversation thread to a ticket.
     *
     * Each entry in $convo:
     *   ['who' => 'agent'|'client', 'body' => '...', 'days_ago' => int]
     *
     * 'agent' → WhoType::Agent, NoteType::Reply, random tech author
     * 'client' → WhoType::EndUser, NoteType::Reply, author_name = contact full name
     */
    private function addConversation(Ticket $ticket, array $convo, int $openedDaysAgo): void
    {
        // Reload to get contact
        $ticket->load('contact');
        $contact = $ticket->contact;
        $contactName = $contact ? trim($contact->first_name.' '.$contact->last_name) : 'Client';

        // Initial description note (the opening message from the client)
        // The ticket description serves as the first message; add a formal Reply note too
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => $contactName,
            'body' => $ticket->description,
            'note_type' => NoteType::Reply->value,
            'who_type' => WhoType::EndUser->value,
            'ai_authored' => false,
            'is_private' => false,
            'is_billable' => false,
            'noted_at' => now()->subDays($openedDaysAgo)->addMinutes(2),
        ]);

        foreach ($convo as $entry) {
            $isAgent = $entry['who'] === 'agent';
            $techId = $isAgent ? $this->techIds[array_rand($this->techIds)] : null;
            $tech = $isAgent && $techId
                ? \App\Models\User::find($techId)
                : null;

            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => $isAgent ? $techId : null,
                'author_name' => $isAgent
                    ? ($tech?->name ?? 'Support Team')
                    : $contactName,
                'body' => $entry['body'],
                'note_type' => NoteType::Reply->value,
                'who_type' => $isAgent ? WhoType::Agent->value : WhoType::EndUser->value,
                'ai_authored' => false,
                'is_private' => false,
                'is_billable' => $isAgent,
                'noted_at' => now()->subDays($entry['days_ago']),
            ]);
        }
    }
}
