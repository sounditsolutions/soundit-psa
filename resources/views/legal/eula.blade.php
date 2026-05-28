@extends('layouts.app')

@section('title', 'End-User License Agreement')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h2 class="section-title">End-User License Agreement</h2>
        <p class="text-muted mb-4">Last updated: February 17, 2026</p>

        <div class="card card-static shadow-sm">
            <div class="card-body" style="line-height: 1.7;">
                {{-- TEMPLATE: this scaffolding is a starting point only.
                     Operators MUST review with legal counsel and customize
                     the company name, license terms, and dispute-resolution
                     clauses before relying on it. --}}

                <h5>1. Acceptance of Terms</h5>
                <p>By accessing and using this application ("the Application"), you agree to be bound by this End-User License Agreement ("Agreement"). If you do not agree to these terms, do not use the Application.</p>

                <h5>2. License Grant</h5>
                <p>{{ config('app.name') }} ("Company") grants authorized staff members a limited, non-exclusive, non-transferable license to access and use the Application for internal business purposes only.</p>

                <h5>3. Restrictions</h5>
                <p>You may not:</p>
                <ul>
                    <li>Share your login credentials with unauthorized individuals</li>
                    <li>Attempt to gain unauthorized access to the Application or its systems</li>
                    <li>Use the Application for any unlawful purpose</li>
                    <li>Copy, modify, distribute, or reverse-engineer the Application</li>
                </ul>

                <h5>4. Third-Party Integrations</h5>
                <p>The Application integrates with third-party services including QuickBooks Online, NinjaRMM, Level RMM, and Plivo. Your use of these integrations is subject to the respective third-party terms of service. The Company is not responsible for third-party service availability or data handling.</p>

                <h5>5. Data</h5>
                <p>The Application processes business data including client information, invoices, and service records. Users are responsible for ensuring the accuracy of data entered into the Application.</p>

                <h5>6. Disclaimer of Warranties</h5>
                <p>The Application is provided "as is" without warranty of any kind, express or implied, including but not limited to warranties of merchantability, fitness for a particular purpose, or non-infringement.</p>

                <h5>7. Limitation of Liability</h5>
                <p>In no event shall the Company be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the Application.</p>

                <h5>8. Termination</h5>
                <p>The Company may terminate your access to the Application at any time, with or without cause.</p>

                <h5>9. Contact</h5>
                <p>For questions about this Agreement, contact <a href="mailto:contact@your-msp.example">contact@your-msp.example</a>.</p>
            </div>
        </div>
    </div>
</div>
@endsection
