@extends('layouts.app')

@section('title', 'Privacy Policy')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h2 class="section-title">Privacy Policy</h2>
        <p class="text-muted mb-4">Last updated: February 17, 2026</p>

        {{-- TEMPLATE: this scaffolding is a starting point only. Operators
             MUST review with legal counsel and replace the company name,
             contact email, retention periods, and integration lists with
             values specific to their deployment before relying on it. --}}

        <div class="card card-static shadow-sm">
            <div class="card-body" style="line-height: 1.7;">
                <h5>1. Introduction</h5>
                <p>{{ config('app.name') }} ("Company", "we", "us") operates this application ("the Application"). This Privacy Policy describes how we collect, use, and protect information in connection with the Application.</p>

                <h5>2. Information We Collect</h5>
                <p>The Application collects and processes the following types of information:</p>
                <ul>
                    <li><strong>User account information:</strong> Name and email address, obtained via Microsoft Entra ID single sign-on</li>
                    <li><strong>Business data:</strong> Client records, contacts, assets, contracts, invoices, and service tickets managed within the Application</li>
                    <li><strong>Integration data:</strong> Data synchronized from connected third-party services (QuickBooks Online, NinjaRMM, Level RMM, Plivo)</li>
                    <li><strong>Usage data:</strong> Application logs for troubleshooting and security purposes</li>
                </ul>

                <h5>3. How We Use Information</h5>
                <p>Information is used exclusively for:</p>
                <ul>
                    <li>Providing and operating the Application's business management features</li>
                    <li>Synchronizing data with authorized third-party services</li>
                    <li>Generating invoices and processing billing through QuickBooks Online</li>
                    <li>Application security, troubleshooting, and improvement</li>
                </ul>

                <h5>4. Data Sharing</h5>
                <p>We share data only with the third-party services you have explicitly connected through the Application's integrations settings. We do not sell, rent, or share data with any other third parties.</p>

                <h5>5. QuickBooks Online Integration</h5>
                <p>When connected to QuickBooks Online, the Application:</p>
                <ul>
                    <li>Reads customer information for client matching</li>
                    <li>Creates and updates invoices</li>
                    <li>Reads invoice payment status</li>
                </ul>
                <p>OAuth2 access tokens are stored encrypted and can be revoked at any time by disconnecting from Settings or from within QuickBooks Online.</p>

                <h5>6. Data Security</h5>
                <p>We implement appropriate technical measures to protect data, including:</p>
                <ul>
                    <li>Encrypted storage of API credentials and OAuth tokens</li>
                    <li>HTTPS encryption for all data in transit</li>
                    <li>Authentication required for all application access via Microsoft Entra ID SSO</li>
                    <li>CSRF protection on all forms</li>
                </ul>

                <h5>7. Data Retention</h5>
                <p>Business data is retained for as long as needed for operational purposes. Users may request deletion of their account data by contacting the Company.</p>

                <h5>8. Access and Control</h5>
                <p>The Application is accessible only to authorized staff members. Users can disconnect third-party integrations at any time from the Application's settings page.</p>

                <h5>9. Changes to This Policy</h5>
                <p>We may update this Privacy Policy from time to time. The "Last updated" date at the top of this page indicates when the policy was last revised.</p>

                <h5>10. Contact</h5>
                <p>For questions about this Privacy Policy, contact the operator of this deployment.</p>
            </div>
        </div>
    </div>
</div>
@endsection
